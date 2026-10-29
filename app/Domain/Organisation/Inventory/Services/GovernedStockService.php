<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

final class GovernedStockService
{
    public const APPROVE = 'inventory.variances.approve.branch';

    public const REASONS = ['expired', 'damaged', 'contaminated', 'other_approved'];

    public const LEGACY_LIMIT = 'The legacy demo stocktake and manual adjustment path still permits same-person count and post and bypasses the independent approval; it must be disabled or hidden for the pilot in a separate slice.';

    /** @param array<string,mixed> $input */
    public function propose(User $actor, string $kind, array $input): int
    {
        abort_unless(in_array($kind, ['count', 'writeoff', 'reversal'], true), 404);
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'in:0'], 'retry_key' => ['required', 'uuid'],
            'balance_id' => ['required', 'integer', 'min:1'], 'balance_version' => ['required', 'integer', 'min:1'], 'unit' => ['required', 'string', 'max:100'],
            'quantity' => [$kind === 'writeoff' ? 'required' : 'prohibited'], 'reason' => [$kind === 'writeoff' ? 'required' : 'prohibited', Rule::in(self::REASONS)],
            'disposal_reference' => [$kind === 'writeoff' ? 'required' : 'prohibited', 'string', 'max:200', 'not_regex:/[\p{Cc}\p{Cf}<>]/u'],
            'reverses_case_id' => [$kind === 'reversal' ? 'required' : 'prohibited', 'integer'],
        ])->validate();
        if ($kind === 'writeoff') {
            $data['disposal_reference'] = trim($data['disposal_reference']);
            if ($data['disposal_reference'] === '') {
                $this->refuse('disposal_reference', 'Enter a disposal document or log reference. This does not prove disposal.');
            }
        }
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        $hash = $this->fingerprint([$kind, $data]);

        return DB::transaction(function () use ($actor, $authority, $branch, $kind, $data, $hash): int {
            $actor = $authority->lockForBranch($actor, $branch, $kind === 'count' ? InventoryControlService::STOCKTAKE_PERMISSION : InventoryControlService::ADJUST_PERMISSION);
            $retry = $this->retry($actor, $data['retry_key'], $hash);
            if ($retry !== null) {
                return $retry;
            }
            // Case locks always precede SKU/batch/balance locks, including a reversal's original.
            $original = null;
            if ($kind === 'reversal') {
                $original = DB::table('inventory_governed_cases')->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('id', $data['reverses_case_id'])->lockForUpdate()->first();
                abort_unless($original !== null && $original->kind !== 'reversal', 404);
            }
            [$sku, $batch, $location, $balance] = $this->lockSource($actor, $branch, (int) $data['balance_id']);
            if ($balance->lock_version !== (int) $data['balance_version']) {
                $this->refuse('balance_version', 'Stock changed. Reload before taking a new snapshot.');
            }
            StockUnitQuantity::base('1', $sku->stock_unit, $data['unit']);
            $quantity = $kind === 'writeoff' ? StockUnitQuantity::base($data['quantity'], $sku->stock_unit, $data['unit']) : null;
            if ($kind === 'reversal') {
                abort_unless($original->balance_id === $balance->id, 404);
                $posted = DB::table('inventory_governed_actions')->where('case_id', $original->id)->where('action', 'approved')->first();
                if ($posted === null || DB::table('inventory_governed_cases')->where('reverses_case_id', $original->id)->exists()) {
                    $this->refuse('reverses_case_id', 'Only one reversal proposal may reference an approved original.');
                }
                $quantity = $posted->quantity;
            }
            if ($kind === 'writeoff' && StockUnitQuantity::scaled($quantity) > StockUnitQuantity::scaled($balance->quantity, true)) {
                $this->refuse('quantity', 'Write-off cannot exceed the stock on hand.');
            }
            if ($kind === 'writeoff' && $data['reason'] === 'expired' && (string) $batch->getRawOriginal('expiry_date') > app(InventoryBatchEligibility::class)->localDate($branch)) {
                $this->refuse('reason', 'This batch has not expired at this branch.');
            }
            $id = DB::table('inventory_governed_cases')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id, 'inventory_location_id' => $location->id, 'balance_id' => $balance->id, 'kind' => $kind, 'base_unit' => $sku->stock_unit, 'reason' => $kind === 'count' ? 'count_variance' : ($kind === 'reversal' ? 'correction' : $data['reason']), 'expected_quantity' => $balance->quantity, 'expected_balance_version' => $balance->lock_version, 'quantity' => $quantity, 'disposal_reference' => $data['disposal_reference'] ?? null, 'proposer_user_id' => $actor->id, 'reverses_case_id' => $original?->id, 'created_at' => now()->utc()]);
            $this->append($actor, $branch, $id, 'created', $kind === 'count' ? 'awaiting_count' : 'awaiting_approval', 1, $data['retry_key'], $hash, null, null);

            return $id;
        }, 3);
    }

    /** @param array<string,mixed> $input */
    public function act(User $actor, int $caseId, string $action, array $input): int
    {
        abort_unless(in_array($action, ['counted', 'recounted', 'approved'], true), 404);
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:1'], 'retry_key' => ['required', 'uuid'], 'quantity' => [$action === 'approved' ? 'prohibited' : 'required'], 'unit' => [$action === 'approved' ? 'prohibited' : 'required', 'string', 'max:100'], 'batch_public_id' => ['prohibited'], 'sku_public_id' => ['prohibited'], 'location_public_id' => ['prohibited'], 'sku_id' => ['prohibited'], 'inventory_batch_id' => ['prohibited'], 'inventory_sku_id' => ['prohibited'], 'location_id' => ['prohibited'], 'batch_id' => ['prohibited'], 'balance_id' => ['prohibited'], 'lines' => ['prohibited']])->validate();
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        $hash = $this->fingerprint([$caseId, $action, $data]);

        return DB::transaction(function () use ($actor, $caseId, $action, $data, $authority, $branch, $hash): int {
            $actor = $authority->lockForBranch($actor, $branch, $action === 'approved' ? self::APPROVE : InventoryControlService::STOCKTAKE_PERMISSION);
            $retry = $this->retry($actor, $data['retry_key'], $hash);
            if ($retry !== null) {
                return $retry;
            }
            $case = DB::table('inventory_governed_cases')->where('id', $caseId)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            abort_unless($case !== null, 404);
            $previous = DB::table('inventory_governed_actions')->where('case_id', $caseId)->orderByDesc('lock_version')->first();
            if ($previous === null || $previous->lock_version !== (int) $data['lock_version']) {
                $this->refuse('lock_version', 'This case changed. Reload before continuing.');
            }
            [$sku, $batch, $location, $balance] = $this->lockSource($actor, $branch, $case->balance_id);
            if ($sku->stock_unit !== $case->base_unit || $balance->lock_version !== $case->expected_balance_version || $balance->quantity !== $case->expected_quantity) {
                $this->refuse('snapshot', 'Stock changed after the snapshot. Take a new snapshot; no adjustment was posted.');
            }
            if ($action !== 'approved') {
                $required = $action === 'counted' ? 'awaiting_count' : 'awaiting_recount';
                if ($case->kind !== 'count' || $previous->state !== $required) {
                    $this->refuse('state', 'This case is not awaiting that count action.');
                }
                abort_if($action === 'recounted' && $previous->actor_user_id === $actor->id, 403, 'A different person must recount this stock.');
                StockUnitQuantity::base('1', $sku->stock_unit, $data['unit']);
                $quantity = StockUnitQuantity::format(StockUnitQuantity::scaled($data['quantity'], true));
                $state = $quantity === $case->expected_quantity ? 'closed_zero' : ($action === 'counted' ? 'awaiting_recount' : 'awaiting_approval');
                $this->append($actor, $branch, $caseId, $action, $state, $previous->lock_version + 1, $data['retry_key'], $hash, $quantity, null);

                return $caseId;
            }
            if ($previous->state !== 'awaiting_approval') {
                $this->refuse('state', 'Only an independently recounted variance or a pending write-off can be approved.');
            }
            $involved = DB::table('inventory_governed_actions')->whereIn('case_id', array_filter([$caseId, $case->reverses_case_id]))->whereIn('action', ['counted', 'recounted'])->pluck('actor_user_id')->all();
            $proposers = DB::table('inventory_governed_cases')->whereIn('id', array_filter([$caseId, $case->reverses_case_id]))->pluck('proposer_user_id')->all();
            abort_if(in_array($actor->id, [...$involved, ...$proposers], true), 403, 'The approver must differ from the counter, recounter and proposer.');
            if ($case->kind === 'count') {
                $delta = StockUnitQuantity::scaled($previous->quantity, true) - StockUnitQuantity::scaled($case->expected_quantity, true);
                $quantity = StockUnitQuantity::format(abs($delta));
                $type = $delta > 0 ? StockMovement::TYPE_STOCKTAKE_GAIN : StockMovement::TYPE_STOCKTAKE_LOSS;
            } elseif ($case->kind === 'writeoff') {
                $quantity = $case->quantity;
                $type = $case->reason === 'expired' ? StockMovement::TYPE_EXPIRY_WRITEOFF : StockMovement::TYPE_STOCK_WRITEOFF;
            } else {
                $quantity = $case->quantity;
                $original = DB::table('inventory_governed_actions as a')->join('stock_movements as m', 'm.id', '=', 'a.movement_id')->where('a.case_id', $case->reverses_case_id)->where('a.action', 'approved')->sole(['m.source_location_id']);
                $type = $original->source_location_id !== null ? StockMovement::TYPE_ADJUSTMENT_IN : StockMovement::TYPE_ADJUSTMENT_OUT;
            }
            $movement = app(InventoryMovementService::class)->recordGovernedChange($actor, $location, $sku, $batch, $quantity, $type, $case->public_id);
            $this->append($actor, $branch, $caseId, 'approved', 'posted', $previous->lock_version + 1, $data['retry_key'], $hash, $quantity, $movement->id);

            return $caseId;
        }, 3);
    }

    /** @return array{InventorySku,InventoryBatch,InventoryLocation,stdClass} */
    private function lockSource(User $actor, Branch $branch, int $balanceId): array
    {
        $candidate = DB::table('inventory_stock_balances as b')->join('inventory_locations as l', 'l.id', '=', 'b.inventory_location_id')->where('b.id', $balanceId)->where('b.organisation_id', $actor->organisation_id)->where('l.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->first(['b.*']);
        abort_unless($candidate !== null, 404);
        $sku = InventorySku::query()->whereKey($candidate->inventory_sku_id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        $batch = InventoryBatch::query()->whereKey($candidate->inventory_batch_id)->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
        $balance = DB::table('inventory_stock_balances')->where('id', $balanceId)->lockForUpdate()->sole();
        $location = InventoryLocation::query()->whereKey($candidate->inventory_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('is_active', true)->firstOrFail();

        return [$sku, $batch, $location, $balance];
    }

    /** @param array<mixed> $payload */
    private function fingerprint(array $payload): string
    {
        foreach ($payload as &$part) {
            if (is_array($part)) {
                ksort($part);
            }
        } unset($part);

        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function retry(User $actor, string $key, string $hash): ?int
    {
        $retry = DB::table('inventory_governed_actions')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $key)->first();
        if ($retry === null) {
            return null;
        }
        if (! hash_equals($retry->payload_hash, $hash)) {
            $this->refuse('retry_key', 'This retry key was used for different stock evidence.');
        }

        return $retry->case_id;
    }

    private function append(User $actor, Branch $branch, int $caseId, string $action, string $state, int $version, string $key, string $hash, ?string $quantity, ?int $movementId): void
    {
        $id = DB::table('inventory_governed_actions')->insertGetId(['organisation_id' => $actor->organisation_id, 'case_id' => $caseId, 'actor_user_id' => $actor->id, 'action' => $action, 'state' => $state, 'quantity' => $quantity, 'movement_id' => $movementId, 'lock_version' => $version, 'retry_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc()]);
        abort_if(app(AuditRecorder::class)->record('inventory.governed_stock.'.$action, null, ['case_id' => $caseId, 'action_id' => $id, 'lock_version' => $version, 'state' => $state], $actor, $branch) === null, 503, 'The stock action could not be audited. Nothing was saved or posted.');
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function page(User $actor, string $mode, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branch = app(BranchAccessService::class)->activeBranch($actor);
        abort_unless($branch && app(BranchAccessService::class)->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'targets_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'state' => ['nullable', Rule::in(['awaiting_count', 'awaiting_recount', 'awaiting_approval', 'closed_zero', 'posted'])]])->validate();
        $latest = DB::table('inventory_governed_actions')->select('case_id')->selectRaw('MAX(lock_version) as version')->groupBy('case_id');
        $query = DB::table('inventory_governed_cases as c')->joinSub($latest, 'latest', 'latest.case_id', '=', 'c.id')->join('inventory_governed_actions as a', fn ($j) => $j->on('a.case_id', '=', 'c.id')->on('a.lock_version', '=', 'latest.version'))
            ->join('inventory_stock_balances as current', 'current.id', '=', 'c.balance_id')->join('inventory_skus as s', 's.id', '=', 'c.inventory_sku_id')->join('inventory_batches as b', 'b.id', '=', 'c.inventory_batch_id')->join('inventory_locations as l', 'l.id', '=', 'c.inventory_location_id')
            ->where('c.organisation_id', $actor->organisation_id)->where('c.branch_id', $branch->id)->where(fn ($q) => $mode === 'count' ? $q->where('c.kind', 'count') : $q->whereIn('c.kind', ['writeoff', 'reversal']))
            ->when(filled($data['state'] ?? null), fn ($q) => $q->where('a.state', $data['state']));
        $this->search($query, $data['q'] ?? '');
        $p = $query->orderByDesc('c.id')->paginate(25, ['c.*', 'current.lock_version as current_balance_version', 'a.state', 'a.lock_version', 'a.quantity as last_quantity', 's.sku_code', 'c.base_unit as stock_unit', 'b.batch_number', 'l.name as location'], 'page', (int) ($data['page'] ?? 1));
        $histories = DB::table('inventory_governed_actions')->whereIn('case_id', $p->pluck('id'))->orderBy('lock_version')->get(['id', 'case_id', 'action', 'state', 'actor_user_id', 'quantity', 'lock_version', 'movement_id', 'created_at'])->groupBy('case_id');
        $originalIds = $p->pluck('reverses_case_id')->filter()->all();
        $involved = DB::table('inventory_governed_actions')->whereIn('case_id', $originalIds)->whereIn('action', ['created', 'counted', 'recounted'])->get(['case_id', 'actor_user_id'])->groupBy('case_id');
        $reversed = DB::table('inventory_governed_cases')->whereIn('reverses_case_id', $p->pluck('id'))->pluck('reverses_case_id')->all();
        $rows = [];
        foreach ($p->items() as $row) {
            $history = $histories->get($row->id, collect());
            $excluded = [$row->proposer_user_id, ...$history->whereIn('action', ['counted', 'recounted'])->pluck('actor_user_id')->all(), ...$involved->get($row->reverses_case_id, collect())->pluck('actor_user_id')->all()];
            $rows[] = [...(array) $row, 'history' => $history->all(), 'canCount' => $actor->can(InventoryControlService::STOCKTAKE_PERMISSION) && in_array($row->state, ['awaiting_count', 'awaiting_recount'], true) && ($row->state !== 'awaiting_recount' || $history->firstWhere('action', 'counted')?->actor_user_id !== $actor->id), 'canApprove' => $actor->can(self::APPROVE) && $row->state === 'awaiting_approval' && ! in_array($actor->id, $excluded, true), 'canReverse' => $actor->can(InventoryControlService::ADJUST_PERMISSION) && $row->state === 'posted' && $row->kind !== 'reversal' && ! in_array($row->id, $reversed, true)];
        }
        $targets = DB::table('inventory_stock_balances as r')->join('inventory_locations as l', 'l.id', '=', 'r.inventory_location_id')->join('inventory_skus as s', 's.id', '=', 'r.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')->join('inventory_batches as b', 'b.id', '=', 'r.inventory_batch_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('s.is_active', true)->where('i.is_active', true);
        $this->search($targets, $data['q'] ?? '');
        $t = $targets->orderBy('s.sku_code')->orderBy('b.expiry_date')->orderBy('r.id')->paginate(25, ['r.id', 'r.quantity', 'r.lock_version', 's.sku_code', 's.stock_unit', 'b.batch_number', 'b.expiry_date', 'l.name as location'], 'targets_page', (int) ($data['targets_page'] ?? 1));

        return ['branchId' => $branch->id, 'branchName' => $branch->name, 'mode' => $mode, 'q' => $data['q'] ?? '', 'state' => $data['state'] ?? '', 'legacyLimit' => self::LEGACY_LIMIT, 'canPropose' => $actor->can($mode === 'count' ? InventoryControlService::STOCKTAKE_PERMISSION : InventoryControlService::ADJUST_PERMISSION), 'cases' => ['rows' => $rows, 'total' => $p->total(), 'page' => $p->currentPage(), 'lastPage' => $p->lastPage()], 'targets' => ['rows' => $t->items(), 'total' => $t->total(), 'page' => $t->currentPage(), 'lastPage' => $t->lastPage()]];
    }

    private function search(Builder $query, string $text): void
    {
        if ($text === '') {
            return;
        }
        $needle = '%'.addcslashes($text, '%_\\').'%';
        $query->where(fn ($q) => $q->where('s.sku_code', 'ilike', $needle)->orWhere('b.batch_number', 'ilike', $needle)->orWhere('l.name', 'ilike', $needle));
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
