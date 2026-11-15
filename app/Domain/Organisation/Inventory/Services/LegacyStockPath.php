<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventoryStocktake;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Closed APIs retain fresh authority and scope checks, but never mutate stock or history. */
final class LegacyStockPath
{
    public const MESSAGE = 'Legacy stocktake and manual adjustment are closed. Use Stock count (governed) or Write-off (governed) in Inventory; independent approval is required.';

    /** @param array<string,mixed> $attributes */
    public static function refuse(User $actor, string $permission, array $attributes, ?InventoryStocktake $stocktake = null, ?InventoryLocation $location = null): never
    {
        $authority = app(InventoryAuthorityService::class);
        $branch = $authority->activeBranch($actor, $attributes);
        DB::transaction(function () use ($actor, $permission, $attributes, $stocktake, $location, $authority, $branch): void {
            $actor = $authority->lockForBranch($actor, $branch, $permission);
            if ($stocktake !== null) {
                $stocktake = InventoryStocktake::query()->whereKey($stocktake->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
                $location = InventoryLocation::query()->whereKey($stocktake->inventory_location_id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
            } elseif ($location !== null) {
                $location = InventoryLocation::query()->whereKey($location->id)->where('organisation_id', $actor->organisation_id)->firstOrFail();
            } elseif (is_string($attributes['location_public_id'] ?? null) && Str::isUuid($attributes['location_public_id'])) {
                $location = InventoryLocation::query()->where('public_id', $attributes['location_public_id'])->where('organisation_id', $actor->organisation_id)->firstOrFail();
            }
            if ($location !== null) {
                if ($location->branch_id === null) {
                    if (! $actor->can(InventoryControlService::WAREHOUSE_PERMISSION)) {
                        throw new AuthorizationException('Organisation warehouse operations require explicit authority.');
                    }
                } else {
                    abort_unless($location->branch_id === $branch->id, 404);
                }
            }
            throw ValidationException::withMessages(['legacy_stock_path' => self::MESSAGE]);
        }, 3);
    }

    public static function refuseForLocation(User $actor, string $permission, InventoryLocation $location): never
    {
        $branch = app(BranchAccessService::class)->activeBranch($actor);
        self::refuse($actor, $permission, ['expected_branch_id' => $branch?->id], location: $location);
    }
}
