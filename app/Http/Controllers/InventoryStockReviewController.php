<?php

namespace App\Http\Controllers;

use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class InventoryStockReviewController extends Controller
{
    public function index(Request $request, InventoryStockReviewService $service): Response
    {
        return Inertia::render('Inventory/StockReview', ['review' => $service->page($request->user(), $request->query())]);
    }

    public function store(Request $request, string $kind, int $reviewItem, InventoryStockReviewService $service): RedirectResponse
    {
        try {
            $service->acknowledge($request->user(), $kind, $reviewItem, $request->all());
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('inventory.stock-review'));
        }

        return to_route('inventory.stock-review');
    }
}
