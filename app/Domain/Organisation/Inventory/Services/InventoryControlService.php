<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryAdjustment;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventoryReorderLevel;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStocktake;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryControlService
{
    public const STOCKTAKE_PERMISSION = 'inventory.stocktake.branch';

    public const ADJUST_PERMISSION = 'inventory.adjust.branch';

    public const REORDER_PERMISSION = 'inventory.reorder.manage.branch';

    public const WAREHOUSE_PERMISSION = 'inventory.warehouse.organisation';

    public function __construct(
        private InventoryAuthorityService $authority,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createStocktake(User $actor, array $attributes): InventoryStocktake
    {
        LegacyStockPath::refuse($actor, self::STOCKTAKE_PERMISSION, $attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function startCounting(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        LegacyStockPath::refuse($actor, self::STOCKTAKE_PERMISSION, $attributes, stocktake: $stocktake);
    }

    /** @param array<string, mixed> $attributes */
    public function recordCounts(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        LegacyStockPath::refuse($actor, self::STOCKTAKE_PERMISSION, $attributes, stocktake: $stocktake);
    }

    /** @param array<string, mixed> $attributes */
    public function postStocktake(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        LegacyStockPath::refuse($actor, self::STOCKTAKE_PERMISSION, $attributes, stocktake: $stocktake);
    }

    /** @param array<string, mixed> $attributes */
    public function cancelStocktake(User $actor, InventoryStocktake $stocktake, array $attributes): InventoryStocktake
    {
        LegacyStockPath::refuse($actor, self::STOCKTAKE_PERMISSION, $attributes, stocktake: $stocktake);
    }

    /** @param array<string, mixed> $attributes */
    public function adjust(User $actor, array $attributes): InventoryAdjustment
    {
        LegacyStockPath::refuse($actor, self::ADJUST_PERMISSION, $attributes);
    }

    /**
     * STK-30b: optimistic locking (expected_lock_version: null for a new level) and an idempotency key.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function setReorderLevel(User $actor, array $attributes): InventoryReorderLevel
    {
        $branch = $this->authority->activeBranch($actor, $attributes);
        $values = validator($attributes, [
            'location_public_id' => ['required', 'uuid'], 'sku_public_id' => ['required', 'uuid'],
            'reorder_level' => ['required', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'],
            // STK-30: optional maximum; blank clears it. Suggestions order up to it, nothing is ordered automatically.
            'max_level' => ['nullable', 'regex:/^\d{1,12}(?:\.\d{1,3})?$/'],
            'expected_lock_version' => ['present', 'nullable', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();
        $max = filled($values['max_level'] ?? null) ? StockUnitQuantity::scaled((string) $values['max_level'], true) : null;
        $reorder = StockUnitQuantity::scaled((string) $values['reorder_level'], true);
        if ($max !== null && $reorder === 0) {
            // A level of 0 never lists the item as low, so a maximum would do nothing.
            throw ValidationException::withMessages(['max_level' => 'Set a reorder level above 0 to use a maximum.']);
        }
        if ($max !== null && $max < $reorder) {
            throw ValidationException::withMessages(['max_level' => 'The maximum must be at least the reorder level.']);
        }
        $expected = $values['expected_lock_version'] === null ? null : (int) $values['expected_lock_version'];
        $key = strtolower((string) $values['idempotency_key']);
        $hash = hash('sha256', json_encode([(int) $actor->id, strtolower((string) $values['location_public_id']), strtolower((string) $values['sku_public_id']),
            StockUnitQuantity::format($reorder), $max === null ? null : StockUnitQuantity::format($max), $expected], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $values, $reorder, $max, $expected, $key, $hash, $branch): InventoryReorderLevel {
            $lockedActor = $this->authority->lockForBranch($actor, $branch, self::REORDER_PERMISSION);
            $location = $this->lockLocation($lockedActor, $branch, $values['location_public_id']);
            $sku = InventorySku::query()->where('public_id', $values['sku_public_id'])->where('organisation_id', $lockedActor->organisation_id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $level = InventoryReorderLevel::query()->where('organisation_id', $lockedActor->organisation_id)->where('inventory_location_id', $location->id)->where('inventory_sku_id', $sku->id)->lockForUpdate()->first();
            // Read after the row locks, so a double submit that waited behind the first one replays it.
            $command = DB::table('inventory_reorder_level_commands')->where('organisation_id', $lockedActor->organisation_id)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($command !== null) {
                if ((int) $command->branch_id !== $branch->id || $command->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different details.']);
                }

                return InventoryReorderLevel::query()->whereKey($command->inventory_reorder_level_id)
                    ->where('organisation_id', $lockedActor->organisation_id)->lockForUpdate()->firstOrFail();
            }
            if (($level === null) !== ($expected === null) || ($level !== null && (int) $level->lock_version !== $expected)) {
                throw ValidationException::withMessages(['expected_lock_version' => 'This reorder level changed. Reload and try again.']);
            }
            $level ??= new InventoryReorderLevel;
            try {
                $level->forceFill(['organisation_id' => $lockedActor->organisation_id, 'inventory_location_id' => $location->id, 'inventory_sku_id' => $sku->id,
                    'reorder_level' => StockUnitQuantity::format($reorder), 'max_level' => $max === null ? null : StockUnitQuantity::format($max),
                    'lock_version' => $level->exists ? (int) $level->lock_version + 1 : 1, 'updated_by_user_id' => $lockedActor->id])->save();
                DB::table('inventory_reorder_level_commands')->insert(['organisation_id' => $lockedActor->organisation_id, 'branch_id' => $branch->id,
                    'inventory_reorder_level_id' => $level->id, 'idempotency_key' => $key, 'payload_hash' => $hash, 'created_at' => now()->utc()]);
            } catch (UniqueConstraintViolationException $exception) {
                // Two first saves for one location and SKU (or one key) raced: the unique index keeps the first, and
                // the other is refused like any stale page. The whole transaction rolls back.
                throw str_contains($exception->getMessage(), 'reorder_level_commands_key_unique')
                    ? ValidationException::withMessages(['idempotency_key' => 'This request key was already used with different details.'])
                    : ValidationException::withMessages(['expected_lock_version' => 'This reorder level changed. Reload and try again.']);
            }
            $this->audit->record('inventory.reorder_level.updated', $level, ['reorder_level' => $level->reorder_level, 'max_level' => $level->max_level, 'lock_version' => $level->lock_version], $lockedActor, $branch);

            return $level;
        }, 3);
    }

    /**
     * STK-30b: the saved level for one location and SKU, so the form can show it and send its version. Read only.
     *
     * @param  array<string, mixed>  $input
     * @return array{reorderLevel: string, maxLevel: string|null, lockVersion: int}|null
     */
    public function currentReorderLevel(User $actor, array $input): ?array
    {
        $branch = $this->authority->activeBranch($actor, $input);
        $values = validator($input, ['location_public_id' => ['required', 'uuid'], 'sku_public_id' => ['required', 'uuid']])->validate();
        $actor = $actor->fresh()?->load(['roles.permissions', 'permissions']);
        abort_unless($actor && $actor->is_active && $actor->can(self::REORDER_PERMISSION) && app(BranchAccessService::class)->hasEffectiveAssignment($actor, $branch), 403);
        $location = InventoryLocation::query()->where('public_id', $values['location_public_id'])->where('organisation_id', $actor->organisation_id)->where('is_active', true)->firstOrFail();
        abort_unless($location->branch_id === $branch->id || ($location->branch_id === null && $actor->can(self::WAREHOUSE_PERMISSION)), 404);
        $sku = InventorySku::query()->where('public_id', $values['sku_public_id'])->where('organisation_id', $actor->organisation_id)->where('is_active', true)->firstOrFail();
        $level = InventoryReorderLevel::query()->where('organisation_id', $actor->organisation_id)->where('inventory_location_id', $location->id)->where('inventory_sku_id', $sku->id)->first();

        return $level === null ? null : [
            'reorderLevel' => StockUnitQuantity::format(StockUnitQuantity::scaled((string) $level->reorder_level, true)),
            'maxLevel' => $level->max_level === null ? null : StockUnitQuantity::format(StockUnitQuantity::scaled((string) $level->max_level, true)),
            'lockVersion' => (int) $level->lock_version,
        ];
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
}
