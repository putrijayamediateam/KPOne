<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\StartClinicalEncounterRequest;
use App\Http\Requests\UpdateClinicalEncounterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClinicalEncounterController extends Controller
{
    public function store(
        StartClinicalEncounterRequest $request,
        Visit $visit,
        ClinicalEncounterService $encounters,
    ): RedirectResponse {
        $encounters->start($request->user(), $visit, $request->validated());

        return to_route('encounters.show', $visit);
    }

    public function show(
        Request $request,
        Visit $visit,
        ClinicalEncounterDirectoryService $directory,
    ): Response {
        return Inertia::render('Clinical/Show', [
            'clinical' => $directory->detail($request->user(), $visit),
        ]);
    }

    public function history(
        Request $request,
        Visit $historicalVisit,
        ClinicalEncounterDirectoryService $directory,
    ): Response {
        return Inertia::render('Clinical/HistoryShow', [
            'historical' => $directory->historicalDetail($request->user(), $historicalVisit),
        ]);
    }

    public function update(
        UpdateClinicalEncounterRequest $request,
        Visit $visit,
        ClinicalEncounterService $encounters,
    ): RedirectResponse {
        $encounters->update($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Clinical record saved.')]);

        return to_route('encounters.show', $visit);
    }
}
