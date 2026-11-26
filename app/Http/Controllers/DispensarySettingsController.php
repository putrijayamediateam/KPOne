<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DispensarySettingsReader;
use App\Domain\Clinical\Dispensary\Services\DispensarySettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** DSP-23 (owner answer 12 = A): the branch's "doctor checks prepared medicine" switch. */
class DispensarySettingsController extends Controller
{
    public function show(Request $request, BranchAccessService $branches, DispensarySettingsReader $settings): Response
    {
        $branch = $branches->activeBranch($request->user());
        abort_unless($branch !== null, 404);

        return Inertia::render('Dispensary/Settings', $settings->snapshot($request->user(), $branch));
    }

    public function update(Request $request, DispensarySettingsService $settings): RedirectResponse
    {
        try {
            $row = $settings->setDoctorCheck($request->user(), $request->only(['expected_branch_id', 'doctor_check_required', 'lock_version', 'retry_key']));
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('dispensary-settings.show'));
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $row->doctor_check_required ? 'Doctor\'s check turned on for this branch.' : 'Doctor\'s check turned off for this branch.']);

        return to_route('dispensary-settings.show');
    }
}
