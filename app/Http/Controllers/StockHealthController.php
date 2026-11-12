<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\StockHealthService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** STK-33: read-only stock health. The rules live in StockHealthService. */
final class StockHealthController extends Controller
{
    public function index(Request $request, StockHealthService $service): Response
    {
        return Inertia::render('Inventory/StockHealth', ['health' => $service->page($request->user(), $request->query())]);
    }
}
