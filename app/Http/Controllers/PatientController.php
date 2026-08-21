<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PatientController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('search', Patient::class);

        return Inertia::render('Patient/Index', [
            'canCreate' => $request->user()->can('create', Patient::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Patient::class);

        return Inertia::render('Patient/Create');
    }

    public function store(StorePatientRequest $request, PatientAdministrationService $administration): RedirectResponse
    {
        $patient = $administration->create($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient record created.')]);

        return to_route('patients.show', $patient);
    }

    public function show(Request $request, Patient $patient, PatientDirectoryService $directory): Response
    {
        return Inertia::render('Patient/Show', ['patient' => $directory->detail($request->user(), $patient)]);
    }

    public function edit(Request $request, Patient $patient, PatientDirectoryService $directory): Response
    {
        $this->authorize('update', $patient);

        return Inertia::render('Patient/Edit', ['patient' => $directory->detail($request->user(), $patient)]);
    }

    public function update(UpdatePatientRequest $request, Patient $patient, PatientAdministrationService $administration): RedirectResponse
    {
        $administration->update($patient, $request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient record updated.')]);

        return to_route('patients.show', $patient);
    }
}
