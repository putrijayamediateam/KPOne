<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryReadinessController extends Controller
{
    public function index(Request $request, InventoryReadinessService $service): Response
    {
        return Inertia::render('Inventory/Readiness', ['readiness' => $service->page($request->user(), $request->all())]);
    }

    public function verify(Request $request, string $kind, string $key, InventoryReadinessService $service): RedirectResponse
    {
        $service->verify($request->user(), $kind, $key, $request->all());

        return to_route('inventory.readiness');
    }
}
