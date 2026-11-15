<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\SupplierInvoiceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-22: supplier invoices matched against what was ordered and received. KPOne pays nothing. */
final class SupplierInvoiceController extends Controller
{
    public function index(Request $request, SupplierInvoiceService $service, BranchAccessService $branches): Response
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id, 404);

        return Inertia::render('Inventory/SupplierInvoices', ['invoices' => $service->page($request->user(), $branch, $request->query())]);
    }

    public function store(Request $request, SupplierInvoiceService $service): RedirectResponse
    {
        try {
            $service->record($request->user(), $request->only(['expected_branch_id', 'idempotency_key', 'purchase_order_public_id', 'invoice_number', 'invoice_date', 'stated_total', 'lines']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-invoices'));
        }

        return to_route('inventory.supplier-invoices')->with('success', 'Invoice recorded.');
    }

    public function decide(Request $request, string $supplierInvoice, string $decision, SupplierInvoiceService $service): RedirectResponse
    {
        try {
            $service->decide($request->user(), $supplierInvoice, $decision, $request->only(['expected_branch_id', 'lock_version', 'reason', 'note']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-invoices'));
        } catch (AuthorizationException $e) {
            // The recorder is refused on the page with the reason, not a bare 403.
            throw ValidationException::withMessages(['status' => $e->getMessage()])->redirectTo(route('inventory.supplier-invoices'));
        }

        return to_route('inventory.supplier-invoices', ['tab' => $decision])->with('success', $decision === 'accepted' ? 'Invoice accepted.' : 'Invoice rejected.');
    }
}
