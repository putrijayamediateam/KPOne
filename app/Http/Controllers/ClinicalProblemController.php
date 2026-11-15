<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\PatientProblemService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\ManageProblemRecordRequest;
use App\Http\Requests\TransitionProblemRecordRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ClinicalProblemController extends Controller
{
    public function store(
        ManageProblemRecordRequest $request,
        Visit $visit,
        PatientProblemService $problems,
    ): RedirectResponse {
        $problems->add($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Problem recorded.')]);

        return to_route('encounters.show', $visit);
    }

    public function update(
        ManageProblemRecordRequest $request,
        Visit $visit,
        string $problem,
        PatientProblemService $problems,
    ): RedirectResponse {
        $problems->update($request->user(), $visit, $problem, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Problem updated.')]);

        return to_route('encounters.show', $visit);
    }

    public function resolve(
        TransitionProblemRecordRequest $request,
        Visit $visit,
        string $problem,
        PatientProblemService $problems,
    ): RedirectResponse {
        $problems->resolve($request->user(), $visit, $problem, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Problem marked as resolved.')]);

        return to_route('encounters.show', $visit);
    }

    public function enterInError(
        TransitionProblemRecordRequest $request,
        Visit $visit,
        string $problem,
        PatientProblemService $problems,
    ): RedirectResponse {
        $problems->enterInError($request->user(), $visit, $problem, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Problem marked as entered in error.')]);

        return to_route('encounters.show', $visit);
    }
}
