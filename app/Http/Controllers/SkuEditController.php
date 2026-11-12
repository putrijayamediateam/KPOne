<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\SkuEditDirectoryService;
use App\Domain\Organisation\Inventory\Services\SkuEditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-14: the general SKU edit screen. Business rules live in the domain services. */
final class SkuEditController extends Controller
{
    public function index(Request $request, SkuEditDirectoryService $directory): Response
    {
        return Inertia::render('Inventory/Skus', ['skus' => $directory->page($request->user(), $request->query())]);
    }

    public function update(Request $request, string $sku, SkuEditService $service): RedirectResponse
    {
        try {
            $service->save($request->user(), $sku, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.skus'));
        }

        return to_route('inventory.skus')->with('success', 'SKU saved.');
    }
}
