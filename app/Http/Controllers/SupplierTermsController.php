<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\SupplierTermsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-13: the supplier price list and lead time per SKU. Business rules live in SupplierTermsService. */
final class SupplierTermsController extends Controller
{
    private const FIELDS = ['expected_branch_id', 'idempotency_key', 'pack_description', 'pack_quantity', 'pack_price_sen', 'lead_time_days', 'minimum_order_packs', 'is_preferred'];

    public function index(Request $request, SupplierTermsService $service): Response
    {
        return Inertia::render('Inventory/SupplierTerms', ['terms' => $service->page($request->user(), $request->query())]);
    }

    public function store(Request $request, SupplierTermsService $service): RedirectResponse
    {
        try {
            $service->create($request->user(), $request->only([...self::FIELDS, 'supplier_public_id', 'sku_public_id']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-terms'));
        }

        return to_route('inventory.supplier-terms');
    }

    public function update(Request $request, string $term, SupplierTermsService $service): RedirectResponse
    {
        try {
            $service->update($request->user(), $term, $request->only([...self::FIELDS, 'lock_version', 'is_active']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.supplier-terms'));
        }

        return to_route('inventory.supplier-terms');
    }
}
