<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Triage\TriageViewProjection;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TRI-1e: the patient's own doctor's read-only triage view. GET only. The domain checks the permission, the branch and
 * the own doctor, records the open, and only then builds the page.
 */
class TriageViewController extends Controller
{
    public function show(Request $request, Visit $visit, TriageViewProjection $projection): Response|HttpResponse
    {
        // A HEAD request shows nothing, so it records nothing.
        if ($request->isMethod('HEAD')) {
            return response()->noContent();
        }

        return Inertia::render('Triage/View', $projection->open($request->user(), $visit));
    }
}
