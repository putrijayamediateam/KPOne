<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * STK-8 treatment-room usage and waste (owner decision 2026-11-05): CA and CA Supervisor record stock used or
 * wasted outside dispensing; every entry debits the ledger and is audited. A CA Supervisor, Finance Officer or
 * Director who did not record an entry may reverse it once, which credits the quantity back. Not linked to a
 * patient, no bill and no kits.
 */
final class TreatmentUsageService
{
    public const RECORD = 'inventory.usage.record.branch';

    /** Existing variance approval permission: CA Supervisor, Finance Officer, Director. */
    public const REVERSE = 'inventory.variances.approve.branch';

    /** @var array<string, list<string>> */
    public const REASONS = [
        'usage' => ['procedure', 'wound_care', 'nebulisation', 'injection', 'other_clinical_use'],
        'waste' => ['opened_not_used', 'spilled_or_broken', 'contaminated', 'other_waste'],
    ];

    public const REVERSAL_REASONS = ['recorded_in_error', 'wrong_item_or_batch', 'wrong_quantity', 'wrong_location'];

    public function __construct(private InventoryAuthorityService $authority, private AuditRecorder $audit, private InventoryBatchEligibility $eligibility) {}

    /** @param array<string, mixed> $input */
    public function record(User $actor, array $input): int
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'retry_key' => ['required', 'uuid'],
            'location_id' => ['required', 'integer', 'min:1'], 'batch_id' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'string', 'max:100'], 'quantity' => ['required'],
            'kind' => ['required', 'string', Rule::in(array_keys(self::REASONS))],
            'reason' => ['required', 'string', Rule::in(self::REASONS[is_string($input['kind'] ?? null) ? $input['kind'] : ''] ?? [])],
            'room_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['record', $data]);

        return DB::transaction(function () use ($actor, $branch, $data, $hash): int {
            $actor = $this->authority->lockForBranch($actor, $branch, self::RECORD);
            // Checked under the actor lock, so a concurrent replay of the same key waits and then replays.
            $retry = DB::table('inventory_treatment_usages')->where('organisation_id', $actor->organisation_id)->where('recorder_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry !== null) {
                if (! hash_equals((string) $retry->payload_hash, $hash)) {
                    $this->refuse('retry_key', 'This retry key was used for a different usage entry.');
                }

                return (int) $retry->id;
            }
            $location = InventoryLocation::query()->whereKey((int) $data['location_id'])->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->first();
            abort_unless($location !== null, 404);
            if (! $location->is_active) {
                $this->refuse('location_id', 'This stock location is inactive.');
            }
            if ($location->type === InventoryLocation::TYPE_DISPENSARY_RETURNS) {
                $this->refuse('location_id', 'Stock in a patient returns location cannot be used in treatment. Write it off or transfer it.');
            }
            $skuId = DB::table('inventory_batches')->where('id', (int) $data['batch_id'])->where('organisation_id', $actor->organisation_id)->value('inventory_sku_id');
            abort_unless($skuId !== null, 404);
            // SKU before batch, the shared inventory lock order.
            $sku = InventorySku::query()->whereKey((int) $skuId)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $batch = InventoryBatch::query()->whereKey((int) $data['batch_id'])->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
            if (! $sku->is_active) {
                $this->refuse('batch_id', 'This SKU is inactive, so stock cannot be recorded against it. Reactivate it in Stock setup first.');
            }
            $quantity = StockUnitQuantity::format(StockUnitQuantity::scaled(StockUnitQuantity::base($data['quantity'], $sku->stock_unit, (string) $data['unit'])));
            // Owner decision 2026-11-05: usage and waste are for usable stock only. Expired, held or unavailable
            // stock leaves through the governed Write-off, which needs a second person.
            try {
                $this->eligibility->assertEligible($actor->organisation_id, $branch, $batch->id, $sku->sku_code);
            } catch (ValidationException $e) {
                $this->refuse('batch_id', 'This batch cannot be recorded here: '.$e->validator->errors()->first().' Use the governed Write-off for expired, held or unavailable stock.');
            }
            $roomId = null;
            if (isset($data['room_id'])) {
                $room = BranchRoom::query()->whereKey((int) $data['room_id'])->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('kind', BranchRoom::KIND_TREATMENT)->where('is_active', true)->first();
                if ($room === null) {
                    $this->refuse('room_id', 'Choose an active treatment room of this branch.');
                }
                $roomId = $room->id;
            }
            $publicId = (string) Str::uuid();
            $type = $data['kind'] === 'usage' ? StockMovement::TYPE_TREATMENT_USAGE : StockMovement::TYPE_TREATMENT_WASTE;
            $movement = app(InventoryMovementService::class)->recordTreatmentUsage($actor, $location, $sku, $batch, $quantity, $type, $publicId);
            $id = DB::table('inventory_treatment_usages')->insertGetId([
                'public_id' => $publicId, 'organisation_id' => $actor->organisation_id, 'branch_id' => $branch->id,
                'usage_number' => 'TRU-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(10)), 'kind' => $data['kind'], 'reason' => $data['reason'],
                'branch_room_id' => $roomId, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $sku->id, 'inventory_batch_id' => $batch->id,
                'base_unit' => $sku->stock_unit, 'quantity' => $quantity, 'movement_id' => $movement->id, 'recorder_user_id' => $actor->id,
                'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'created_at' => now()->utc(),
            ]);
            $this->audited('inventory.treatment_usage.recorded', ['usage_id' => $id, 'kind' => $data['kind'], 'movement_id' => $movement->id], $actor, $branch);

            return $id;
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function reverse(User $actor, int $usageId, array $input): int
    {
        $data = Validator::make($input, [
            'expected_branch_id' => ['required', 'integer'], 'retry_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', Rule::in(self::REVERSAL_REASONS)],
        ])->validate();
        $branch = $this->authority->activeBranch($actor, $data);
        $hash = $this->fingerprint(['reverse', $usageId, $data]);

        return DB::transaction(function () use ($actor, $branch, $usageId, $data, $hash): int {
            $actor = $this->authority->lockForBranch($actor, $branch, self::REVERSE);
            $usage = DB::table('inventory_treatment_usages')->where('id', $usageId)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->lockForUpdate()->first();
            abort_unless($usage !== null, 404);
            $retry = DB::table('inventory_treatment_usage_reversals')->where('organisation_id', $actor->organisation_id)->where('actor_user_id', $actor->id)->where('retry_key', $data['retry_key'])->first();
            if ($retry !== null) {
                if (! hash_equals((string) $retry->payload_hash, $hash)) {
                    $this->refuse('retry_key', 'This retry key was used for a different reversal.');
                }

                return (int) $retry->usage_id;
            }
            if (DB::table('inventory_treatment_usage_reversals')->where('usage_id', $usage->id)->exists()) {
                $this->refuse('state', 'This entry has already been reversed.');
            }
            if ((int) $usage->recorder_user_id === $actor->id) {
                $this->refuse('state', 'You recorded this entry, so a different CA Supervisor, Finance Officer or Director must reverse it.');
            }
            $sku = InventorySku::query()->whereKey($usage->inventory_sku_id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
            $batch = InventoryBatch::query()->whereKey($usage->inventory_batch_id)->where('organisation_id', $actor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
            $location = InventoryLocation::query()->whereKey($usage->inventory_location_id)->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->firstOrFail();
            if (! $sku->is_active || ! $location->is_active) {
                $this->refuse('state', 'This SKU or stock location is inactive, so stock cannot be put back. Reactivate it in Stock setup first.');
            }
            if (DB::table('stock_movements')->where('organisation_id', $actor->organisation_id)->where('reference_type', 'inventory_governed_case')->where('id', '>', $usage->movement_id)
                ->where('inventory_sku_id', $sku->id)->where('inventory_batch_id', $batch->id)
                ->where(fn ($q) => $q->where('source_location_id', $usage->inventory_location_id)->orWhere('destination_location_id', $usage->inventory_location_id))->exists()) {
                $this->refuse('state', 'A governed stock count or write-off has been posted for this batch here since the entry, so reversing it could count the stock twice. Use a governed stock count instead.');
            }
            if ($sku->stock_unit !== $usage->base_unit) {
                $this->refuse('state', 'The stock unit of this SKU changed, so this entry cannot be reversed automatically. Use a governed stock count.');
            }
            $movement = app(InventoryMovementService::class)->recordTreatmentUsage($actor, $location, $sku, $batch, StockUnitQuantity::format(StockUnitQuantity::scaled((string) $usage->quantity)), StockMovement::TYPE_ADJUSTMENT_IN, (string) $usage->public_id);
            $id = DB::table('inventory_treatment_usage_reversals')->insertGetId([
                'organisation_id' => $actor->organisation_id, 'usage_id' => $usage->id, 'actor_user_id' => $actor->id, 'reason' => $data['reason'],
                'movement_id' => $movement->id, 'retry_key' => $data['retry_key'], 'payload_hash' => $hash, 'created_at' => now()->utc(),
            ]);
            $this->audited('inventory.treatment_usage.reversed', ['usage_id' => (int) $usage->id, 'reversal_id' => $id, 'movement_id' => $movement->id], $actor, $branch);

            return (int) $usage->id;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function page(User $actor, array $input): array
    {
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branches = app(BranchAccessService::class);
        $branch = $branches->activeBranch($actor);
        abort_unless($branch && $branches->hasEffectiveAssignment($actor, $branch), 403);
        $data = Validator::make($input, [
            'q' => ['nullable', 'string', 'max:100'], 'stock_q' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'stock_page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $canRecord = $actor->can(self::RECORD);
        $canReverse = $actor->can(self::REVERSE);
        $like = fn (?string $value): ?string => filled($value) ? '%'.addcslashes((string) $value, '%_\\').'%' : null;
        $needle = $like($data['q'] ?? null);

        $entries = DB::table('inventory_treatment_usages as u')
            ->join('inventory_skus as s', 's.id', '=', 'u.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
            ->join('inventory_batches as b', 'b.id', '=', 'u.inventory_batch_id')->join('inventory_locations as l', 'l.id', '=', 'u.inventory_location_id')
            ->leftJoin('branch_rooms as room', 'room.id', '=', 'u.branch_room_id')
            ->leftJoin('inventory_treatment_usage_reversals as r', 'r.usage_id', '=', 'u.id')
            ->where('u.organisation_id', $actor->organisation_id)->where('u.branch_id', $branch->id)
            ->when($needle, fn ($q) => $q->where(fn ($w) => $w->where('s.sku_code', 'ilike', $needle)->orWhere('i.generic_name', 'ilike', $needle)->orWhere('b.batch_number', 'ilike', $needle)->orWhere('u.usage_number', 'ilike', $needle)))
            ->orderByDesc('u.id')
            ->paginate(25, ['u.id', 'u.usage_number', 'u.kind', 'u.reason', 'u.quantity', 'u.base_unit', 'u.recorder_user_id', 'u.created_at', 's.sku_code', 'i.generic_name as item_name',
                'b.batch_number', 'b.expiry_date', 'l.name as location', 'room.name as room', 'r.id as reversal_id', 'r.reason as reversal_reason', 'r.actor_user_id as reversed_by', 'r.created_at as reversed_at'], 'page', (int) ($data['page'] ?? 1));
        $local = fn (?string $at): ?string => $at === null ? null : CarbonImmutable::parse($at)->setTimezone($branch->timezone)->format('j M Y, g:i A');
        // TIDY-4: who recorded and who reversed, in words (BIL-2 staff-visibility rule), not ids.
        $name = app(InventoryStaffNames::class)->resolver($actor, [...collect($entries->items())->pluck('recorder_user_id'), ...collect($entries->items())->pluck('reversed_by')]);
        $rows = array_map(function (stdClass $row) use ($actor, $canReverse, $local, $name): array {
            $mine = (int) $row->recorder_user_id === $actor->id;

            return [...(array) $row, 'recordedAt' => $local($row->created_at), 'reversedAt' => $local($row->reversed_at), 'recordedByYou' => $mine, 'recorded_by_name' => $name($row->recorder_user_id), 'reversed_by_name' => $name($row->reversed_by),
                'canReverse' => $canReverse && $row->reversal_id === null && ! $mine];
        }, $entries->items());

        $stock = ['rows' => [], 'total' => 0, 'page' => 1, 'lastPage' => 1];
        if ($canRecord) {
            $stockNeedle = $like($data['stock_q'] ?? null);
            $paged = DB::table('inventory_stock_balances as bal')
                ->join('inventory_locations as l', 'l.id', '=', 'bal.inventory_location_id')
                ->join('inventory_skus as s', 's.id', '=', 'bal.inventory_sku_id')->join('inventory_items as i', 'i.id', '=', 's.inventory_item_id')
                ->joinSub($this->eligibility->query($actor->organisation_id, $branch), 'e', 'e.id', '=', 'bal.inventory_batch_id')
                ->where('bal.organisation_id', $actor->organisation_id)->where('l.branch_id', $branch->id)->where('l.is_active', true)->where('l.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)->where('s.is_active', true)->where('bal.quantity', '>', 0)
                ->when($stockNeedle, fn ($q) => $q->where(fn ($w) => $w->where('s.sku_code', 'ilike', $stockNeedle)->orWhere('i.generic_name', 'ilike', $stockNeedle)->orWhere('e.batch_number', 'ilike', $stockNeedle)))
                ->orderBy('i.generic_name')->orderBy('e.expiry_date')->orderBy('bal.id')
                ->paginate(25, ['bal.id', 'l.id as location_id', 'l.name as location', 's.sku_code', 's.stock_unit', 'i.generic_name as item_name', 'e.id as batch_id', 'e.batch_number', 'e.expiry_date', 'e.eligibility_reason', 'bal.quantity as on_hand'], 'stock_page', (int) ($data['stock_page'] ?? 1));
            $stock = ['rows' => array_map(fn (stdClass $row): array => [...(array) $row, 'on_hand' => StockUnitQuantity::format(StockUnitQuantity::scaled((string) $row->on_hand, true)), 'usable' => $row->eligibility_reason === null], $paged->items()),
                'total' => $paged->total(), 'page' => $paged->currentPage(), 'lastPage' => $paged->lastPage()];
        }

        return ['branchId' => $branch->id, 'branchName' => $branch->name, 'q' => $data['q'] ?? '', 'stockQ' => $data['stock_q'] ?? '',
            'canRecord' => $canRecord, 'canReverse' => $canReverse, 'reasons' => self::REASONS, 'reversalReasons' => self::REVERSAL_REASONS,
            'rooms' => $canRecord ? BranchRoom::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('kind', BranchRoom::KIND_TREATMENT)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->toArray() : [],
            'entries' => ['rows' => $rows, 'total' => $entries->total(), 'page' => $entries->currentPage(), 'lastPage' => $entries->lastPage()], 'stock' => $stock];
    }

    /** @param array<mixed> $payload */
    private function fingerprint(array $payload): string
    {
        foreach ($payload as &$part) {
            if (is_array($part)) {
                ksort($part);
            }
        }
        unset($part);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, int|string> $metadata */
    private function audited(string $event, array $metadata, User $actor, Branch $branch): void
    {
        abort_if($this->audit->record($event, null, $metadata, $actor, $branch) === null, 503, 'The usage entry could not be audited. Nothing was saved or posted.');
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
