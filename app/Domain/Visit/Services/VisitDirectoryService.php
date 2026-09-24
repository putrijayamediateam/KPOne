<?php

namespace App\Domain\Visit\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientDirectoryService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Policies\VisitPolicy;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitDirectoryService
{
    public function __construct(
        private BranchAccessService $branches,
        private VisitDoctorEligibilityService $doctors,
        private PatientDirectoryService $patients,
        private VisitPolicy $visitPolicy,
        private DispensaryDirectoryService $dispensary,
        private VisitReasonService $reasons,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function search(User $actor, array $criteria): array
    {
        Gate::forUser($actor)->authorize('viewAny', Visit::class);
        if (($criteria['board_status'] ?? null) === 'dispensary') {
            return $this->dispensary->board($actor, $criteria);
        }
        $branch = $this->activeBranch($actor);
        [$from, $to] = $this->dateRange($branch, $criteria);
        $query = Visit::query()
            ->select([
                'id',
                'organisation_id',
                'branch_id',
                'patient_id',
                'visit_number',
                'visit_type',
                'priority',
                'visit_reason',
                'assigned_doctor_user_id',
                'coverage_type',
                'coverage_panel_name_snapshot',
                'registered_at',
                'status',
                'lock_version',
                'completed_at',
            ])
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->whereBetween('registered_at', [$from, $to])
            ->with([
                'patient:id,organisation_id,patient_number,full_name',
                'assignedDoctor:id,name',
                'reasonAssignments.reason:id,public_id,name',
                'queueEntry:id,organisation_id,branch_id,visit_id,operational_date,queue_number,status,queued_at,called_at,returned_from_dispensary_at,lock_version',
                'clinicalEncounter.holds',
            ]);

        $boardStatus = (string) ($criteria['board_status'] ?? 'all');
        if (in_array($boardStatus, [QueueEntry::STATUS_WAITING, QueueEntry::STATUS_SERVING], true)) {
            $query->where('status', Visit::STATUS_REGISTERED)
                ->whereHas('queueEntry', fn ($queue) => $queue->where('status', $boardStatus));
        } elseif ($boardStatus === Visit::STATUS_CANCELLED) {
            $query->where('status', Visit::STATUS_CANCELLED);
        } elseif ($boardStatus === 'completed') {
            $query->where('status', Visit::STATUS_COMPLETED);
        } elseif ($boardStatus === 'billing') {
            abort_unless($actor->can('billing.view.branch'), 404);
            $query->where('status', Visit::STATUS_REGISTERED)->whereExists(function ($q): void {
                $q->selectRaw('1')->from('consultation_checkouts as checkout')->whereColumn('checkout.current_visit_guard', 'visits.id')
                    ->where(fn ($r) => $r->where('checkout.route', 'billing')->orWhereExists(fn ($c) => $c->selectRaw('1')->from('dispensary_cases as dc')->whereColumn('dc.visit_id', 'visits.id')->where('dc.status', 'completed')));
            });
        }

        $patientQuery = trim((string) ($criteria['patient_query'] ?? ''));
        if ($patientQuery !== '') {
            $query->whereHas('patient', function ($patient) use ($patientQuery): void {
                if (preg_match('/\AKP-\d{8}\z/i', $patientQuery) === 1) {
                    $patient->where('patient_number', Str::upper($patientQuery));
                } else {
                    $patient->whereRaw("search_name LIKE ? ESCAPE '\\'", ['%'.$this->escapeLike(Str::lower($patientQuery)).'%']);
                }
            });
        }
        foreach (['visit_type', 'priority', 'coverage_type', 'status'] as $field) {
            if (filled($criteria[$field] ?? null)) {
                $query->where($field, $criteria[$field]);
            }
        }
        if (filled($criteria['doctor_id'] ?? null)) {
            $query->where('assigned_doctor_user_id', (int) $criteria['doctor_id']);
        }

        $paginator = $query->latest('registered_at')->paginate(25, page: (int) ($criteria['page'] ?? 1));
        $effectiveDate = now()->setTimezone($branch->timezone)->toDateString();
        $eligibleDoctorIds = $this->doctors->eligibleDoctors($branch, $effectiveDate)
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $actionHints = [];

        return [
            'data' => $paginator->getCollection()
                ->map(function (Visit $visit) use ($actor, $branch, $eligibleDoctorIds, &$actionHints): array {
                    return $this->row($actor, $visit, $branch, $eligibleDoctorIds, $actionHints);
                })
                ->values(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(User $actor, Visit $visit): array
    {
        Gate::forUser($actor)->authorize('view', $visit);
        $visit->load([
            'branch:id,code,name,timezone',
            'patient:id,patient_number,full_name,date_of_birth,sex',
            'assignedDoctor:id,name',
            'queueEntry:id,organisation_id,branch_id,visit_id,operational_date,queue_number,status,queued_at,called_at,returned_from_dispensary_at,lock_version',
            'reasonAssignments.reason:id,public_id,name',
        ]);
        $visibleQueueEntry = $this->visibleQueueEntry($actor, $visit);

        return [
            'visitNumber' => $visit->visit_number,
            'patient' => [
                'patientNumber' => $visit->patient->patient_number,
                'fullName' => $visit->patient->full_name,
                'dateOfBirth' => $visit->patient->date_of_birth?->format('Y-m-d'),
                'sex' => $visit->patient->sex,
            ],
            'branch' => $visit->branch->only(['code', 'name', 'timezone']),
            'visitType' => $visit->visit_type,
            'status' => $visit->status,
            'priority' => $visit->priority,
            'visitReason' => $visit->visit_reason,
            'visitReasons' => $this->reasons->presentation($visit),
            'doctor' => $visit->assignedDoctor?->only(['id', 'name']),
            'coverage' => [
                'type' => $visit->coverage_type,
                'panelId' => $visit->panel_id,
                'panelName' => $visit->coverage_panel_name_snapshot,
                'memberReference' => $visit->coverage_member_reference,
            ],
            'registeredAt' => $visit->registered_at->setTimezone($visit->branch->timezone)->toIso8601String(),
            'cancelledAt' => $visit->cancelled_at?->setTimezone($visit->branch->timezone)->toIso8601String(),
            'cancellationReason' => $visit->cancellation_reason,
            'lockVersion' => $visit->lock_version,
            'queue' => $visibleQueueEntry ? [
                'queueNumber' => sprintf('%03d', $visibleQueueEntry->queue_number),
                'operationalDate' => $visibleQueueEntry->operational_date->toDateString(),
                'status' => $visibleQueueEntry->status,
                'queuedAt' => $visibleQueueEntry->queued_at->toIso8601String(),
                'calledAt' => $visibleQueueEntry->called_at?->toIso8601String(),
                'lockVersion' => $visibleQueueEntry->lock_version,
            ] : null,
            'can' => [
                'update' => $actor->can('update', $visit),
                'cancel' => $actor->can('cancel', $visit),
                'sendToWaiting' => $visit->status === Visit::STATUS_REGISTERED
                    && $visit->visit_type === 'consultation'
                    && $visit->queueEntry === null
                    && $actor->can('create', QueueEntry::class),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function formOptions(User $actor): array
    {
        $branch = $this->activeBranch($actor);
        $date = now()->setTimezone($branch->timezone)->toDateString();

        return [
            'branch' => $branch->only(['id', 'code', 'name', 'timezone']),
            'idempotencyKey' => (string) Str::uuid(),
            'doctors' => $this->doctors->eligibleDoctors($branch, $date)
                ->map->only(['id', 'name'])->values(),
            'panels' => Panel::query()->where('organisation_id', $actor->organisation_id)
                ->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    public function consoleOptions(User $actor): array
    {
        $branch = $this->activeBranch($actor);
        $date = now()->setTimezone($branch->timezone)->toDateString();

        return [
            'branch' => $branch->only(['id', 'code', 'name', 'timezone']),
            'doctors' => $this->doctors->eligibleDoctors($branch, $date)
                ->map->only(['id', 'name'])->values(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recentPatients(User $actor): array
    {
        Gate::forUser($actor)->authorize('create', Visit::class);
        $branch = $this->activeBranch($actor);
        $limit = 20;

        $patientIds = Visit::query()
            ->where('organisation_id', $actor->organisation_id)
            ->where('branch_id', $branch->id)
            ->where('status', Visit::STATUS_REGISTERED)
            ->select('patient_id')
            ->selectRaw('MAX(registered_at) AS last_registered_at')
            ->groupBy('patient_id')
            ->orderByDesc('last_registered_at')
            ->limit($limit)
            ->pluck('patient_id');

        if ($patientIds->count() < $limit) {
            $fallback = Patient::query()
                ->where('organisation_id', $actor->organisation_id)
                ->whereNotIn('id', $patientIds->all())
                ->latest('created_at')
                ->latest('id')
                ->limit($limit - $patientIds->count())
                ->pluck('id');
            $patientIds = $patientIds->concat($fallback);
        }

        $rankedPatientIds = [];
        foreach ($patientIds as $patientId) {
            $rankedPatientIds[] = (int) $patientId;
        }

        return $this->patients->summaries($actor, $rankedPatientIds);
    }

    /** @return array<string, mixed> */
    public function editOptions(User $actor, Visit $visit): array
    {
        Gate::forUser($actor)->authorize('update', $visit);
        $options = $this->formOptions($actor);
        unset($options['idempotencyKey']);

        return $options;
    }

    private function activeBranch(User $actor): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch) {
            throw ValidationException::withMessages(['branch' => 'Select an active authorised branch.']);
        }

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function dateRange(Branch $branch, array $criteria): array
    {
        $today = now()->setTimezone($branch->timezone)->startOfDay();
        $from = filled($criteria['date_from'] ?? null)
            ? $today->createFromFormat('Y-m-d', (string) $criteria['date_from'], $branch->timezone)->startOfDay()
            : $today;
        $to = filled($criteria['date_to'] ?? null)
            ? $today->createFromFormat('Y-m-d', (string) $criteria['date_to'], $branch->timezone)->endOfDay()
            : $from->endOfDay();
        if ($to->lt($from) || $from->diffInDays($to) > 30) {
            throw ValidationException::withMessages(['date_to' => 'Registration Console date range may not exceed 31 days.']);
        }

        return [$from->utc(), $to->utc()];
    }

    /**
     * @param  array<int, int>  $eligibleDoctorIds
     * @param  array<string, array{update: bool, cancel: bool}>  $actionHints
     * @return array<string, mixed>
     */
    private function row(
        User $actor,
        Visit $visit,
        Branch $branch,
        array $eligibleDoctorIds,
        array &$actionHints,
    ): array {
        $visibleQueueEntry = $this->visibleQueueEntry($actor, $visit);
        $visibleQueueStatus = $visibleQueueEntry instanceof QueueEntry ? $visibleQueueEntry->status : 'none';
        $actionKey = $visit->status.':'.$visibleQueueStatus;
        $doctorEligible = $visit->assigned_doctor_user_id !== null
            && in_array($visit->assigned_doctor_user_id, $eligibleDoctorIds, true);
        $canCall = $visibleQueueEntry?->status === QueueEntry::STATUS_WAITING
            && $doctorEligible
            && Gate::forUser($actor)->allows('call', $visibleQueueEntry);
        $canOpenEncounter = $visibleQueueEntry?->status === QueueEntry::STATUS_SERVING
            && $doctorEligible
            && $actor->is_active
            && $actor->hasRole('resident_doctor')
            && $actor->can('encounters.start.own')
            && $visit->assigned_doctor_user_id === $actor->id;
        $durationMinutes = match ($visibleQueueEntry?->status) {
            QueueEntry::STATUS_WAITING => max(0, (int) $visibleQueueEntry->queued_at->diffInMinutes(now()->utc())),
            QueueEntry::STATUS_SERVING => $visibleQueueEntry->called_at
                ? max(0, (int) $visibleQueueEntry->called_at->diffInMinutes(now()->utc()))
                : null,
            default => null,
        };
        $actions = $actionHints[$actionKey] ??= $this->visitPolicy->actionHints($actor, $visit);
        $activeHold = $visit->clinicalEncounter?->holds
            ->first(fn ($hold): bool => $hold->resumed_at === null);
        $checkout = ConsultationCheckout::query()->where('current_visit_guard', $visit->id)->first(['route']);
        $canBilling = $actor->can('billing.view.branch') && ($visit->status === Visit::STATUS_COMPLETED || ($checkout !== null && ($checkout->route === 'billing'
            || DispensaryCase::query()->where('visit_id', $visit->id)->where('status', 'completed')->exists())));

        return [
            'visitNumber' => $visit->visit_number,
            'patientNumber' => $visit->patient->patient_number,
            'patientName' => $visit->patient->full_name,
            'visitType' => $visit->visit_type,
            'registeredAt' => $visit->registered_at->setTimezone($branch->timezone)->format('H:i'),
            'registeredAtDate' => $visit->registered_at->setTimezone($branch->timezone)->format('Y-m-d'),
            'visitReasonExcerpt' => ($summary = $this->reasons->summary($visit)) ? Str::limit($summary, 80) : null,
            'doctorName' => $visit->assignedDoctor?->name,
            'coverageLabel' => $visit->coverage_type === 'panel'
                ? $visit->coverage_panel_name_snapshot : 'Self-pay',
            'priority' => $visit->priority,
            'status' => $visit->status,
            'queueNumber' => $visibleQueueEntry ? sprintf('%03d', $visibleQueueEntry->queue_number) : null,
            'queueStatus' => $visibleQueueEntry?->status,
            'queueRemovalReason' => $visibleQueueEntry?->removal_reason,
            'isHeld' => $activeHold !== null,
            'holdStartedAt' => $activeHold?->held_at->toIso8601String(),
            'durationMinutes' => $durationMinutes,
            'visitLockVersion' => $visit->lock_version,
            'billingUrl' => $canBilling ? route('billing.show', $visit) : null,
            'awaitingBilling' => $canBilling && $visit->status === Visit::STATUS_REGISTERED,
            'completedAt' => $visit->completed_at?->setTimezone($branch->timezone)->format('j M Y, g:i A'),
            'queueLockVersion' => $visibleQueueEntry?->lock_version,
            'returnedFromDispensary' => $visibleQueueEntry?->status === QueueEntry::STATUS_SERVING
                && $visibleQueueEntry->returned_from_dispensary_at !== null,
            'can' => [
                'viewPatient' => Gate::forUser($actor)->allows('view', $visit->patient),
                'update' => $actions['update'],
                'cancel' => $actions['cancel'],
                'sendToWaiting' => $visit->status === Visit::STATUS_REGISTERED
                    && $visit->visit_type === 'consultation'
                    && $visit->queueEntry === null
                    && Gate::forUser($actor)->allows('create', QueueEntry::class),
                'call' => $canCall,
                'openConsultation' => $canOpenEncounter,
                'openBilling' => $canBilling,
            ],
        ];
    }

    private function visibleQueueEntry(User $actor, Visit $visit): ?QueueEntry
    {
        $entry = $visit->queueEntry;
        if (! $entry) {
            return null;
        }

        $entry->setRelation('visit', $visit);

        return Gate::forUser($actor)->allows('view', $entry) ? $entry : null;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
