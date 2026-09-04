<?php

namespace App\Http\Controllers;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConsultationCheckoutController extends Controller
{
    public function complete(Request $request, Visit $visit, CompleteConsultationService $service): RedirectResponse
    {
        $checkout = $service->complete($request->user(), $visit, $request->only(['expected_branch_id', 'visit_lock_version', 'queue_lock_version', 'encounter_lock_version', 'lock_version', 'service_deliveries']));
        Inertia::flash('toast', ['type' => 'success', 'message' => $checkout->route === 'billing' ? 'Consultation completed. Awaiting Billing.' : 'Consultation completed. Sent to Dispensary.']);

        return to_route('queue.index');
    }

    public function reopen(Request $request, Visit $visit, ReopenConsultationCheckoutService $service): RedirectResponse
    {
        $service->reopen($request->user(), $visit, $request->only(['expected_branch_id', 'checkout_lock_version', 'visit_lock_version']));

        return to_route('encounters.show', $visit);
    }
}
