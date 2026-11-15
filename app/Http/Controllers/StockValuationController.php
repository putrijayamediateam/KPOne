<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\StockValuationReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-28: stock value, cost of goods sold, margin and losses (Director only; KPOne defaults, not accounting advice). */
final class StockValuationController extends Controller
{
    public function __invoke(Request $request, StockValuationReportService $service, BranchAccessService $branches): Response
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id && $branches->hasEffectiveAssignment($request->user(), $branch), 404);
        try {
            $report = $service->report($request->user(), $branch, $request->only(['branch', 'from', 'to']));
        } catch (ValidationException $e) {
            // A refused filter goes back to the default period with its reason, never to another page.
            throw $e->redirectTo(route('inventory.stock-value'));
        }

        return Inertia::render('Inventory/StockValue', ['report' => $report]);
    }
}
