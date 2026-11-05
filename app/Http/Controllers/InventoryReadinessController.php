<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryReadinessController extends Controller
{
    public function index(Request $request, InventoryReadinessService $service): Response
    {
        return Inertia::render('Inventory/Readiness', ['readiness' => $service->page($request->user(), $request->all())]);
    }

    public function barcode(Request $request, string $sku, InventoryReadinessService $service): RedirectResponse
    {
        try {
            $service->changeBarcode($request->user(), $sku, $request->only(['barcode']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.readiness'));
        }

        return to_route('inventory.readiness');
    }

    public function verify(Request $request, string $kind, string $key, InventoryReadinessService $service): RedirectResponse
    {
        $service->verify($request->user(), $kind, $key, $request->all());

        return to_route('inventory.readiness');
    }
}
