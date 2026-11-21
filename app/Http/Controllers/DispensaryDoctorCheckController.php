<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryDoctorCheckDirectory;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Http\Requests\DispensaryDoctorCheckRequest;
use App\Http\Requests\SendForDoctorCheckRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** DSP-23: the doctor checks prepared medicine before payment (owner decision 14; answers 10 to 13). */
class DispensaryDoctorCheckController extends Controller
{
    public function send(SendForDoctorCheckRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        try {
            $service->sendForDoctorCheck($request->user(), $dispensaryCase, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('dispensary.show', $dispensaryCase));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent to the doctor for check.']);

        return to_route('dispensary.show', $dispensaryCase);
    }

    public function verify(DispensaryDoctorCheckRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        try {
            $service->verifyDoctorCheck($request->user(), $dispensaryCase, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($request->returnUrl());
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Medicine verified and released to billing.']);

        return $this->after($request, $dispensaryCase);
    }

    public function sendBack(DispensaryDoctorCheckRequest $request, DispensaryCase $dispensaryCase, DispensaryService $service): RedirectResponse
    {
        try {
            $service->sendBackDoctorCheck($request->user(), $dispensaryCase, $request->validated());
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($request->returnUrl());
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sent back to the CA.']);

        return $this->after($request, $dispensaryCase);
    }

    public function waiting(Request $request, BranchAccessService $branches, DispensaryDoctorCheckDirectory $directory): Response
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null, 404);

        return Inertia::render('Dispensary/DoctorChecks', [
            'waiting' => $directory->waiting($request->user(), $branch),
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
        ]);
    }

    private function after(DispensaryDoctorCheckRequest $request, DispensaryCase $dispensaryCase): RedirectResponse
    {
        return $request->user()->hasRole('resident_doctor')
            ? to_route('encounters.show', $dispensaryCase->visit()->firstOrFail())
            : to_route('dispensary.doctor-checks');
    }
}
