<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryReviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DSP-25: the Director's read-only dispensary case. GET only (a source test refuses any other verb under this path).
 * The service records the view before it builds anything.
 */
class DispensaryReviewController extends Controller
{
    public function show(Request $request, DispensaryCase $dispensaryCase, DispensaryReviewService $review): Response
    {
        return Inertia::render('Dispensary/Review', ['review' => $review->open($request->user(), $dispensaryCase)]);
    }
}
