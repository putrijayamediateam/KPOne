<?php

namespace App\Domain\Organisation\Inventory\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared retained movement scope. Callers must authorize the actor and assigned branch. */
final class InventoryMovementProjection
{
    public static function query(int $organisation, int $branch): Builder
    {
        return DB::table('stock_movements as movement')
            ->join('inventory_skus as s', fn ($join) => $join->on('s.id', '=', 'movement.inventory_sku_id')->on('s.organisation_id', '=', 'movement.organisation_id'))
            ->join('inventory_items as i', fn ($join) => $join->on('i.id', '=', 's.inventory_item_id')->on('i.organisation_id', '=', 'movement.organisation_id'))
            ->join('inventory_batches as batch', fn ($join) => $join->on('batch.id', '=', 'movement.inventory_batch_id')->on('batch.organisation_id', '=', 'movement.organisation_id'))
            ->leftJoin('inventory_locations as source', fn ($join) => $join->on('source.id', '=', 'movement.source_location_id')->on('source.organisation_id', '=', 'movement.organisation_id'))
            ->leftJoin('inventory_locations as destination', fn ($join) => $join->on('destination.id', '=', 'movement.destination_location_id')->on('destination.organisation_id', '=', 'movement.organisation_id'))
            ->where('movement.organisation_id', $organisation)
            ->where(fn (Builder $scope) => $scope->where('source.branch_id', $branch)->orWhere('destination.branch_id', $branch));
    }
}
