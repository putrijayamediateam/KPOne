<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\DifferenceReportService;
use App\Domain\Organisation\Inventory\Services\InventoryStockReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class InventoryStockReviewController extends Controller
{
    public function index(Request $request, InventoryStockReviewService $service, BranchAccessService $branches): Response
    {
        // STK-42: the at-risk section (with value for cost viewers) comes only from here, never from page().
        $review = $service->page($request->user(), $request->query());
        // STK-45: Report a difference on Expiring and Expired rows (one batch each), never on Low stock rows. Attached
        // here, not in page(), so the Pharmacy and Ask spaces that also read page() never receive it.
        $differences = app(DifferenceReportService::class)->forRows($request->user(), $branches->activeBranch($request->user()), array_values(array_map(
            fn (array $row): int => (int) $row['item_id'],
            [...$review['lists']['expiring']['rows'], ...$review['lists']['expired']['rows']],
        )));
        foreach (['expiring', 'expired'] as $kind) {
            foreach ($review['lists'][$kind]['rows'] as $i => $row) {
                if (isset($differences[(int) $row['item_id']])) {
                    $review['lists'][$kind]['rows'][$i]['difference'] = $differences[(int) $row['item_id']];
                }
            }
        }

        // STK-45: the Report a difference sheet, for P10 holders only.
        return Inertia::render('Inventory/StockReview', ['review' => $review, 'atRisk' => $service->atRisk($request->user(), $request->query()),
            'differences' => $request->user()->can(DifferenceReportService::PERMISSION) ? ['canReport' => true, 'expectedBranchId' => $review['branchId']] : ['canReport' => false]]);
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
