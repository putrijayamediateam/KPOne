<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
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
                $isPatient = $log->subject_type === (new Patient)->getMorphClass();
                $isVisit = $log->subject_type === (new Visit)->getMorphClass();
                $isQueue = $log->subject_type === (new QueueEntry)->getMorphClass();
                $isEncounter = $log->subject_type === (new ClinicalEncounter)->getMorphClass();
                $isProtectedOperationalRecord = $isPatient || $isVisit || $isQueue || $isEncounter;

                return [
                    'id' => $log->id,
                    'event' => $log->event,
                    'actor' => $log->actor instanceof User ? $log->actor->name : 'System',
                    'branch' => $log->branch?->code,
                    'subjectType' => $isPatient
                        ? 'Patient record'
                        : ($isVisit
                            ? 'Visit record'
                            : ($isQueue
                                ? 'Queue record'
                                : ($isEncounter
                                    ? 'Clinical record'
                                    : ($log->subject_type ? class_basename($log->subject_type) : null)))),
                    'subjectId' => $isProtectedOperationalRecord ? null : $log->subject_id,
                    'occurredAt' => $log->occurred_at->toIso8601String(),
                    'roleNames' => $log->roleNames(),
                ];
            })->values(),
            'total' => $paginator->total(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];

        return Inertia::render('AuditLogs/Index', ['logs' => $logs]);
    }
}
