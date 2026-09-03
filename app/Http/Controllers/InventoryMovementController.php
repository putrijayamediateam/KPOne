<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Http\Requests\InventoryOpeningBalanceRequest;
use App\Http\Requests\InventoryTransferRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class InventoryMovementController extends Controller
{
    public function openingBalance(InventoryOpeningBalanceRequest $request, InventoryMovementService $service): RedirectResponse
    {
        $service->openingBalance($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Opening balance recorded.']);

        return back();
    }

    public function transfer(InventoryTransferRequest $request, InventoryMovementService $service): RedirectResponse
    {
        $service->transfer($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stock transferred.']);

        return back();
    }
}
