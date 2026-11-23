<?php

namespace App\Http\Controllers\HR;

use App\Domain\HR\LabourCost\Services\LabourCostService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** HR-18 labour cost. Thin and read-only: every rule lives in app/Domain/HR/LabourCost. */
class LabourCostController extends Controller
{
    public function index(Request $request, LabourCostService $labourCost): Response
    {
        return Inertia::render('HR/LabourCost', ['labourCost' => $labourCost->page($request->user(), $request->only(['month']))]);
    }
}
