<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class GovernedStockController extends Controller
{
    public function counts(Request $request, GovernedStockService $service): Response
    {
        return Inertia::render('Inventory/GovernedStock', ['stock' => $service->page($request->user(), 'count', $request->query())]);
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
