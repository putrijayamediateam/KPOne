<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Organisation\Inventory\Models\InventoryAdjustment;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventoryReorderLevel;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStocktake;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

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
