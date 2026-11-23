<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\ConsultationReviewService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Director's read-only consultation. GET only (a source test refuses any other verb under this path). The service
 * records the view before it builds anything.
 */
class ConsultationReviewController extends Controller
{
    public function show(Request $request, Visit $historicalVisit, ConsultationReviewService $review): Response|HttpResponse
    {
        // A HEAD request shows nothing, so it records nothing.
        if ($request->isMethod('HEAD')) {
            return response()->noContent();
        }

        return Inertia::render('Clinical/Review', ['review' => $review->open($request->user(), $historicalVisit)]);
    }
}
