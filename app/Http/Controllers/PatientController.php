<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientChangeHistoryService;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Patient\Services\PatientGuardianService;
use App\Domain\Patient\Services\PatientMergeDirectoryService;
use App\Domain\Patient\Services\PatientMergeLinks;
use App\Domain\Patient\Services\RegistrationSettingsService;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use Illuminate\Http\JsonResponse;
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
            'canSeeMergeRequests' => $request->user()->canAny(['patients.merge.propose.organisation', 'patients.merge.approve.organisation']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Patient::class);

        return Inertia::render('Patient/Create', ['guardianToday' => app(PatientGuardianService::class)->today($request->user()), 'requirements' => app(RegistrationSettingsService::class)->policy($request->user()->organisation_id)]);
    }

    public function store(StorePatientRequest $request, PatientAdministrationService $administration): RedirectResponse
    {
        $patient = $administration->create($request->user(), $request->validated());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient record created.')]);

        return to_route('patients.show', $patient);
    }

    public function show(Request $request, Patient $patient, PatientDirectoryService $directory, PatientMergeDirectoryService $merges): Response|RedirectResponse
    {
        $this->authorize('view', $patient);
        if ($patient->isMergedAway()) {
            // REG-3: the merged-away record is hidden; its page opens the kept record.
            $kept = PatientMergeLinks::kept($patient);
            Inertia::flash('toast', ['type' => 'info', 'message' => __(':old was merged into :kept.', ['old' => $patient->patient_number, 'kept' => $kept->patient_number])]);

            return to_route('patients.show', $kept);
        }

        return Inertia::render('Patient/Show', [
            'patient' => $directory->detail($request->user(), $patient),
            'merge' => $merges->panel($request->user(), $patient),
            'history' => $request->user()->can(PatientChangeHistoryService::PERMISSION)
                ? app(PatientChangeHistoryService::class)->page($request->user(), $patient, null, PatientChangeHistoryService::FIRST_PAGE)
                : null,
        ]);
    }

    /** REG-4: older change-history rows for the "Show older changes" button. */
    public function history(Request $request, Patient $patient, PatientChangeHistoryService $history): JsonResponse
    {
        $this->authorize('view', $patient);
        $validated = $request->validate(['cursor' => ['nullable', 'string', 'max:512']]);
        $kept = $patient->isMergedAway() ? PatientMergeLinks::kept($patient) : $patient;

        return response()->json($history->page($request->user(), $kept, $validated['cursor'] ?? null))
            ->header('Cache-Control', 'no-store, private');
    }

    public function edit(Request $request, Patient $patient, PatientDirectoryService $directory): Response|RedirectResponse
    {
        $this->authorize('update', $patient);
        if ($patient->isMergedAway()) {
            return to_route('patients.show', $patient);
        }

        return Inertia::render('Patient/Edit', ['requirements' => app(RegistrationSettingsService::class)->policy($request->user()->organisation_id), 'guardianToday' => app(PatientGuardianService::class)->today($request->user()), 'patient' => $directory->detail($request->user(), $patient)]);
    }

    public function update(UpdatePatientRequest $request, Patient $patient, PatientAdministrationService $administration): RedirectResponse
    {
        $administration->update($patient, $request->validated(), $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Patient record updated.')]);

        return to_route('patients.show', $patient);
    }
}
