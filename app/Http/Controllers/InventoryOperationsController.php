<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Models\InventoryStocktake;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Models\PurchaseOrder;
use App\Domain\Organisation\Inventory\Models\StockRequest;
use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InventoryOperationsController extends Controller
{
    public function createSupplier(Request $request, SupplierAdministrationService $service): RedirectResponse
    {
        $service->create($request->user(), $request->only(['code', 'name', 'contact_name', 'business_email', 'business_phone', 'is_active']));

        return $this->success('Supplier created.');
    }

    public function updateSupplier(Request $request, InventorySupplier $supplier, SupplierAdministrationService $service): RedirectResponse
    {
        $service->update($request->user(), $supplier, $request->only(['name', 'contact_name', 'business_email', 'business_phone']));

        return $this->success('Supplier updated.');
    }

    public function supplierStatus(Request $request, InventorySupplier $supplier, SupplierAdministrationService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($request->user(), $supplier, $data['is_active']);

        return $this->success($data['is_active'] ? 'Supplier activated.' : 'Supplier deactivated.');
    }

    public function createPurchaseOrder(Request $request, ProcurementService $service): RedirectResponse
    {
        $service->create($request->user(), $request->only(['expected_branch_id', 'supplier_public_id', 'destination_location_public_id', 'lines']));

        return $this->success('Purchase Order draft created.');
    }

    public function updatePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        $service->updateDraft($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version', 'supplier_public_id', 'destination_location_public_id', 'lines']));

        return $this->success('Purchase Order draft updated.');
    }

    public function submitPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        $service->submit($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version']));

        return $this->success('Purchase Order submitted.');
    }

    public function approvePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        $service->approve($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version']));

        return $this->success('Purchase Order approved.');
    }

    public function cancelPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        $service->cancel($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version', 'reason']));

        return $this->success('Purchase Order cancelled.');
    }

    public function closePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        $service->close($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version']));

        return $this->success('Purchase Order closed.');
    }

    public function receivePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        $data = $request->validate([
            'receipt_session_nonce' => ['required', 'uuid'],
        ]);
        $submittedNonce = Str::lower($data['receipt_session_nonce']);
        $currentNonce = InventoryController::currentReceiptSessionNonce($request, $request->user());

        if ($currentNonce === null || ! hash_equals($currentNonce, $submittedNonce)) {
            throw ValidationException::withMessages([
                'receipt_session_nonce' => 'The secure receipt session expired. Reload Inventory before receiving stock.',
            ]);
        }

        $service->receive($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version', 'idempotency_key', 'lines']));

        return $this->success('Goods Receipt posted.');
    }

    public function createStockRequest(Request $request, StockRequestService $service): RedirectResponse
    {
        $service->create($request->user(), $request->only(['expected_branch_id', 'source_location_public_id', 'destination_location_public_id', 'lines']));

        return $this->success('Stock Request submitted.');
    }

    public function approveStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        $service->approve($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version']));

        return $this->success('Stock Request approved.');
    }

    public function rejectStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        $service->reject($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version', 'reason']));

        return $this->success('Stock Request rejected.');
    }

    public function dispatchStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        $service->dispatch($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version', 'dispatch_idempotency_key', 'lines']));

        return $this->success('Transfer dispatched.');
    }

    public function receiveStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        $service->receive($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version', 'receive_idempotency_key']));

        return $this->success('Transfer received.');
    }

    public function createStocktake(Request $request, InventoryControlService $service): RedirectResponse
    {
        $service->createStocktake($request->user(), $request->only(['expected_branch_id', 'location_public_id']));

        return $this->success('Stocktake draft created.');
    }

    public function startStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        $service->startCounting($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version']));

        return $this->success('Stocktake counting started.');
    }

    public function countStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        $service->recordCounts($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version', 'lines']));

        return $this->success('Stocktake sent for review.');
    }

    public function postStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        $service->postStocktake($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version']));

        return $this->success('Stocktake posted.');
    }

    public function cancelStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        $service->cancelStocktake($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version']));

        return $this->success('Stocktake cancelled.');
    }

    public function adjustment(Request $request, InventoryControlService $service): RedirectResponse
    {
        $service->adjust($request->user(), $request->only(['expected_branch_id', 'location_public_id', 'sku_public_id', 'batch_public_id', 'direction', 'quantity', 'reason_code', 'reason_note', 'idempotency_key']));

        return $this->success('Inventory adjustment posted.');
    }

    public function reorderLevel(Request $request, InventoryControlService $service): RedirectResponse
    {
        $service->setReorderLevel($request->user(), $request->only(['expected_branch_id', 'location_public_id', 'sku_public_id', 'reorder_level']));

        return $this->success('Reorder level updated.');
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
