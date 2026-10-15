<?php

namespace App\Http\Controllers;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitHistoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VisitHistoryController extends Controller
{
    public function invoice(Request $request, Visit $visit, VisitHistoryService $history): Response
    {
        return Inertia::render('Billing/Invoice', [
            'invoice' => $history->invoiceDetail($request->user(), $visit),
        ]);
    }

    public function __invoke(
        Request $request,
        Visit $visit,
        VisitHistoryService $history,
        BranchAccessService $branches,
    ): Response {
        $actor = $request->user();
        $returnVisitNumber = $request->query('from_visit');
        $branch = $branches->activeBranch($actor);
        $consultationReturnVisit = is_string($returnVisitNumber) && $branch
            ? Visit::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('branch_id', $branch->id)
                ->where('patient_id', $visit->patient_id)
                ->where('visit_number', $returnVisitNumber)
                ->where('assigned_doctor_user_id', $actor->id)
                ->where('visit_type', 'consultation')
                ->where('status', Visit::STATUS_REGISTERED)
                ->whereHas('queueEntry', fn ($queue) => $queue->where('status', QueueEntry::STATUS_SERVING))
                ->whereHas('clinicalEncounter', fn ($encounter) => $encounter
                    ->where('attending_clinician_user_id', $actor->id)
                    ->where('status', ClinicalEncounter::STATUS_IN_PROGRESS))
                ->first()
            : null;

        return Inertia::render('Visits/History', [
            'history' => $history->detail($actor, $visit, $consultationReturnVisit),
        ]);
    }
}
