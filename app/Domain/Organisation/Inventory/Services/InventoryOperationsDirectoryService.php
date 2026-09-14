<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InventoryOperationsDirectoryService
{
    public function __construct(private BranchAccessService $branches) {}

    /** @return array<string, mixed> */
    public function directory(User $actor): array
    {
        abort_unless($actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $organisationId = $actor->organisation_id;
        $localDate = now()->setTimezone($branch->timezone)->toDateString();
        $mayUseWarehouse = $actor->can(ProcurementService::WAREHOUSE_PERMISSION);
        $visibleLocationIds = array_values(DB::table('inventory_locations')->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('branch_id', $branch->id)->when($mayUseWarehouse, fn (Builder $locations) => $locations->orWhereNull('branch_id')))
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $purchaseOrders = DB::table('inventory_purchase_orders as po')->join('inventory_suppliers as supplier', 'supplier.id', '=', 'po.supplier_id')->join('inventory_locations as location', 'location.id', '=', 'po.destination_location_id')
            ->where('po.organisation_id', $organisationId)->where(fn (Builder $query) => $query->where('location.branch_id', $branch->id)->when($mayUseWarehouse, fn (Builder $locations) => $locations->orWhereNull('location.branch_id')))->orderByDesc('po.created_at')->limit(50)
            ->get(['po.id', 'po.public_id as publicId', 'po.order_number as number', 'po.status', 'po.lock_version as lockVersion', 'supplier.public_id as supplierPublicId', 'supplier.name as supplier', 'location.public_id as destinationPublicId', 'location.name as destination', 'po.created_at as createdAt']);
        $purchaseOrderLines = $this->purchaseOrderLines($organisationId, array_values($purchaseOrders->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()));
        $stockRequests = DB::table('inventory_stock_requests as request')->join('inventory_locations as source', 'source.id', '=', 'request.source_location_id')->join('inventory_locations as destination', 'destination.id', '=', 'request.destination_location_id')
            ->where('request.organisation_id', $organisationId)->where(fn (Builder $query) => $query->where('request.requesting_branch_id', $branch->id)->orWhere('source.branch_id', $branch->id)->orWhere('destination.branch_id', $branch->id))->orderByDesc('request.created_at')->limit(50)
            ->get(['request.id', 'request.public_id as publicId', 'request.request_number as number', 'request.status', 'request.lock_version as lockVersion', 'source.name as source', 'destination.name as destination', 'request.created_at as createdAt']);
        $stockRequestLines = $this->requestLines($organisationId, array_values($stockRequests->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()));
        $stocktakes = DB::table('inventory_stocktakes as stocktake')->join('inventory_locations as location', 'location.id', '=', 'stocktake.inventory_location_id')->where('stocktake.organisation_id', $organisationId)->whereIn('stocktake.inventory_location_id', $visibleLocationIds)->orderByDesc('stocktake.created_at')->limit(50)
            ->get(['stocktake.id', 'stocktake.public_id as publicId', 'stocktake.stocktake_number as number', 'stocktake.status', 'stocktake.lock_version as lockVersion', 'location.name as location', 'stocktake.created_at as createdAt']);
        $stocktakeLines = $this->stocktakeLines($organisationId, array_values($stocktakes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()));

        return [
            'expectedBranchId' => $branch->id,
            'permissions' => [
                'manageSuppliers' => $actor->can(SupplierAdministrationService::PERMISSION),
                'createPurchaseOrders' => $actor->can(ProcurementService::CREATE_PERMISSION),
                'approvePurchaseOrders' => $actor->can(ProcurementService::APPROVE_PERMISSION),
                'receiveGoods' => $actor->can(ProcurementService::RECEIVE_PERMISSION),
                'createRequests' => $actor->can(StockRequestService::CREATE_PERMISSION),
                'approveRequests' => $actor->can(StockRequestService::APPROVE_PERMISSION),
                'dispatchTransfers' => $actor->can(StockRequestService::DISPATCH_PERMISSION),
                'receiveTransfers' => $actor->can(StockRequestService::RECEIVE_PERMISSION),
                'stocktake' => $actor->can(InventoryControlService::STOCKTAKE_PERMISSION),
                'adjust' => $actor->can(InventoryControlService::ADJUST_PERMISSION),
                'manageReorder' => $actor->can(InventoryControlService::REORDER_PERMISSION),
            ],
            'suppliers' => DB::table('inventory_suppliers')->where('organisation_id', $organisationId)->orderBy('name')->get(['public_id as publicId', 'code', 'name', 'is_active as isActive'])->map(fn ($row): array => (array) $row)->all(),
            'locations' => DB::table('inventory_locations')->where('organisation_id', $organisationId)->where('is_active', true)
                ->where(fn (Builder $query) => $query->where('branch_id', $branch->id)->when($mayUseWarehouse, fn (Builder $locations) => $locations->orWhereNull('branch_id')))->orderBy('name')->get(['public_id as publicId', 'name', 'type', 'branch_id as branchId'])->map(fn ($row): array => (array) $row)->all(),
            'skus' => DB::table('inventory_skus as sku')->join('inventory_items as item', fn ($join) => $join->on('item.id', '=', 'sku.inventory_item_id')->on('item.organisation_id', '=', 'sku.organisation_id'))
                ->where('sku.organisation_id', $organisationId)->where('sku.is_active', true)->where('item.is_active', true)->orderBy('sku.sku_code')
                ->get(['sku.public_id as publicId', 'sku.sku_code as code', 'sku.stock_unit as stockUnit', 'item.generic_name as name'])->map(fn ($row): array => (array) $row)->all(),
            'batches' => DB::table('inventory_batches as batch')->join('inventory_skus as sku', fn ($join) => $join->on('sku.id', '=', 'batch.inventory_sku_id')->on('sku.organisation_id', '=', 'batch.organisation_id'))
                ->join('inventory_items as item', fn ($join) => $join->on('item.id', '=', 'sku.inventory_item_id')->on('item.organisation_id', '=', 'sku.organisation_id'))
                ->where('batch.organisation_id', $organisationId)->where('sku.is_active', true)->where('item.is_active', true)
                ->where('batch.status', 'available')->whereDate('batch.expiry_date', '>', $localDate)->orderBy('batch.expiry_date')->get(['batch.public_id as publicId', 'sku.public_id as skuPublicId', 'batch.batch_number as batchNumber', 'batch.expiry_date as expiryDate'])->map(fn ($row): array => (array) $row)->all(),
            'purchaseOrders' => $purchaseOrders->map(fn ($row): array => $this->documentWithLines($row, $purchaseOrderLines))->all(),
            'stockRequests' => $stockRequests->map(fn ($row): array => $this->documentWithLines($row, $stockRequestLines))->all(),
            'stocktakes' => $stocktakes->map(fn ($row): array => $this->documentWithLines($row, $stocktakeLines))->all(),
            'adjustments' => DB::table('inventory_adjustments as adjustment')->join('inventory_locations as location', 'location.id', '=', 'adjustment.inventory_location_id')->join('inventory_skus as sku', 'sku.id', '=', 'adjustment.inventory_sku_id')->join('inventory_batches as batch', 'batch.id', '=', 'adjustment.inventory_batch_id')
                ->where('adjustment.organisation_id', $organisationId)->whereIn('adjustment.inventory_location_id', $visibleLocationIds)->orderByDesc('adjustment.posted_at')->limit(50)
                ->get(['adjustment.public_id as publicId', 'adjustment.direction', 'adjustment.quantity', 'adjustment.reason_code as reasonCode', 'location.name as location', 'sku.sku_code as sku', 'batch.batch_number as batch', 'adjustment.posted_at as postedAt'])->map(fn ($row): array => (array) $row)->all(),
            'lowStock' => $this->lowStock($organisationId, $visibleLocationIds, $localDate),
            'expiringBatches' => $this->expiring($organisationId, $visibleLocationIds, $branch->timezone),
        ];
    }

    /**
     * @param  list<int>  $purchaseOrderIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function purchaseOrderLines(int $organisationId, array $purchaseOrderIds): array
    {
        return $this->groupLines(DB::table('inventory_purchase_order_lines as line')->join('inventory_skus as sku', 'sku.id', '=', 'line.inventory_sku_id')
            ->where('line.organisation_id', $organisationId)->whereIn('line.purchase_order_id', $purchaseOrderIds)->orderBy('line.purchase_order_id')->orderBy('line.id')
            ->get(['line.purchase_order_id as parentId', 'line.public_id as publicId', 'sku.public_id as skuPublicId', 'sku.sku_code as sku', 'line.ordered_quantity as orderedQuantity', 'line.received_quantity as receivedQuantity']));
    }

    /**
     * @param  list<int>  $stockRequestIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function requestLines(int $organisationId, array $stockRequestIds): array
    {
        return $this->groupLines(DB::table('inventory_stock_request_lines as line')->join('inventory_skus as sku', 'sku.id', '=', 'line.inventory_sku_id')->leftJoin('inventory_batches as batch', 'batch.id', '=', 'line.inventory_batch_id')
            ->where('line.organisation_id', $organisationId)->whereIn('line.stock_request_id', $stockRequestIds)->orderBy('line.stock_request_id')->orderBy('line.id')
            ->get(['line.stock_request_id as parentId', 'line.public_id as publicId', 'sku.public_id as skuPublicId', 'sku.sku_code as sku', 'line.requested_quantity as requestedQuantity', 'line.dispatched_quantity as dispatchedQuantity', 'line.received_quantity as receivedQuantity', 'batch.batch_number as batch']));
    }

    /**
     * @param  list<int>  $stocktakeIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function stocktakeLines(int $organisationId, array $stocktakeIds): array
    {
        return $this->groupLines(DB::table('inventory_stocktake_lines as line')->join('inventory_skus as sku', 'sku.id', '=', 'line.inventory_sku_id')->join('inventory_batches as batch', 'batch.id', '=', 'line.inventory_batch_id')
            ->where('line.organisation_id', $organisationId)->whereIn('line.stocktake_id', $stocktakeIds)->orderBy('line.stocktake_id')->orderBy('line.id')
            ->get(['line.stocktake_id as parentId', 'line.public_id as publicId', 'sku.sku_code as sku', 'batch.batch_number as batch', 'line.expected_quantity as expectedQuantity', 'line.physical_quantity as physicalQuantity', 'line.variance_quantity as varianceQuantity']));
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $lines
     * @return array<string, mixed>
     */
    private function documentWithLines(object $document, array $lines): array
    {
        $values = (array) $document;
        $id = (int) $values['id'];
        unset($values['id']);

        return [...$values, 'lines' => $lines[$id] ?? []];
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupLines(Collection $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            $parentId = (int) $values['parentId'];
            unset($values['parentId']);
            $grouped[$parentId][] = $values;
        }

        return $grouped;
    }

    /**
     * @param  list<int>  $locationIds
     * @return list<array<string, mixed>>
     */
    private function lowStock(int $organisationId, array $locationIds, string $localDate): array
    {
        $availableQuantity = "COALESCE(SUM(CASE WHEN batch.status = 'available' AND batch.expiry_date > ? THEN balance.quantity ELSE 0 END), 0)";

        return array_values(DB::table('inventory_reorder_levels as reorder')->join('inventory_locations as location', 'location.id', '=', 'reorder.inventory_location_id')->join('inventory_skus as sku', 'sku.id', '=', 'reorder.inventory_sku_id')->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')
            ->leftJoin('inventory_stock_balances as balance', fn ($join) => $join->on('balance.organisation_id', '=', 'reorder.organisation_id')->on('balance.inventory_location_id', '=', 'reorder.inventory_location_id')->on('balance.inventory_sku_id', '=', 'reorder.inventory_sku_id'))
            ->leftJoin('inventory_batches as batch', 'batch.id', '=', 'balance.inventory_batch_id')->where('reorder.organisation_id', $organisationId)->whereIn('reorder.inventory_location_id', $locationIds)
            ->where('location.is_active', true)->where('sku.is_active', true)->where('item.is_active', true)
            ->groupBy(['reorder.id', 'location.name', 'sku.sku_code', 'item.generic_name', 'reorder.reorder_level'])->havingRaw($availableQuantity.' <= reorder.reorder_level', [$localDate])
            ->orderBy('sku.sku_code')->selectRaw('location.name as location, sku.sku_code as sku, item.generic_name as item, reorder.reorder_level as "reorderLevel", '.$availableQuantity.' as "availableQuantity"', [$localDate])->get()->map(fn ($row): array => (array) $row)->all());
    }

    /**
     * @param  list<int>  $locationIds
     * @return list<array<string, mixed>>
     */
    private function expiring(int $organisationId, array $locationIds, string $timezone): array
    {
        $today = now()->setTimezone($timezone)->toDateString();
        $until = now()->setTimezone($timezone)->addDays(90)->toDateString();

        return array_values(DB::table('inventory_stock_balances as balance')->join('inventory_locations as location', 'location.id', '=', 'balance.inventory_location_id')->join('inventory_skus as sku', 'sku.id', '=', 'balance.inventory_sku_id')
            ->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')->join('inventory_batches as batch', 'batch.id', '=', 'balance.inventory_batch_id')
            ->where('balance.organisation_id', $organisationId)->whereIn('balance.inventory_location_id', $locationIds)->where('balance.quantity', '>', 0)
            ->where('location.is_active', true)->where('sku.is_active', true)->where('item.is_active', true)
            ->where('batch.status', 'available')->whereDate('batch.expiry_date', '>', $today)->whereDate('batch.expiry_date', '<=', $until)
            ->orderBy('batch.expiry_date')->get(['sku.sku_code as sku', 'batch.batch_number as batch', 'batch.expiry_date as expiryDate', 'balance.quantity', 'location.name as location'])->map(fn ($row): array => (array) $row)->all());
    }
}
