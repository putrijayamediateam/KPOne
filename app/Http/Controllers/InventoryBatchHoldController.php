<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InventoryBatchHoldController extends Controller
{
    public function index(Request $request, InventoryBatchHoldService $service): Response
    {
        return Inertia::render('Inventory/BatchHolds', ['holds' => $service->page($request->user(), $request->all())]);
    }

    public function store(Request $request, string $batch, string $action, InventoryBatchHoldService $service): RedirectResponse
    {
        try {
            $service->act($request->user(), $batch, $action, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.batch-holds'));
        }

        return to_route('inventory.batch-holds');
    }
}
