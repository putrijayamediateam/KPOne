<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\SaveTreatmentPlanRequest;
use App\Http\Requests\SendTreatmentPlanToDispensaryRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class TreatmentPlanController extends Controller
{
    public function send(SendTreatmentPlanToDispensaryRequest $request, Visit $visit, DispensaryHandoffService $handoff): RedirectResponse
    {
        $handoff->send($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Treatment Plan sent to Dispensary.')]);

        return to_route('queue.index');
    }

    public function save(SaveTreatmentPlanRequest $request, Visit $visit, TreatmentPlanService $plans): RedirectResponse
    {
        $plans->save($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Treatment Plan saved.')]);

        return to_route('encounters.show', $visit);
    }
}
