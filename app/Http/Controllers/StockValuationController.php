<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\StockJournalSummaryService;
use App\Domain\Organisation\Inventory\Services\StockValuationReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
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
            // A refused filter shows the default period, with its reason on the page (no redirect, so nothing is lost).
            $report = [...$service->report($request->user(), $branch, []), 'refused' => array_values(array_map(fn (array $messages): string => (string) $messages[0], $e->errors()))];
        }

        return Inertia::render('Inventory/StockValue', ['report' => $report]);
    }

    /** STK-29: the monthly journal summary CSV (Director only). Nothing is kept on the server. */
    public function journal(Request $request, StockJournalSummaryService $service, BranchAccessService $branches): HttpResponse
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id && $branches->hasEffectiveAssignment($request->user(), $branch), 404);
        $file = $service->csv($request->user(), $branch, $request->only(['branch', 'month']));

        return response($file['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
