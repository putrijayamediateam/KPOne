<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\SupplierReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class SupplierReturnController extends Controller
{
    public function index(Request $request, SupplierReturnService $service): Response
    {
        return Inertia::render('Inventory/SupplierReturns', ['returns' => $service->page($request->user(), $request->query())]);
    }

    public function store(Request $request, SupplierReturnService $service): RedirectResponse
    {
        try {
            $service->request($request->user(), $request->only(['expected_branch_id', 'retry_key', 'receipt_lot_id', 'unit', 'quantity', 'reason', 'supplier_reference']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-returns'));
        }

        return to_route('inventory.supplier-returns');
    }

    public function act(Request $request, int $supplierReturn, string $action, SupplierReturnService $service): RedirectResponse
    {
        try {
            $service->act($request->user(), $supplierReturn, $action, $request->only(['expected_branch_id', 'lock_version', 'retry_key', 'reason_code', 'reference', 'credit_amount_sen']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-returns'));
        }

        return to_route('inventory.supplier-returns');
    }

    /** STK-17: return several lots from one delivery in one request. */
    public function storeGroup(Request $request, SupplierReturnService $service): RedirectResponse
    {
        try {
            $service->requestGroup($request->user(), $request->only(['expected_branch_id', 'retry_key', 'goods_receipt_id', 'supplier_reference', 'lines']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-returns', ['delivery' => $request->integer('goods_receipt_id') ?: null]));
        }

        return to_route('inventory.supplier-returns');
    }

    /** STK-17: the whole multi-lot return takes one action together. */
    public function actGroup(Request $request, int $group, string $action, SupplierReturnService $service): RedirectResponse
    {
        try {
            $service->actGroup($request->user(), $group, $action, $request->only(['expected_branch_id', 'lock_version', 'retry_key', 'reason_code', 'reference', 'credit_amount_sen']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-returns'));
        }

        return to_route('inventory.supplier-returns');
    }
}
