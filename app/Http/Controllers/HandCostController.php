<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\HandCostPageService;
use App\Domain\Organisation\Inventory\Services\HandCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class HandCostController extends Controller
{
    public function index(Request $request, HandCostPageService $service): Response
    {
        return Inertia::render('Inventory/HandCosts', ['costs' => $service->page($request->user(), $request->query())]);
    }

    public function store(Request $request, HandCostService $service): RedirectResponse
    {
        $service->enter($request->user(), $request->all());

        return to_route('inventory.hand-costs.index')->with('success', 'Cost sent for a second person to check.');
    }

    public function decide(Request $request, string $handCost, HandCostService $service): RedirectResponse
    {
        $service->decide($request->user(), $handCost, $request->all());

        return to_route('inventory.hand-costs.index')->with('success', 'Cost decision recorded.');
    }
}
