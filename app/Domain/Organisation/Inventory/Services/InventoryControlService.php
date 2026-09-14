<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryAdjustment;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventoryReorderLevel;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\InventoryStocktake;
use App\Domain\Organisation\Inventory\Models\InventoryStocktakeLine;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InventoryControlService
{
    public const STOCKTAKE_PERMISSION = 'inventory.stocktake.branch';

    public const ADJUST_PERMISSION = 'inventory.adjust.branch';

    public const REORDER_PERMISSION = 'inventory.reorder.manage.branch';

    public const WAREHOUSE_PERMISSION = 'inventory.warehouse.organisation';

    private const REASONS = ['correction', 'damage', 'found_stock', 'count_variance', 'other'];

    public function __construct(
        private InventoryAuthorityService $authority,
        private InventoryMovementService $movements,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createStocktake(User $actor, array $attributes): InventoryStocktake
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $attributes, $branch): InventoryStocktake {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::STOCKTAKE_PERMISSION);
            $location = $this->lockLocation($lockedActor, $branch, (string) ($attributes['location_public_id'] ?? ''));
            $balances = InventoryStockBalance::query()->where('organisation_id', $lockedActor->organisation_id)->where('inventory_location_id', $location->id)->orderBy('id')->lockForUpdate()->get();
            if ($balances->isEmpty()) {
                throw ValidationException::withMessages(['location_public_id' => 'The location has no materialized stock balances to count.']);
            }
            $stocktake = new InventoryStocktake;
            $stocktake->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, 'inventory_location_id' => $location->id, 'stocktake_number' => 'ST-'.now()->utc()->format('Ym').'-'.Str::upper(Str::random(8)), 'status' => InventoryStocktake::STATUS_DRAFT, 'lock_version' => 1, 'created_by_user_id' => $lockedActor->id])->save();
            foreach ($balances as $balance) {
                $line = new InventoryStocktakeLine;
                $line->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $lockedActor->organisation_id, 'stocktake_id' => $stocktake->id, 'inventory_sku_id' => $balance->inventory_sku_id, 'inventory_batch_id' => $balance->inventory_batch_id, 'expected_quantity' => $balance->quantity, 'expected_balance_lock_version' => $balance->lock_version])->save();
            }
            $this->audit->record('inventory.stocktake.created', $stocktake, ['line_count' => $balances->count()], $lockedActor, $branch);

            return $stocktake->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function startCounting(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        return $this->stocktakeTransition($actor, $stocktake, $attributes, InventoryStocktake::STATUS_DRAFT, InventoryStocktake::STATUS_COUNTING, []);
    }

    /** @param array<string, mixed> $attributes */
    public function recordCounts(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $counts = validator(['lines' => $attributes['lines'] ?? null], ['lines' => ['required', 'array', 'min:1'], 'lines.*.line_public_id' => ['required', 'uuid', 'distinct'], 'lines.*.physical_quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/']])->validate()['lines'];

        return DB::transaction(function () use ($actor, $stocktake, $attributes, $branch, $counts): InventoryStocktake {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::STOCKTAKE_PERMISSION);
            $locked = $this->lockStocktake($lockedActor, $stocktake, $attributes);
            $this->assertStocktakeLocation($lockedActor, $branch, $locked);
            $this->requireStocktakeStatus($locked, InventoryStocktake::STATUS_COUNTING);
            $lines = InventoryStocktakeLine::query()->where('stocktake_id', $locked->id)->where('organisation_id', $lockedActor->organisation_id)->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
            if ($lines->count() !== count($counts)) {
                throw ValidationException::withMessages(['lines' => 'Every stocktake line requires a physical count.']);
            }
            foreach ($counts as $input) {
                /** @var InventoryStocktakeLine|null $line */
                $line = $lines->get($input['line_public_id']);
                if (! $line) {
                    abort(404);
                }
                $physical = number_format((float) $input['physical_quantity'], 3, '.', '');
                $line->forceFill(['physical_quantity' => $physical, 'variance_quantity' => number_format((float) $physical - (float) $line->expected_quantity, 3, '.', '')])->save();
            }
            $locked->forceFill(['status' => InventoryStocktake::STATUS_REVIEW, 'counted_by_user_id' => $lockedActor->id, 'counted_at' => now()->utc(), 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.stocktake.counted', $locked, ['line_count' => count($counts)], $lockedActor, $branch);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function postStocktake(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $stocktake, $attributes, $branch): InventoryStocktake {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::STOCKTAKE_PERMISSION);
            $locked = $this->lockStocktake($lockedActor, $stocktake, $attributes);
            $location = $this->assertStocktakeLocation($lockedActor, $branch, $locked);
            $this->requireStocktakeStatus($locked, InventoryStocktake::STATUS_REVIEW);
            $lines = InventoryStocktakeLine::query()->where('stocktake_id', $locked->id)->where('organisation_id', $lockedActor->organisation_id)->orderBy('id')->lockForUpdate()->get();
            $skus = InventorySku::query()->where('organisation_id', $lockedActor->organisation_id)->whereIn('id', $lines->pluck('inventory_sku_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $batches = InventoryBatch::query()->where('organisation_id', $lockedActor->organisation_id)->whereIn('id', $lines->pluck('inventory_batch_id')->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $balances = InventoryStockBalance::query()->where('organisation_id', $lockedActor->organisation_id)->where('inventory_location_id', $location->id)
                ->orderBy('id')->lockForUpdate()->get();
            $snapshotKeys = $lines->map(fn (InventoryStocktakeLine $line): string => $this->balanceKey($line->inventory_sku_id, $line->inventory_batch_id))->sort()->values();
            $currentKeys = $balances->map(fn (InventoryStockBalance $balance): string => $this->balanceKey($balance->inventory_sku_id, $balance->inventory_batch_id))->sort()->values();
            if ($snapshotKeys->all() !== $currentKeys->all()) {
                throw ValidationException::withMessages(['stocktake' => 'Stock balance keys changed after the snapshot. Return this stocktake for review.']);
            }
            $balances = $balances->keyBy(fn (InventoryStockBalance $balance): string => $this->balanceKey($balance->inventory_sku_id, $balance->inventory_batch_id));
            foreach ($lines as $line) {
                $balance = $balances->get($this->balanceKey($line->inventory_sku_id, $line->inventory_batch_id));
                if (! $balance || $balance->lock_version !== $line->expected_balance_lock_version || abs((float) $balance->quantity - (float) $line->expected_quantity) > 0.0001) {
                    throw ValidationException::withMessages(['stocktake' => 'Stock changed after the snapshot. Return this stocktake for review.']);
                }
                if ($line->physical_quantity === null || $line->variance_quantity === null) {
                    throw ValidationException::withMessages(['stocktake' => 'Every stocktake line must be counted before posting.']);
                }
            }
            foreach ($lines as $line) {
                $sku = $skus->get($line->inventory_sku_id);
                $batch = $batches->get($line->inventory_batch_id);
                abort_unless($sku && $batch && $batch->inventory_sku_id === $sku->id, 404);
                $this->movements->recordStocktakeVariance($lockedActor, $location, $sku, $batch, $line->variance_quantity, $locked->public_id);
            }
            $locked->forceFill(['status' => InventoryStocktake::STATUS_POSTED, 'posted_by_user_id' => $lockedActor->id, 'posted_at' => now()->utc(), 'lock_version' => $locked->lock_version + 1])->save();
            $this->audit->record('inventory.stocktake.posted', $locked, ['line_count' => $lines->count()], $lockedActor, $branch);

            return $locked->refresh()->load('lines');
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function cancelStocktake(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        return $this->stocktakeTransition($actor, $stocktake, $attributes, [InventoryStocktake::STATUS_DRAFT, InventoryStocktake::STATUS_COUNTING, InventoryStocktake::STATUS_REVIEW], InventoryStocktake::STATUS_CANCELLED, ['cancelled_by_user_id' => $actor->id, 'cancelled_at' => now()->utc()]);
    }

    /** @param array<string, mixed> $attributes */
    public function adjust(User $actor, array $attributes): InventoryAdjustment
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $values = validator($attributes, ['location_public_id' => ['required', 'uuid'], 'sku_public_id' => ['required', 'uuid'], 'batch_public_id' => ['required', 'uuid'], 'direction' => ['required', 'in:in,out'], 'quantity' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'], 'reason_code' => ['required', 'in:'.implode(',', self::REASONS)], 'reason_note' => ['nullable', 'string', 'max:300'], 'idempotency_key' => ['required', 'uuid']])->validate();
        if ((float) $values['quantity'] <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Adjustment quantity must be positive.']);
        }
        $values['quantity'] = number_format((float) $values['quantity'], 3, '.', '');
        $values['reason_note'] = filled($values['reason_note'] ?? null) ? trim((string) $values['reason_note']) : null;
        $values['idempotency_key'] = Str::lower((string) $values['idempotency_key']);

        return DB::transaction(function () use ($actor, $values, $branch): InventoryAdjustment {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::ADJUST_PERMISSION);
            $location = $this->lockLocation($lockedActor, $branch, $values['location_public_id']);
            $sku = InventorySku::query()->where('public_id', $values['sku_public_id'])->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $batch = InventoryBatch::query()->where('public_id', $values['batch_public_id'])->where('organisation_id', $lockedActor->organisation_id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->firstOrFail();
            $requestHash = hash('sha256', json_encode([
                'organisation_id' => $lockedActor->organisation_id,
                'inventory_location_id' => $location->id,
                'inventory_sku_id' => $sku->id,
                'inventory_batch_id' => $batch->id,
                'direction' => $values['direction'],
                'quantity' => $values['quantity'],
                'reason_code' => $values['reason_code'],
                'reason_note' => $values['reason_note'],
            ], JSON_THROW_ON_ERROR));
            $publicId = (string) Str::uuid();
            $timestamp = now()->utc();
            $inserted = DB::table('inventory_adjustments')->insertOrIgnore([
                'public_id' => $publicId,
                'organisation_id' => $lockedActor->organisation_id,
                'idempotency_key' => $values['idempotency_key'],
                'request_hash' => $requestHash,
                'inventory_location_id' => $location->id,
                'inventory_sku_id' => $sku->id,
                'inventory_batch_id' => $batch->id,
                'direction' => $values['direction'],
                'quantity' => $values['quantity'],
                'reason_code' => $values['reason_code'],
                'reason_note' => $values['reason_note'],
                'posted_by_user_id' => $lockedActor->id,
                'posted_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            if ($inserted === 0) {
                $existing = InventoryAdjustment::query()->where('organisation_id', $lockedActor->organisation_id)
                    ->where('idempotency_key', $values['idempotency_key'])->lockForUpdate()->firstOrFail();
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This idempotency key was already used for another adjustment.']);
                }

                return $existing;
            }
            $adjustment = InventoryAdjustment::query()->where('public_id', $publicId)->where('organisation_id', $lockedActor->organisation_id)->firstOrFail();
            $this->movements->recordAdjustment($lockedActor, $location, $sku, $batch, $adjustment->direction, $adjustment->quantity, $adjustment->public_id);
            $this->audit->record('inventory.adjustment.posted', $adjustment, ['direction' => $adjustment->direction, 'reason_code' => $adjustment->reason_code], $lockedActor, $branch);

            return $adjustment;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function setReorderLevel(User $actor, array $attributes): InventoryReorderLevel
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $values = validator($attributes, ['location_public_id' => ['required', 'uuid'], 'sku_public_id' => ['required', 'uuid'], 'reorder_level' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/']])->validate();

        return DB::transaction(function () use ($actor, $values, $branch): InventoryReorderLevel {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::REORDER_PERMISSION);
            $location = $this->lockLocation($lockedActor, $branch, $values['location_public_id']);
            $sku = InventorySku::query()->where('public_id', $values['sku_public_id'])->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $level = InventoryReorderLevel::query()->where('organisation_id', $lockedActor->organisation_id)->where('inventory_location_id', $location->id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->first() ?? new InventoryReorderLevel;
            $level->forceFill(['organisation_id' => $lockedActor->organisation_id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $sku->id, 'reorder_level' => number_format((float) $values['reorder_level'], 3, '.', ''), 'updated_by_user_id' => $lockedActor->id])->save();
            $this->audit->record('inventory.reorder_level.updated', $level, ['reorder_level' => $level->reorder_level], $lockedActor, $branch);

            return $level;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  string|list<string>  $from
     * @param  array<string, mixed>  $extra
     */
    private function stocktakeTransition(User $actor, InventoryStocktake $stocktake, array $attributes, string|array $from, string $to, array $extra): InventoryStocktake
    {
        $branch = $this->authority->activeBranch($actor, $attributes);

        return DB::transaction(function () use ($actor, $stocktake, $attributes, $branch, $from, $to, $extra): InventoryStocktake {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::STOCKTAKE_PERMISSION);
            $locked = $this->lockStocktake($lockedActor, $stocktake, $attributes);
            $this->assertStocktakeLocation($lockedActor, $branch, $locked);
            $allowed = is_array($from) ? $from : [$from];
            if (! in_array($locked->status, $allowed, true)) {
                throw ValidationException::withMessages(['stocktake' => 'The stocktake is not in a valid state for this action.']);
            }
            if ($to === InventoryStocktake::STATUS_CANCELLED) {
                $extra = ['cancelled_by_user_id' => $lockedActor->id, 'cancelled_at' => now()->utc()];
            }
            $previous = $locked->status;
            $locked->forceFill(['status' => $to, 'lock_version' => $locked->lock_version + 1, ...$extra])->save();
            $this->audit->record('inventory.stocktake.'.$to, $locked, ['from' => $previous, 'to' => $to], $lockedActor, $branch);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function lockStocktake(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        $locked = InventoryStocktake::query()->whereKey($stocktake->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        if ((int) ($attributes['lock_version'] ?? 0) !== $locked->lock_version) {
            throw ValidationException::withMessages(['lock_version' => 'The stocktake changed. Review and retry.']);
        }

        return $locked;
    }

    private function lockLocation(User $actor, Branch $branch, string $publicId): InventoryLocation
    {
        $location = InventoryLocation::query()->where('public_id', $publicId)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        if ($location->branch_id === null) {
            if (! $actor->can(self::WAREHOUSE_PERMISSION)) {
                throw new AuthorizationException('Organisation warehouse operations require explicit authority.');
            }
        } else {
            abort_unless($location->branch_id === $branch->id, 404);
        }

        return $location;
    }

    private function assertStocktakeLocation(User $actor, Branch $branch, InventoryStocktake $stocktake): InventoryLocation
    {
        $location = InventoryLocation::query()->whereKey($stocktake->inventory_location_id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
        if ($location->branch_id === null && ! $actor->can(self::WAREHOUSE_PERMISSION)) {
            throw new AuthorizationException('Organisation warehouse operations require explicit authority.');
        }
        if ($location->branch_id !== null) {
            abort_unless($location->branch_id === $branch->id, 404);
        }

        return $location;
    }

    private function requireStocktakeStatus(InventoryStocktake $stocktake, string $status): void
    {
        if ($stocktake->status !== $status) {
            throw ValidationException::withMessages(['stocktake' => 'The stocktake is not in a valid state for this action.']);
        }
    }

    private function balanceKey(int $skuId, int $batchId): string
    {
        return $skuId.':'.$batchId;
    }
}
