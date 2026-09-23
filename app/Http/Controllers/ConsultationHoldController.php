<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ConsultationHoldController extends Controller
{
    public function hold(Request $request, Visit $visit, ConsultationHoldService $holds): RedirectResponse
    {
        try {
            $holds->hold($request->user(), $visit, $request->all());
        } catch (ValidationException $exception) {
            throw $this->stayOnConsultation($visit, $exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Consultation placed On Hold.']);

        return to_route('encounters.show', $visit);
    }

    public function resume(Request $request, Visit $visit, ConsultationHoldService $holds): RedirectResponse
    {
        try {
            $holds->resume($request->user(), $visit, $request->all());
        } catch (ValidationException $exception) {
            throw $this->stayOnConsultation($visit, $exception);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Consultation resumed.']);

        return to_route('encounters.show', $visit);
    }

    /**
     * OH-06d: the doctor is working on this consultation when Hold/Resume is
     * pressed, so both the success path (above) and a blocked attempt must
     * redirect back to this same page - never Laravel's default `back()`
     * resolution, which (for an Inertia SPA where most navigation is
     * client-side XHR, not full page loads) can land on a much older page
     * from earlier in the session rather than the page the request actually
     * came from. A blocked resume still needs a toast, matching the success
     * path, so the doctor is told what happened rather than seeing a silent
     * page reload with only an inline error.
     */
    private function stayOnConsultation(Visit $visit, ValidationException $exception): ValidationException
    {
        $message = array_values($exception->errors())[0][0]
            ?? 'The consultation state could not be changed.';
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return $exception->redirectTo(route('encounters.show', $visit));
    }
}
