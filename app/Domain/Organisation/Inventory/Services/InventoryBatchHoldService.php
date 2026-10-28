<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InventoryBatchHoldService
{
    public const PLACE = 'inventory.batch_holds.place.branch';

    public const RELEASE = 'inventory.batch_holds.release.branch';

    public const PLACE_REASONS = ['suspected_quality_issue', 'recall', 'damaged', 'other_safety'];

    public const RELEASE_REASONS = ['cleared_by_supplier', 'inspection_passed', 'other_approved'];

    /** @param array<string,mixed> $input */
    public function act(User $actor, string $batchKey, string $action, array $input): int
    {
        abort_unless(in_array($action, ['place', 'release'], true), 404);
        $data = Validator::make($input, ['expected_branch_id' => ['required', 'integer'], 'lock_version' => ['required', 'integer', 'min:0'], 'retry_key' => ['required', 'uuid'], 'reason' => ['required', Rule::in($action === 'place' ? self::PLACE_REASONS : self::RELEASE_REASONS)]])->validate();
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $data);
        ksort($data);
        $hash = hash_hmac('sha256', json_encode([$batchKey, $action, $data], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return DB::transaction(function () use ($actor, $authority, $branch, $batchKey, $action, $data, $hash): int {
            $actor = $authority->lockForBranch($actor, $branch, $action === 'place' ? self::PLACE : self::RELEASE);
            $retry = DB::table('inventory_batch_hold_actions')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry) {
                if (! hash_equals($retry->payload_hash, $hash)) {
                    $this->refuse('retry_key', 'This retry key was used for a different batch action.');
                }

                return $retry->id;
            }
            $candidate = InventoryBatch::query()->where('organisation_id', $actor->organisation_id)->where('public_id', $batchKey)->firstOrFail();
            InventorySku::query()->whereKey($candidate->inventory_sku_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $batch = InventoryBatch::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            abort_unless(DB::table('inventory_stock_balances as balance')->join('inventory_locations as location', 'location.id', '=', 'balance.inventory_location_id')->where('balance.organisation_id', $actor->organisation_id)->where('balance.inventory_batch_id', $batch->id)->where('location.branch_id', $branch->id)->exists(), 404);
            DB::table('inventory_batch_hold_states')->insertOrIgnore(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'inventory_batch_id' => $batch->id, 'lock_version' => 0]);
            $state = DB::table('inventory_batch_hold_states')->where('inventory_batch_id', $batch->id)->where('branch_id', $branch->id)->lockForUpdate()->sole();
            if ($state->lock_version !== (int) $data['lock_version']) {
                $this->refuse('lock_version', 'The batch hold changed. Reload before continuing.');
            }
            if (($action === 'place') === ($state->active_action_id !== null)) {
                $this->refuse('batch', $action === 'place' ? 'This batch is already held at this branch.' : 'This batch has no active hold at this branch.');
            }
            if ($action === 'release') {
                $placed = DB::table('inventory_batch_hold_actions')->where('id', $state->active_action_id)->sole();
                abort_if($placed->actor_user_id === $actor->id, 403, 'A different authorised person must release this hold.');
            }
            $id = DB::table('inventory_batch_hold_actions')->insertGetId(['organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id, 'inventory_batch_id' => $batch->id, 'action' => $action, 'reason' => $data['reason'], 'placed_action_id' => $action === 'release' ? $state->active_action_id : null, 'actor_user_id' => $actor->id, 'lock_version' => $state->lock_version + 1, 'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'created_at' => now()->utc()]);
            DB::table('inventory_batch_hold_states')->where('id', $state->id)->update(['active_action_id' => $action === 'place' ? $id : null, 'lock_version' => $state->lock_version + 1]);
            abort_if(app(AuditRecorder::class)->record('inventory.batch_hold.'.$action, $batch, ['action_id' => $id], $actor, $branch) === null, 503, 'The batch action could not be audited. Nothing was saved.');

            return $id;
        }, 3);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $access = app(BranchAccessService::class);
        $branch = $access->activeBranch($actor);
        abort_unless($branch && $access->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:10000']])->validate();
        $page = DB::table('inventory_batches as b')->join('inventory_skus as s', 's.id', '=', 'b.inventory_sku_id')
            ->leftJoin('inventory_batch_hold_states as h', fn ($q) => $q->on('h.inventory_batch_id', '=', 'b.id')->where('h.branch_id', $branch->id))
            ->leftJoin('inventory_batch_hold_actions as a', 'a.id', '=', 'h.active_action_id')
            ->where('b.organisation_id', $actor->organisation_id)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('inventory_stock_balances as balance')->join('inventory_locations as location', 'location.id', '=', 'balance.inventory_location_id')->whereColumn('balance.inventory_batch_id', 'b.id')->where('location.branch_id', $branch->id))
            ->when(filled($data['q'] ?? null), fn ($q) => $q->where(fn ($q) => $q->where('s.sku_code', 'ilike', '%'.addcslashes($data['q'], '%_\\').'%')->orWhere('b.batch_number', 'ilike', '%'.addcslashes($data['q'], '%_\\').'%')))
            ->orderBy('s.sku_code')->orderBy('b.expiry_date')->orderBy('b.id')->paginate(25, ['b.public_id', 'b.batch_number', 'b.expiry_date', 'b.status', 's.sku_code', 'h.lock_version', 'a.reason', 'a.actor_user_id'], 'page', (int) ($data['page'] ?? 1));

        return ['branchId' => $branch->id, 'branchName' => $branch->name, 'q' => $data['q'] ?? '', 'page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total(), 'rows' => collect($page->items())->map(fn ($r): array => [...(array) $r, 'lock_version' => $r->lock_version ?? 0, 'canPlace' => ! $r->reason && $actor->can(self::PLACE), 'canRelease' => $r->reason && $r->actor_user_id !== $actor->id && $actor->can(self::RELEASE)])->all()];
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
