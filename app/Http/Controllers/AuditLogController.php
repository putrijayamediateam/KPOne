<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\PatientProblemRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $paginator = AuditLog::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->with(['actor:id,name', 'branch:id,code,name'])
            ->latest('occurred_at')
            ->paginate(25);
        $logs = [
            'data' => $paginator->getCollection()->map(function (AuditLog $log): array {
                $isFinancialEvent = str_starts_with($log->event, 'billing.');
                $isClinicalSafetyEvent = $isFinancialEvent || in_array($log->event, [
                    'allergy_profile.updated',
                    'allergy_record.created',
                    'allergy_record.updated',
                    'allergy_record.entered_in_error',
                    'encounter.allergy_reviewed',
                    'problem.created',
                    'problem.updated',
                    'problem.resolved',
                    'problem.entered_in_error',
                    'treatment_plan.created',
                    'treatment_plan.updated',
                    'treatment_plan.sent_to_dispensary',
                    'dispensary.started',
                    'dispensary.updated',
                    'dispensary.returned_to_doctor',
                    'dispensary.partial_acknowledged',
                    'dispensary.completed',
                    'inventory.dispensed',
                    'consultation.checked_out',
                    'consultation.checkout_reopened',
                ], true);
                $isPatient = $log->subject_type === (new Patient)->getMorphClass();
                $isVisit = $log->subject_type === (new Visit)->getMorphClass();
                $isQueue = $log->subject_type === (new QueueEntry)->getMorphClass();
                $isEncounter = $log->subject_type === (new ClinicalEncounter)->getMorphClass();
                $isAllergyProfile = $log->subject_type === (new PatientAllergyProfile)->getMorphClass();
                $isAllergyRecord = $log->subject_type === (new PatientAllergyRecord)->getMorphClass();
                $isProblemRecord = $log->subject_type === (new PatientProblemRecord)->getMorphClass();
                $isTreatmentPlan = $log->subject_type === (new TreatmentPlan)->getMorphClass();
                $isDispensaryCase = $log->subject_type === (new DispensaryCase)->getMorphClass();
                $isStockMovement = $log->subject_type === (new StockMovement)->getMorphClass();
                $isCheckout = $log->subject_type === (new ConsultationCheckout)->getMorphClass();
                $isPublicCheckInLink = $log->subject_type === (new PublicCheckInLink)->getMorphClass();
                $isProtectedOperationalRecord = $isFinancialEvent || $isCheckout || $isPatient || $isVisit || $isQueue || $isEncounter
                    || $isAllergyProfile || $isAllergyRecord || $isProblemRecord || $isTreatmentPlan
                    || $isDispensaryCase || $isStockMovement;
                $isProtectedOperationalRecord = $isProtectedOperationalRecord || $isPublicCheckInLink;
                $subjectType = match (true) {
                    $isFinancialEvent => 'Financial record',
                    $isCheckout => 'Clinical checkout record',
                    $isPatient => 'Patient record',
                    $isVisit => 'Visit record',
                    $isQueue => 'Queue record',
                    $isEncounter => 'Clinical record',
                    $isAllergyProfile, $isAllergyRecord => 'Clinical allergy record',
                    $isProblemRecord => 'Clinical problem record',
                    $isTreatmentPlan => 'Treatment Plan record',
                    $isDispensaryCase => 'Dispensary record',
                    $isStockMovement => 'Inventory movement',
                    $isPublicCheckInLink => 'Public check-in link',
                    default => $log->subject_type ? class_basename($log->subject_type) : null,
                };

                return [
                    'id' => $log->id,
                    'event' => $log->event,
                    'actor' => $isClinicalSafetyEvent
                        ? ($isFinancialEvent ? 'Authorized user' : 'Clinical user')
                        : ($log->actor instanceof User ? $log->actor->name : 'System'),
                    'branch' => $log->branch?->code,
                    'subjectType' => $subjectType,
                    'subjectId' => $isProtectedOperationalRecord ? null : $log->subject_id,
                    'occurredAt' => $log->occurred_at->toIso8601String(),
                    'roleNames' => $isClinicalSafetyEvent ? [] : $log->roleNames(),
                ];
            })->values(),
            'total' => $paginator->total(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];

        return Inertia::render('AuditLogs/Index', ['logs' => $logs]);
    }
}
