<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\Patient\Services\PatientGuardianService;
use App\Domain\Patient\Services\PublicIntakeReviewService;
use App\Domain\Patient\Services\RegistrationSettingsService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\RegisterAndQueueService;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Http\Requests\SearchVisitsRequest;
use App\Http\Requests\StoreVisitRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    public function index(
        Request $request,
        VisitDirectoryService $directory,
        PublicIntakeReviewService $reviews,
    ): Response {
        $this->authorize('viewAny', Visit::class);

        return Inertia::render('Registration/Index', [
            // TRI-1b: after a triage save the CA comes back to the Triage tab.
            'visits' => $directory->search($request->user(), $request->query('tab') === 'triage'
                && $request->user()->can(TriagePermissions::RECORD_BRANCH) ? ['board_status' => 'triage'] : []),
            'options' => $directory->consoleOptions($request->user()),
            'canCreate' => $request->user()->can('create', Visit::class),
            'qrIntakes' => $request->user()->can('public_intakes.review.branch')
                ? $reviews->listing($request->user(), max(1, $request->integer('qr_history_page', 1))) : null,
        ]);
    }

    public function search(SearchVisitsRequest $request, VisitDirectoryService $directory): JsonResponse
    {
        return response()->json($directory->search($request->user(), $request->validated()));
    }

    public function create(Request $request, VisitDirectoryService $directory): Response
    {
        $this->authorize('create', Visit::class);

        return Inertia::render('Registration/Create', [
            'guardianToday' => app(PatientGuardianService::class)->today($request->user()),
            'options' => $directory->formOptions($request->user()),
            'requirements' => app(RegistrationSettingsService::class)->policy($request->user()->organisation_id),
            'canViewEmergencyContact' => $request->user()->can('patients.view.organisation'),
            'canUpdateEmergencyContact' => $request->user()->can('patients.update.organisation'),
            'canCreateEmergencyContact' => $request->user()->can('patients.create.organisation') && $request->user()->can('patients.view.organisation'),
            'recentPatients' => $directory->recentPatients($request->user()),
        ]);
    }

    /**
     * AUTO-1: the doctor Auto would pick right now (name only, never why), so the CA's reason field appears only when the
     * doctor chosen by hand is a different one. Read only; the registration itself decides again under lock.
     */
    public function recommendedDoctor(Request $request, VisitDirectoryService $directory): JsonResponse
    {
        $this->authorize('create', Visit::class);
        $request->validate(['patient_number' => ['nullable', 'string', 'max:20']]);

        return response()->json(['doctor' => $directory->recommendedDoctor($request->user(), $request->query('patient_number'))]);
    }

    /** Register the Visit and put it on the waiting list in one step, then back to the Registration board. */
    public function store(StoreVisitRequest $request, RegisterAndQueueService $registration): RedirectResponse
    {
        $result = $registration->register($request->user(), $request->validated());
        $visit = $result['visit'];
        Inertia::flash('toast', ['type' => 'success', 'message' => $result['queueLabel']
            ? __('Visit :visit registered · :number is waiting', ['visit' => $visit->visit_number, 'number' => $result['queueLabel']])
            : __('Visit registered.')]);

        return $result['queueLabel'] ? to_route('registration.index') : to_route('visits.show', $visit);
    }
}
