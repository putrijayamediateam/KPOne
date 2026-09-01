<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Models\PatientProblemRecord;
use App\Domain\Clinical\Models\TreatmentPlan;
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
                $isClinicalSafetyEvent = in_array($log->event, [
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
                ], true);
                $isPatient = $log->subject_type === (new Patient)->getMorphClass();
                $isVisit = $log->subject_type === (new Visit)->getMorphClass();
                $isQueue = $log->subject_type === (new QueueEntry)->getMorphClass();
                $isEncounter = $log->subject_type === (new ClinicalEncounter)->getMorphClass();
                $isAllergyProfile = $log->subject_type === (new PatientAllergyProfile)->getMorphClass();
                $isAllergyRecord = $log->subject_type === (new PatientAllergyRecord)->getMorphClass();
                $isProblemRecord = $log->subject_type === (new PatientProblemRecord)->getMorphClass();
                $isTreatmentPlan = $log->subject_type === (new TreatmentPlan)->getMorphClass();
                $isProtectedOperationalRecord = $isPatient || $isVisit || $isQueue || $isEncounter
                    || $isAllergyProfile || $isAllergyRecord || $isProblemRecord || $isTreatmentPlan;
                $subjectType = match (true) {
                    $isPatient => 'Patient record',
                    $isVisit => 'Visit record',
                    $isQueue => 'Queue record',
                    $isEncounter => 'Clinical record',
                    $isAllergyProfile, $isAllergyRecord => 'Clinical allergy record',
                    $isProblemRecord => 'Clinical problem record',
                    $isTreatmentPlan => 'Treatment Plan record',
                    default => $log->subject_type ? class_basename($log->subject_type) : null,
                };

                return [
                    'id' => $log->id,
                    'event' => $log->event,
                    'actor' => $isClinicalSafetyEvent
                        ? 'Clinical user'
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
