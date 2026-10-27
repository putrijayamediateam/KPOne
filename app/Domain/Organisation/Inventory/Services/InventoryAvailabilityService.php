<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryAvailabilityService
{
    /** @return list<array<string,mixed>> */
    public function forSku(User $actor, int $skuId, int $branchId, bool $dispensaryOnly = false): array
    {
        $branch = Branch::query()
            ->whereKey($branchId)
            ->where('organisation_id', $actor->organisation_id)
            ->firstOrFail();

        $rows = DB::table('inventory_stock_balances')->where('inventory_stock_balances.organisation_id', $actor->organisation_id)->where('inventory_stock_balances.inventory_sku_id', $skuId)->where('inventory_stock_balances.quantity', '>', 0)
            ->join('inventory_locations', 'inventory_locations.id', '=', 'inventory_stock_balances.inventory_location_id')
            ->joinSub(app(InventoryBatchEligibility::class)->query($actor->organisation_id, $branch), 'inventory_batches', 'inventory_batches.id', '=', 'inventory_stock_balances.inventory_batch_id')
            ->where('inventory_locations.is_active', true)->where(fn ($q) => $q->where('inventory_locations.branch_id', $branchId)->orWhereNull('inventory_locations.branch_id'))
            ->when($dispensaryOnly, fn ($q) => $q->where('inventory_locations.branch_id', $branchId)->where('inventory_locations.type', InventoryLocation::TYPE_DISPENSARY))
            ->where(fn ($q) => $q->whereNull('inventory_batches.eligibility_reason')->orWhereNotNull('inventory_batches.hold_reason'))
            ->orderByRaw('CASE WHEN inventory_locations.branch_id = ? AND inventory_locations.type = ? THEN 0 WHEN inventory_locations.branch_id = ? THEN 1 ELSE 2 END', [$branchId, InventoryLocation::TYPE_DISPENSARY, $branchId])
            ->orderBy('inventory_batches.expiry_date')->orderBy('inventory_batches.received_at')->orderBy('inventory_batches.id')
            ->limit(100)->get(['inventory_batches.eligibility_reason', 'inventory_batches.hold_reason', 'inventory_locations.public_id as location_public_id', 'inventory_locations.name as location_name', 'inventory_locations.type as location_type', 'inventory_batches.public_id as batch_public_id', 'inventory_batches.batch_number', 'inventory_batches.expiry_date', 'inventory_stock_balances.quantity'])
            ->map(fn (object $row): array => ['locationPublicId' => $row->location_public_id, 'locationName' => $row->location_name, 'locationType' => $row->location_type, 'batchPublicId' => $row->batch_public_id, 'batchNumber' => $row->batch_number, 'expiryDate' => (string) $row->expiry_date, 'quantity' => (string) $row->quantity, ...($row->hold_reason !== null ? ['held' => true, 'holdReason' => $row->hold_reason] : [])])->all();

        return array_values($rows);
    }
}
