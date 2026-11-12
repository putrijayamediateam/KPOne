<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\CycleCountService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GovernedStockController extends Controller
{
    public function counts(Request $request, GovernedStockService $service, CycleCountService $cycle, BranchAccessService $branches): Response
    {
        $stock = $service->page($request->user(), 'count', $request->query());
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null, 403);

        // STK-23: counts due by ABC class, after page() has checked the viewer and the branch.
        return Inertia::render('Inventory/GovernedStock', ['stock' => [...$stock, 'cycle' => $cycle->due($request->user(), $branch, max(1, $request->integer('cycle_page', 1)))]]);
    }

    /** STK-23: open STK-5 count cases for every batch of a SKU at a location. */
    public function startCycleCount(Request $request, CycleCountService $cycle): RedirectResponse
    {
        try {
            $opened = $cycle->start($request->user(), $request->only(['expected_branch_id', 'retry_key', 'sku_public_id', 'location_public_id']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.governed-counts'));
        }

        return to_route('inventory.governed-counts')->with('success', $opened === 0 ? 'A count is already open for every batch here.' : ($opened === 1 ? '1 batch is ready to count.' : "{$opened} batches are ready to count."));
    }

    public function writeoffs(Request $request, GovernedStockService $service): Response
    {
        return Inertia::render('Inventory/GovernedStock', ['stock' => $service->page($request->user(), 'writeoff', $request->query())]);
    }

    public function store(Request $request, string $kind, GovernedStockService $service): RedirectResponse
    {
        $destination = $kind === 'count' ? 'inventory.governed-counts' : 'inventory.governed-writeoffs';
        try {
            $service->propose($request->user(), $kind, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route($destination));
        }

        return to_route($destination);
    }

    public function act(Request $request, int $governedCase, string $action, GovernedStockService $service): RedirectResponse
    {
        $destination = $request->input('return_to') === 'writeoff' ? 'inventory.governed-writeoffs' : 'inventory.governed-counts';
        try {
            $service->act($request->user(), $governedCase, $action, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route($destination));
        }

        return to_route($destination);
    }
}
