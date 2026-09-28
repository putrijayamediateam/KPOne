<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Http\Requests\InventoryOpeningBalanceRequest;
use App\Http\Requests\InventoryTransferRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class InventoryMovementController extends Controller
{
    public function openingBalance(InventoryOpeningBalanceRequest $request, InventoryMovementService $service): RedirectResponse
    {
        try {
            $service->openingBalance($request->user(), $request->validated());
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Opening balance recorded.']);

        return to_route('inventory.index');
    }

    public function transfer(InventoryTransferRequest $request, InventoryMovementService $service): RedirectResponse
    {
        try {
            $service->transfer($request->user(), $request->validated());
        } catch (ValidationException $exception) {
            throw $this->stayOnInventory($exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stock transferred.']);

        return to_route('inventory.index');
    }

    /**
     * NAV-01: see InventoryReferenceController::stayOnInventory() - the same
     * fix, for the same reason, following OH-06d.
     */
    private function stayOnInventory(ValidationException $exception): ValidationException
    {
        return $exception->redirectTo(route('inventory.index'));
    }
}
