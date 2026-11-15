<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientIdentifier;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Http\Requests\ReplacePatientIdentifierRequest;
use App\Http\Requests\StorePatientIdentifierRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PatientIdentifierController extends Controller
{
    public function store(StorePatientIdentifierRequest $request, Patient $patient, PatientAdministrationService $administration): RedirectResponse
    {
        $administration->addIdentifier($patient, $request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient identifier added.')]);

        return to_route('patients.show', $patient);
    }

    public function replace(ReplacePatientIdentifierRequest $request, Patient $patient, PatientIdentifier $identifier, PatientAdministrationService $administration): RedirectResponse
    {
        $administration->replaceIdentifier($patient, $identifier, $request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient identifier corrected.')]);

        return to_route('patients.show', $patient);
    }

    public function retire(Request $request, Patient $patient, PatientIdentifier $identifier, PatientAdministrationService $administration): RedirectResponse
    {
        $this->authorize('manageIdentifiers', $patient);
        $administration->retireIdentifier($patient, $identifier, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient identifier retired.')]);

        return to_route('patients.show', $patient);
    }
}
