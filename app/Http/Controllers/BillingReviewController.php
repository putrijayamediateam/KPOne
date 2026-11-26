<?php

namespace App\Http\Controllers;

use App\Domain\Visit\Billing\Review\BillingReviewService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BIL-37: the Resident Doctor's read-only bill of their own patient's visit. GET only. The service records the view
 * before it builds anything.
 */
class BillingReviewController extends Controller
{
    public function show(Request $request, Visit $visit, BillingReviewService $review): Response|HttpResponse
    {
        // A HEAD request shows nothing, so it records nothing (as the Dispensary review).
        if ($request->isMethod('HEAD')) {
            return response()->noContent();
        }

        return Inertia::render('Billing/Review', [
            'review' => $review->open($request->user(), $visit),
            'backUrl' => match ($request->query('from')) {
                'invoices' => route('visits.invoice', $visit),
                'history' => route('visits.history', $visit),
                default => route('encounters.show', $visit),
            },
        ]);
    }
}
