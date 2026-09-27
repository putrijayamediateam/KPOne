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
        try {
            $service->create($request->user(), $request->only(['code', 'name', 'contact_name', 'business_email', 'business_phone', 'is_active']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Supplier created.');
    }

    public function updateSupplier(Request $request, InventorySupplier $supplier, SupplierAdministrationService $service): RedirectResponse
    {
        try {
            $service->update($request->user(), $supplier, $request->only(['name', 'contact_name', 'business_email', 'business_phone']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Supplier updated.');
    }

    public function supplierStatus(Request $request, InventorySupplier $supplier, SupplierAdministrationService $service): RedirectResponse
    {
        try {
            $data = $request->validate(['is_active' => ['required', 'boolean']]);
            $service->setActive($request->user(), $supplier, $data['is_active']);
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success($data['is_active'] ? 'Supplier activated.' : 'Supplier deactivated.');
    }

    public function createPurchaseOrder(Request $request, ProcurementService $service): RedirectResponse
    {
        try {
            $service->create($request->user(), $request->only(['expected_branch_id', 'supplier_public_id', 'destination_location_public_id', 'lines']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Purchase Order draft created.');
    }

    public function updatePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        try {
            $service->updateDraft($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version', 'supplier_public_id', 'destination_location_public_id', 'lines']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Purchase Order draft updated.');
    }

    public function submitPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        try {
            $service->submit($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Purchase Order submitted.');
    }

    public function approvePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        try {
            $service->approve($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Purchase Order approved.');
    }

    public function cancelPurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        try {
            $service->cancel($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version', 'reason']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Purchase Order cancelled.');
    }

    public function closePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        try {
            $service->close($request->user(), $purchaseOrder, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Purchase Order closed.');
    }

    public function receivePurchaseOrder(Request $request, PurchaseOrder $purchaseOrder, ProcurementService $service): RedirectResponse
    {
        try {
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
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Goods Receipt posted.');
    }

    public function createStockRequest(Request $request, StockRequestService $service): RedirectResponse
    {
        try {
            $service->create($request->user(), $request->only(['expected_branch_id', 'source_location_public_id', 'destination_location_public_id', 'lines']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stock Request submitted.');
    }

    public function approveStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        try {
            $service->approve($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stock Request approved.');
    }

    public function rejectStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        try {
            $service->reject($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version', 'reason']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stock Request rejected.');
    }

    public function dispatchStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        try {
            $service->dispatch($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version', 'dispatch_idempotency_key', 'lines']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Transfer dispatched.');
    }

    public function receiveStockRequest(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse
    {
        try {
            $service->receive($request->user(), $stockRequest, $request->only(['expected_branch_id', 'lock_version', 'receive_idempotency_key']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Transfer received.');
    }

    public function createStocktake(Request $request, InventoryControlService $service): RedirectResponse
    {
        try {
            $service->createStocktake($request->user(), $request->only(['expected_branch_id', 'location_public_id']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stocktake draft created.');
    }

    public function startStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        try {
            $service->startCounting($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stocktake counting started.');
    }

    public function countStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        try {
            $service->recordCounts($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version', 'lines']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stocktake sent for review.');
    }

    public function postStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        try {
            $service->postStocktake($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stocktake posted.');
    }

    public function cancelStocktake(Request $request, InventoryStocktake $stocktake, InventoryControlService $service): RedirectResponse
    {
        try {
            $service->cancelStocktake($request->user(), $stocktake, $request->only(['expected_branch_id', 'lock_version']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Stocktake cancelled.');
    }

    public function adjustment(Request $request, InventoryControlService $service): RedirectResponse
    {
        try {
            $service->adjust($request->user(), $request->only(['expected_branch_id', 'location_public_id', 'sku_public_id', 'batch_public_id', 'direction', 'quantity', 'reason_code', 'reason_note', 'idempotency_key']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Inventory adjustment posted.');
    }

    public function reorderLevel(Request $request, InventoryControlService $service): RedirectResponse
    {
        try {
            $service->setReorderLevel($request->user(), $request->only(['expected_branch_id', 'location_public_id', 'sku_public_id', 'reorder_level']));
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }

        return $this->success('Reorder level updated.');
    }

    private function success(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('inventory.index');
    }

    /**
     * NAV-01: see InventoryReferenceController::stayOnInventory() - the same
     * fix, for the same reason, following OH-06d. Every action on this
     * controller is submitted only from the Inventory Operations panel.
     */
    private function stayOnInventory(ValidationException $exception): ValidationException
    {
        return $exception->redirectTo(route('inventory.index'));
    }
}
