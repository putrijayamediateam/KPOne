<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\MedicineSalesReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** STK-44: the medicine sales page (Director and Finance Officer; KPOne defaults, not accounting advice). */
final class MedicineSalesController extends Controller
{
    public function __invoke(Request $request, MedicineSalesReportService $service, BranchAccessService $branches): Response
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null && $branch->organisation_id === $request->user()->organisation_id && $branches->hasEffectiveAssignment($request->user(), $branch), 404);
        try {
            $report = $service->report($request->user(), $branch, $request->only(['month', 'branch', 'rank', 'below_page']));
        } catch (ValidationException $e) {
            // A refused filter shows the default view, with its reason on the page (no redirect, so nothing is lost).
            $report = [...$service->report($request->user(), $branch, []), 'refused' => array_values(array_map(fn (array $messages): string => (string) $messages[0], $e->errors()))];
        }

        return Inertia::render('Inventory/MedicineSales', ['report' => $report]);
    }
}
