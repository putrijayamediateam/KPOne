<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\StaffAccessService;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class InventoryOperationsDirectoryService
{
    public function __construct(private BranchAccessService $branches, private StaffAccessService $staff) {}

    /** @return array<string, mixed> */
    public function directory(User $actor): array
    {
        abort_unless($actor->is_active && $actor->can('inventory.view.branch'), 403);
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        $organisationId = $actor->organisation_id;
        $localDate = now()->setTimezone($branch->timezone)->toDateString();
        $mayUseWarehouse = $actor->can(ProcurementService::WAREHOUSE_PERMISSION);
        $visibleLocationIds = DB::table('inventory_locations')->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('branch_id', $branch->id)->when($mayUseWarehouse, fn (Builder $locations) => $locations->orWhereNull('branch_id')))
            ->select('id');
        $purchaseOrders = DB::table('inventory_purchase_orders as po')->join('inventory_suppliers as supplier', 'supplier.id', '=', 'po.supplier_id')->join('inventory_locations as location', 'location.id', '=', 'po.destination_location_id')
            ->leftJoin('inventory_purchase_requests as from_request', 'from_request.purchase_order_id', '=', 'po.id')
            ->where('po.organisation_id', $organisationId)->where(fn (Builder $query) => $query->where('location.branch_id', $branch->id)->when($mayUseWarehouse, fn (Builder $locations) => $locations->orWhereNull('location.branch_id')))->orderByDesc('po.created_at')->limit(50)
            ->get(['po.id', 'po.public_id as publicId', 'po.order_number as number', 'po.status', 'po.lock_version as lockVersion', 'supplier.public_id as supplierPublicId', 'supplier.name as supplier', 'location.public_id as destinationPublicId', 'location.name as destination', 'po.created_at as createdAt', 'po.created_by_user_id', 'po.submitted_by_user_id', 'from_request.request_number as fromRequest', 'po.close_kind as closeKind', 'po.short_close_reason as shortCloseReason', 'po.short_close_note as shortCloseNote', 'po.closed_by_user_id', 'po.closed_at']);
        $closerIds = $purchaseOrders->where('closeKind', 'short')->pluck('closed_by_user_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $closers = $closerIds === [] ? collect() : $this->staff->visibleUsers($actor)->whereIn('id', $closerIds)->pluck('name', 'id');
        $purchaseOrderLines = $this->purchaseOrderLines($organisationId, array_values($purchaseOrders->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()));
        $stockRequests = DB::table('inventory_stock_requests as request')->join('inventory_locations as source', 'source.id', '=', 'request.source_location_id')->join('inventory_locations as destination', 'destination.id', '=', 'request.destination_location_id')
            ->where('request.organisation_id', $organisationId)->where(fn (Builder $query) => $query->where('request.requesting_branch_id', $branch->id)->orWhere('source.branch_id', $branch->id)->orWhere('destination.branch_id', $branch->id))->orderByDesc('request.created_at')->limit(50)
            ->get(['request.id', 'request.public_id as publicId', 'request.request_number as number', 'request.status', 'request.lock_version as lockVersion', 'source.name as source', 'source.public_id as sourcePublicId', 'destination.name as destination', 'request.created_at as createdAt',
                'request.requesting_branch_id', 'source.branch_id as source_branch_id', 'destination.branch_id as destination_branch_id'])
            // STK-31: a request can go to another branch; say which end this branch is (mirrors StockRequestService; the server decides).
            ->map(function (object $row) use ($branch, $mayUseWarehouse): object {
                $end = fn (mixed $branchId): bool => $branchId === null ? $mayUseWarehouse : (int) $branchId === $branch->id;
                $row->atRequesting = (int) $row->requesting_branch_id === $branch->id;
                $row->atSource = $end($row->source_branch_id);
                $row->atDestination = $end($row->destination_branch_id);
                unset($row->requesting_branch_id, $row->source_branch_id, $row->destination_branch_id);

                return $row;
            });
        $stockRequestIds = array_values($stockRequests->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $stockRequestLines = $this->requestLines($organisationId, $stockRequestIds);
        $transferDifferences = $this->transferDifferences($actor, $stockRequestIds);
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
                'resolveTransferDifferences' => $actor->can(StockRequestService::RESOLVE_PERMISSION),
                'stocktake' => false,
                'adjust' => false,
                'manageReorder' => $actor->can(InventoryControlService::REORDER_PERMISSION),
            ],
            'suppliers' => app(InventoryPickerService::class)->choices($actor, $branch, 'suppliers', 'operations'),
            'locations' => app(InventoryPickerService::class)->choices($actor, $branch, 'locations', 'operations'),
            'skus' => app(InventoryPickerService::class)->choices($actor, $branch, 'skus', 'operations'),
            'batches' => app(InventoryPickerService::class)->choices($actor, $branch, 'batches', 'operations'),
            // STK-19: whoever raised or submitted an order may not approve it, or cancel it once approved.
            'purchaseOrders' => $purchaseOrders->map(function ($row) use ($purchaseOrderLines, $actor, $closers, $branch): array {
                $raised = (int) $row->created_by_user_id === $actor->id || (int) $row->submitted_by_user_id === $actor->id;
                // STK-21: who closed an order short and when, the name following the staff-visibility rule.
                $closedShort = $row->closeKind === 'short' ? [
                    'closedBy' => $closers->get((int) $row->closed_by_user_id, 'Staff member'),
                    'closedOn' => CarbonImmutable::parse((string) $row->closed_at, 'UTC')->setTimezone($branch->timezone)->format('j M Y'),
                ] : [];
                unset($row->created_by_user_id, $row->submitted_by_user_id, $row->closed_by_user_id, $row->closed_at);

                $document = $this->documentWithLines($row, $purchaseOrderLines);
                // STK-21: what is still to come on each line, worked out exactly in thousandths.
                $document['lines'] = array_map(fn (array $line): array => [...$line, 'outstandingQuantity' => StockUnitQuantity::format(
                    StockUnitQuantity::scaled((string) $line['orderedQuantity'], true) - StockUnitQuantity::scaled((string) $line['receivedQuantity'], true))], $document['lines']);

                return [...$document, ...$closedShort, 'raisedByYou' => $raised];
            })->all(),
            'stockRequests' => $stockRequests->map(fn ($row): array => [...$this->documentWithLines($row, $stockRequestLines), 'differences' => $transferDifferences[(int) $row->id] ?? []])->all(),
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
        return $this->groupLines(DB::table('inventory_purchase_order_lines as line')->join('inventory_skus as sku', 'sku.id', '=', 'line.inventory_sku_id')->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')
            ->where('line.organisation_id', $organisationId)->whereIn('line.purchase_order_id', $purchaseOrderIds)->orderBy('line.purchase_order_id')->orderBy('line.id')
            ->get(['line.purchase_order_id as parentId', 'line.public_id as publicId', 'sku.public_id as skuPublicId', 'sku.sku_code as sku', 'sku.stock_unit as stockUnit', 'line.ordered_quantity as orderedQuantity', 'line.received_quantity as receivedQuantity', 'line.not_delivered_quantity as notDeliveredQuantity', 'item.generic_name as item']));
    }

    /**
     * @param  list<int>  $stockRequestIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function requestLines(int $organisationId, array $stockRequestIds): array
    {
        // V2 page 2b (Daniel): the item's name and stock unit, read only, so the person dispatching or counting a transfer
        // sees "Paracetamol 500 mg tablet" and "5 units", not only the SKU code. Purchase order lines already carry both.
        return $this->groupLines(DB::table('inventory_stock_request_lines as line')->join('inventory_skus as sku', 'sku.id', '=', 'line.inventory_sku_id')->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')->leftJoin('inventory_batches as batch', 'batch.id', '=', 'line.inventory_batch_id')
            ->where('line.organisation_id', $organisationId)->whereIn('line.stock_request_id', $stockRequestIds)->orderBy('line.stock_request_id')->orderBy('line.id')
            ->get(['line.stock_request_id as parentId', 'line.public_id as publicId', 'sku.public_id as skuPublicId', 'sku.sku_code as sku', 'line.requested_quantity as requestedQuantity', 'line.dispatched_quantity as dispatchedQuantity', 'line.received_quantity as receivedQuantity', 'batch.batch_number as batch', 'sku.stock_unit as stockUnit', 'item.generic_name as item']));
    }

    /**
     * STK-6 transfer differences with their append-only resolution. Identifiers only; no staff names.
     *
     * @param  list<int>  $stockRequestIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function transferDifferences(User $actor, array $stockRequestIds): array
    {
        $mayResolve = $actor->can(StockRequestService::RESOLVE_PERMISSION);
        $activeBranchId = $this->branches->activeBranch($actor)?->id;
        $mayUseWarehouse = $actor->can(StockRequestService::WAREHOUSE_PERMISSION);
        $rows = DB::table('inventory_transfer_differences as difference')
            ->join('inventory_transfer_receipt_lines as receipt', 'receipt.id', '=', 'difference.receipt_line_id')
            ->join('inventory_stock_requests as request', 'request.id', '=', 'difference.stock_request_id')
            ->join('inventory_locations as destination', 'destination.id', '=', 'request.destination_location_id')
            ->join('inventory_skus as sku', 'sku.id', '=', 'receipt.inventory_sku_id')
            ->join('inventory_batches as batch', 'batch.id', '=', 'receipt.inventory_batch_id')
            ->leftJoin('inventory_transfer_difference_resolutions as resolution', 'resolution.difference_id', '=', 'difference.id')
            ->where('difference.organisation_id', $actor->organisation_id)->whereIn('difference.stock_request_id', $stockRequestIds)
            ->orderBy('difference.stock_request_id')->orderBy('difference.id')
            ->get(['difference.stock_request_id as parentId', 'difference.public_id as publicId', 'difference.kind', 'difference.quantity', 'receipt.base_unit as unit', 'sku.sku_code as sku', 'batch.batch_number as batch', 'difference.received_by_user_id as receivedBy', 'resolution.outcome', 'resolution.reference', 'resolution.resolved_by_user_id as resolvedBy', 'resolution.created_at as resolvedAt', 'destination.branch_id as destinationBranchId']);
        $grouped = [];
        foreach ($rows as $row) {
            $open = $row->outcome === null;
            // Mirrors StockRequestService::assertDestinationAuthority; the server still decides.
            $atDestination = $row->destinationBranchId === null ? $mayUseWarehouse : (int) $row->destinationBranchId === $activeBranchId;
            $grouped[(int) $row->parentId][] = [
                'publicId' => $row->publicId, 'kind' => $row->kind, 'quantity' => $row->quantity, 'unit' => $row->unit, 'sku' => $row->sku, 'batch' => $row->batch,
                'status' => $open ? 'open' : 'resolved', 'outcome' => $row->outcome, 'reference' => $row->reference, 'resolvedAt' => $row->resolvedAt,
                'outcomes' => StockRequestService::OUTCOMES[(string) $row->kind] ?? [],
                'canResolve' => $open && $mayResolve && $atDestination && (int) $row->receivedBy !== $actor->id,
                'receivedByYou' => (int) $row->receivedBy === $actor->id,
            ];
        }

        return $grouped;
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
     * @return list<array<string, mixed>>
     */
    private function lowStock(int $organisationId, Builder $locationIds, string $localDate): array
    {
        $availableQuantity = "COALESCE(SUM(CASE WHEN batch.status = 'available' AND batch.expiry_date > ? THEN balance.quantity ELSE 0 END), 0)";

        return array_values(DB::table('inventory_reorder_levels as reorder')->join('inventory_locations as location', 'location.id', '=', 'reorder.inventory_location_id')->join('inventory_skus as sku', 'sku.id', '=', 'reorder.inventory_sku_id')->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')
            ->leftJoin('inventory_stock_balances as balance', fn ($join) => $join->on('balance.organisation_id', '=', 'reorder.organisation_id')->on('balance.inventory_location_id', '=', 'reorder.inventory_location_id')->on('balance.inventory_sku_id', '=', 'reorder.inventory_sku_id'))
            ->leftJoin('inventory_batches as batch', 'batch.id', '=', 'balance.inventory_batch_id')->where('reorder.organisation_id', $organisationId)->whereIn('reorder.inventory_location_id', $locationIds)
            ->where('location.is_active', true)->where('location.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)->where('sku.is_active', true)->where('item.is_active', true)
            ->groupBy(['reorder.id', 'location.name', 'sku.sku_code', 'sku.stock_unit', 'item.generic_name', 'reorder.reorder_level', 'reorder.max_level'])->havingRaw($availableQuantity.' <= reorder.reorder_level', [$localDate])
            ->orderByRaw('sku.sku_code COLLATE "C"')->orderBy('reorder.id')->limit(100)->selectRaw('location.name as location, sku.sku_code as sku, sku.stock_unit as "stockUnit", item.generic_name as item, reorder.reorder_level as "reorderLevel", reorder.max_level as "maxLevel", '.$availableQuantity.' as "availableQuantity"', [$localDate])->get()->map(fn ($row): array => (array) $row)->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expiring(int $organisationId, Builder $locationIds, string $timezone): array
    {
        $today = now()->setTimezone($timezone)->toDateString();
        $until = now()->setTimezone($timezone)->addDays(90)->toDateString();

        return array_values(DB::table('inventory_stock_balances as balance')->join('inventory_locations as location', 'location.id', '=', 'balance.inventory_location_id')->join('inventory_skus as sku', 'sku.id', '=', 'balance.inventory_sku_id')
            ->join('inventory_items as item', 'item.id', '=', 'sku.inventory_item_id')->join('inventory_batches as batch', 'batch.id', '=', 'balance.inventory_batch_id')
            ->where('balance.organisation_id', $organisationId)->whereIn('balance.inventory_location_id', $locationIds)->where('balance.quantity', '>', 0)
            ->where('location.is_active', true)->where('location.type', '<>', InventoryLocation::TYPE_DISPENSARY_RETURNS)->where('sku.is_active', true)->where('item.is_active', true)
            ->where('batch.status', 'available')->whereDate('batch.expiry_date', '>', $today)->whereDate('batch.expiry_date', '<=', $until)
            ->orderBy('batch.expiry_date')->orderByRaw('sku.sku_code COLLATE "C"')->orderBy('balance.id')->limit(100)->get(['sku.sku_code as sku', 'sku.stock_unit as stockUnit', 'item.generic_name as item', 'batch.batch_number as batch', 'batch.expiry_date as expiryDate', 'balance.quantity', 'location.name as location'])->map(fn ($row): array => (array) $row)->all());
    }
}
