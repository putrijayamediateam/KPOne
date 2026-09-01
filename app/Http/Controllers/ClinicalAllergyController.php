<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Visit\Models\Visit;
use App\Http\Requests\AllergyProfileVersionRequest;
use App\Http\Requests\ManageAllergyRequest;
use App\Http\Requests\ReviewEncounterAllergiesRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ClinicalAllergyController extends Controller
{
    public function declareNoKnown(
        AllergyProfileVersionRequest $request,
        Visit $visit,
        PatientAllergyService $allergies,
    ): RedirectResponse {
        $allergies->declareNoKnown($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('No known allergies recorded. Review it for this consultation.')]);

        return to_route('encounters.show', $visit);
    }

    public function store(
        ManageAllergyRequest $request,
        Visit $visit,
        PatientAllergyService $allergies,
    ): RedirectResponse {
        $allergies->add($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allergy recorded. Review the updated profile for this consultation.')]);

        return to_route('encounters.show', $visit);
    }

    public function update(
        ManageAllergyRequest $request,
        Visit $visit,
        string $allergy,
        PatientAllergyService $allergies,
    ): RedirectResponse {
        $allergies->update($request->user(), $visit, $allergy, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allergy updated. Review the updated profile for this consultation.')]);

        return to_route('encounters.show', $visit);
    }

    public function enterInError(
        AllergyProfileVersionRequest $request,
        Visit $visit,
        string $allergy,
        PatientAllergyService $allergies,
    ): RedirectResponse {
        $allergies->enterInError($request->user(), $visit, $allergy, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allergy marked as entered in error. Review the current profile again.')]);

        return to_route('encounters.show', $visit);
    }

    public function review(
        ReviewEncounterAllergiesRequest $request,
        Visit $visit,
        PatientAllergyService $allergies,
    ): RedirectResponse {
        $allergies->review($request->user(), $visit, $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Allergy Profile reviewed for this consultation.')]);

        return to_route('encounters.show', $visit);
    }
}
