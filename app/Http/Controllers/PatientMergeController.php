<?php

namespace App\Http\Controllers;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Patient\Services\PatientMergeDirectoryService;
use App\Domain\Patient\Services\PatientMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** REG-3: two-person patient merge screens and actions. Business rules live in PatientMergeService. */
class PatientMergeController extends Controller
{
    public function compare(Request $request, Patient $patient, PatientMergeDirectoryService $directory): JsonResponse
    {
        $validated = $request->validate(['other_patient_number' => ['required', 'string', 'max:20']]);

        return response()->json($directory->compare($request->user(), $patient, $validated['other_patient_number']))
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, Patient $patient, PatientMergeService $merges): RedirectResponse
    {
        try {
            $merges->propose($request->user(), $patient, $request->only([
                'kind', 'other_patient_number', 'reason_code', 'expected_branch_id', 'kept_lock_version', 'other_lock_version', 'idempotency_key',
            ]));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('patients.show', $patient));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request sent. A different CA Supervisor or Director must approve it.')]);

        return to_route('patients.show', $patient);
    }

    public function index(Request $request, PatientMergeDirectoryService $directory): Response
    {
        return Inertia::render('Patient/MergeRequests', ['requests' => $directory->inbox($request->user())]);
    }

    public function approve(Request $request, PatientMergeRequest $mergeRequest, PatientMergeService $merges): RedirectResponse
    {
        return $this->decide($request, fn () => $merges->approve($request->user(), $mergeRequest, $this->decision($request)), 'Request approved.');
    }

    public function reject(Request $request, PatientMergeRequest $mergeRequest, PatientMergeService $merges): RedirectResponse
    {
        return $this->decide($request, fn () => $merges->reject($request->user(), $mergeRequest, $this->decision($request)), 'Request rejected.');
    }

    public function withdraw(Request $request, PatientMergeRequest $mergeRequest, PatientMergeService $merges): RedirectResponse
    {
        return $this->decide($request, fn () => $merges->withdraw($request->user(), $mergeRequest, $this->decision($request)), 'Request withdrawn.');
    }

    /** @return array<string, mixed> */
    private function decision(Request $request): array
    {
        return $request->only(['expected_branch_id', 'lock_version']);
    }

    private function decide(Request $request, callable $action, string $message): RedirectResponse
    {
        $number = $request->input('patient_number');
        $back = $request->input('from') === 'patient' && is_string($number) && preg_match('/^KP-\d{1,12}$/', $number) === 1
            ? route('patients.show', ['patient' => $number])
            : route('patient-merges.index');
        try {
            $action();
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($back);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __($message)]);

        return redirect()->to($back);
    }
}
