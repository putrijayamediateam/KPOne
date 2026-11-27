<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Domain\Clinical\Models\ConsultationHold;
use App\Domain\Clinical\Services\CheckoutReopenEligibility;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPermissions;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\Clinical\Triage\TriageZoneProjection;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Services\VisitWhatsAppEligibility;
use App\Domain\Queue\Emergency\EmergencyLaneProjection;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\QueueNumberFormat;
use App\Domain\Queue\QueueWaitingOrder;
use App\Domain\Queue\Readiness\CallHoldProjection;
use App\Domain\Queue\Treatment\TreatmentStepProjection;
use App\Domain\Visit\Assignment\DoctorWarningProjection;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Policies\VisitPolicy;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QueueDirectoryService
{
    public function __construct(
        private BranchAccessService $branches,
        private VisitDoctorEligibilityService $doctors,
        private VisitPolicy $visitPolicy,
        private DoctorDispensaryAttentionService $dispensaryAttention,
        private VisitReasonService $visitReasons,
    ) {}

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function snapshot(User $actor, array $criteria = []): array
    {
        return $this->buildSnapshot($actor, $criteria, true);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function poll(User $actor, array $criteria = []): array
    {
        return $this->buildSnapshot($actor, $criteria, false);
    }

    /** Independent bounded lists: never derive workload from the ordinary latest-25 board rows.
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function workload(User $actor, int $expectedBranchId, string $section, int $page = 1, array $criteria = []): array
    {
        abort_unless($actor->is_active, 403);
        Gate::forUser($actor)->authorize('viewAny', QueueEntry::class);
        $branch = $this->activeBranch($actor);
        abort_unless($branch->id === $expectedBranchId && $this->branches->canSelect($actor, $branch)
            && $this->branches->hasEffectiveAssignment($actor, $branch), 403);
        abort_unless(in_array($section, ['held', 'treatment', 'checks', 'seeing', 'completed', 'summary'], true), 422);
        $now = now()->utc();
        $queries = [];
        $counts = [];
        foreach (['held', 'treatment', 'checks', 'seeing', 'completed'] as $name) {
            $queries[$name] = app(QueueWorkloadQuery::class)->apply($this->baseQuery($actor, $branch, $criteria), $actor, $branch, $name);
            $counts[$name] = (clone $queries[$name])->count();
        }
        $total = $counts[$section] ?? 0;
        $lastPage = max(1, (int) ceil($total / 25));
        $page = min(max(1, $page), $lastPage);
        $entries = $section === 'summary' ? new EloquentCollection : $queries[$section]->orderBy('queue_entries.id')->forPage($page, 25)->get();
        $entries->load(['visit.patient', 'visit.assignedDoctor', 'visit.reasonAssignments.reason', 'visit.clinicalEncounter.holds']);
        $readiness = app(CallHoldProjection::class)->rows($actor, array_values($entries->modelKeys()));
        $treatment = app(TreatmentStepProjection::class)->rows($actor, $entries);
        $triage = app(TriageZoneProjection::class)->forVisits($actor, $entries
            ->map(fn (QueueEntry $entry): array => TriageZoneProjection::subject($entry->visit, $entry))->all());
        foreach ($entries as $entry) {
            $entry->setRelation('callReadiness', $readiness[$entry->id] ?? null);
            $entry->setRelation('treatmentProgress', $treatment[$entry->id] ?? null);
            $entry->setRelation('triageZone', $triage[$entry->visit_id] ?? null);
        }
        $checks = in_array($section, ['checks', 'completed'], true)
            ? $this->dispensaryAttention->checksForVisits($actor, $entries->map(fn (QueueEntry $entry) => $entry->visit)) : [];
        $removedHints = $section === 'completed'
            ? $this->dispensaryAttention->pendingForVisits($actor, $entries->map(fn (QueueEntry $entry) => $entry->visit))
                + app(CheckoutReopenEligibility::class)->forVisits($actor, $entries->map(fn (QueueEntry $entry) => $entry->visit)) + $checks
            : $checks;
        $eligibleDoctorIds = $this->doctors->eligibleDoctorIds($branch, $now->setTimezone($branch->timezone)->toDateString());
        $hints = [];
        $rows = $this->rows($actor, $entries, $branch, $now, $eligibleDoctorIds, $hints, $removedHints, $checks);
        if ($section === 'completed') {
            // Same authorized, loaded entry as the row; this is checkout/handoff time, not downstream completion.
            $completionTimes = $entries->mapWithKeys(fn (QueueEntry $entry): array => [
                $entry->visit->visit_number => $entry->removed_at?->toIso8601String(),
            ]);
            $rows = $rows->map(function (array $row) use ($completionTimes): array {
                $row['removedAt'] = $completionTimes->get($row['visitNumber']);

                return $row;
            });
        }

        return [
            'branchId' => $branch->id, 'scope' => $actor->can('queue.view.branch') ? 'branch' : 'own',
            'section' => $section, 'counts' => $counts, 'serverNow' => $now->toIso8601String(),
            'data' => $rows,
            'total' => $total, 'currentPage' => $page, 'lastPage' => $lastPage, 'perPage' => 25,
        ];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    private function buildSnapshot(User $actor, array $criteria, bool $includeStableMetadata): array
    {
        Gate::forUser($actor)->authorize('viewAny', QueueEntry::class);
        $branch = $this->activeBranch($actor);
        $now = now()->utc();
        $today = $now->setTimezone($branch->timezone)->toDateString();
        $eligibleDoctors = $includeStableMetadata
            ? $this->doctors->eligibleDoctors($branch, $today)
            : collect();
        $eligibleDoctorIds = $includeStableMetadata
            ? array_values($eligibleDoctors->pluck('id')->map(fn ($id) => (int) $id)->all())
            : $this->doctors->eligibleDoctorIds($branch, $today);
        $base = $this->baseQuery($actor, $branch, $criteria);
        $lane = app(EmergencyLaneProjection::class);
        $emergencyRead = $lane->read($actor, $branch, (int) ($criteria['emergency_page'] ?? 1));
        $emergencyEntries = $emergencyRead['entries'];
        $emergency = Arr::except($emergencyRead, ['entries', 'exclusionQuery']);
        $base->whereNotIn('visits.id', $emergencyRead['exclusionQuery']);
        $counts = $this->tabCounts($base, $today);
        $status = (string) ($criteria['status'] ?? '');
        $page = (int) ($criteria['page'] ?? 1);
        $carryPage = (int) ($criteria['carry_page'] ?? 1);
        $visitActionHints = [];

        /** @var EloquentCollection<int, QueueEntry> $waitingEntries */
        $waitingEntries = new EloquentCollection;
        $waitingMeta = ['total' => 0, 'currentPage' => 1, 'lastPage' => 1];
        if ($status === '' || $status === QueueEntry::STATUS_WAITING) {
            $paginator = QueueWaitingOrder::apply(
                (clone $base)
                    ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
                    ->whereDate('queue_entries.operational_date', $today),
                $today,
            )
                ->paginate(25, page: $page, total: $counts['today']);
            $waitingEntries = $paginator->getCollection();
            $waitingMeta = [
                'total' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
            ];
        }

        /** @var EloquentCollection<int, QueueEntry> $carryOverEntries */
        $carryOverEntries = new EloquentCollection;
        $carryOverMeta = ['total' => 0, 'currentPage' => 1, 'lastPage' => 1];
        if ($status === '' || $status === QueueEntry::STATUS_WAITING) {
            $carryPaginator = QueueWaitingOrder::apply(
                (clone $base)
                    ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
                    ->whereDate('queue_entries.operational_date', '<', $today),
                $today,
                byDate: true,
            )
                ->paginate(25, pageName: 'carry_page', page: $carryPage, total: $counts['carryOver']);
            $carryOverEntries = $carryPaginator->getCollection();
            $carryOverMeta = [
                'total' => $carryPaginator->total(),
                'currentPage' => $carryPaginator->currentPage(),
                'lastPage' => $carryPaginator->lastPage(),
            ];
        }

        /** @var EloquentCollection<int, QueueEntry> $servingEntries */
        $servingEntries = new EloquentCollection;
        if ($status === '' || $status === QueueEntry::STATUS_SERVING) {
            $servingEntries = (clone $base)
                ->where('queue_entries.status', QueueEntry::STATUS_SERVING)
                ->latest('queue_entries.called_at')
                ->limit(25)
                ->get();
        }

        /** @var EloquentCollection<int, QueueEntry> $removedEntries */
        $removedEntries = new EloquentCollection;
        if ($status === QueueEntry::STATUS_REMOVED) {
            $removedEntries = (clone $base)
                ->where('queue_entries.status', QueueEntry::STATUS_REMOVED)
                ->latest('queue_entries.removed_at')
                ->limit(25)
                ->get();
        }

        $entries = new EloquentCollection([
            ...$emergencyEntries->all(),
            ...$waitingEntries->all(),
            ...$carryOverEntries->all(),
            ...$servingEntries->all(),
            ...$removedEntries->all(),
        ]);
        $entries->load([
            'visit' => fn ($visit) => $visit
                ->select([
                    'id', 'organisation_id', 'branch_id', 'patient_id', 'visit_number', 'visit_reason', 'priority', 'coverage_type',
                    'coverage_panel_name_snapshot', 'coverage_panel_note', 'assigned_doctor_user_id', 'status', 'lock_version',
                    // TRI-1c: the zone projection shows a chip on consultation visits only.
                    'visit_type',
                ])
                ->with([
                    'patient:id,organisation_id,patient_number,full_name,mobile_phone',
                    'assignedDoctor:id,name',
                    'reasonAssignments.reason:id,public_id,name',
                    'clinicalEncounter.holds',
                ]),
        ]);

        $removedVisits = $removedEntries->map(fn (QueueEntry $entry) => $entry->visit);
        // Q-13: the doctor's own removed rows whose dispensary case waits for the doctor's check (one batched read, ids only).
        // It is its own array for the board's block; it also lets the doctor open the consultation (the way in).
        $checkWaiting = $this->dispensaryAttention->checksForVisits($actor, $removedVisits);
        // Q-15: when this page already holds EVERY removed entry in scope (the Completed tab, unfiltered, nothing cut off by the 25-row
        // limit), the flags just read are the whole answer, so no second query. Otherwise one aggregate count over all the doctor's cases.
        $showsAllRemoved = $status === QueueEntry::STATUS_REMOVED
            && ! filled($criteria['priority'] ?? null)
            && trim((string) ($criteria['query'] ?? '')) === ''
            && $removedEntries->count() >= $counts['tabs']['removed'];
        $checksWaiting = $showsAllRemoved
            ? count($checkWaiting)
            : $this->dispensaryAttention->checksWaitingCount($actor, $branch);
        $removedHints = $this->dispensaryAttention->pendingForVisits($actor, $removedVisits)
            + app(CheckoutReopenEligibility::class)->forVisits($actor, $removedVisits)
            + $checkWaiting;
        $readiness = app(CallHoldProjection::class)->rows($actor, array_values($entries->modelKeys()));
        $treatment = app(TreatmentStepProjection::class)->rows($actor, $entries);
        // TRI-1c: the zone chip, only for CA, CA Supervisor and the patient's own doctor (one query).
        $triageZones = app(TriageZoneProjection::class)->forVisits($actor, $entries
            ->map(fn (QueueEntry $entry): array => TriageZoneProjection::subject($entry->visit, $entry))->all());
        foreach ($entries as $entry) {
            $entry->setRelation('callReadiness', $readiness[$entry->id] ?? null);
            $entry->setRelation('treatmentProgress', $treatment[$entry->id] ?? null);
            $entry->setRelation('triageZone', $triageZones[$entry->visit_id] ?? null);
        }

        $emergencyBoardRows = $this->rows($actor, $emergencyEntries, $branch, $now, $eligibleDoctorIds, $visitActionHints)->keyBy('visitNumber');
        foreach ($emergency['data'] as &$emergencyRow) {
            $emergencyRow['boardRow'] = $emergencyBoardRows->get($emergencyRow['visitNumber']);
        }
        unset($emergencyRow);

        $dynamic = [
            'emergency' => $emergency,
            'tabCounts' => $counts['tabs'],
            // Q-15: every dispensary case waiting for this doctor's check (0 for anyone else); a top-level key, so tabCounts keeps its shape.
            'checksWaiting' => $checksWaiting,
            'scope' => $actor->can('queue.view.branch') ? 'branch' : 'own',
            'serverNow' => $now->toIso8601String(),
            'operationalDate' => $today,
            'waiting' => ['data' => $this->rows($actor, $waitingEntries, $branch, $now, $eligibleDoctorIds, $visitActionHints), ...$waitingMeta],
            'carryOver' => ['data' => $this->rows($actor, $carryOverEntries, $branch, $now, $eligibleDoctorIds, $visitActionHints), ...$carryOverMeta],
            'serving' => $this->rows($actor, $servingEntries, $branch, $now, $eligibleDoctorIds, $visitActionHints),
            'removed' => $this->rows($actor, $removedEntries, $branch, $now, $eligibleDoctorIds, $visitActionHints, $removedHints, $checkWaiting),
            // Q-4: who "Call next" would call. Only the applied doctor filter matters; null when this board cannot call.
            'callNext' => app(CallNextService::class)->block(
                $actor,
                $branch,
                filled($criteria['doctor_id'] ?? null) ? (int) $criteria['doctor_id'] : null,
                $eligibleDoctorIds,
            ),
        ];

        if (! $includeStableMetadata) {
            return $dynamic;
        }

        return [
            'branch' => $branch->only(['id', 'code', 'name', 'timezone']),
            ...$dynamic,
            'doctors' => $actor->can('queue.view.branch')
                ? $eligibleDoctors->map->only(['id', 'name'])->values()
                : [],
        ];
    }

    /**
     * @param  Collection<int, QueueEntry>  $entries
     * @param  list<int>  $eligibleDoctorIds
     * @param  array<string, array{update: bool, cancel: bool}>  $visitActionHints
     * @param  array<int, bool>  $removedHints
     * @param  array<int, bool>  $checkWaiting
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(
        User $actor,
        Collection $entries,
        Branch $branch,
        CarbonInterface $now,
        array $eligibleDoctorIds,
        array &$visitActionHints,
        array $removedHints = [],
        array $checkWaiting = [],
    ): Collection {
        // AUTO-1c: read the warning once for every waiting consultation doctor on this tab.
        $waiting = $entries->filter(fn (QueueEntry $entry): bool => $entry->status === QueueEntry::STATUS_WAITING && $entry->visit->visit_type === 'consultation');
        $warnings = $waiting->isEmpty() ? [] : app(DoctorWarningProjection::class)->forDoctors($actor, $branch, $waiting->map(fn (QueueEntry $entry): ?int => $entry->visit->assigned_doctor_user_id)->values()->all());
        foreach ($waiting as $entry) {
            $entry->visit->setRelation('doctorWarning', $warnings[$entry->visit->assigned_doctor_user_id] ?? null);
        }

        return $entries
            ->map(function (QueueEntry $entry) use ($actor, $branch, $now, $eligibleDoctorIds, &$visitActionHints, $removedHints, $checkWaiting): array {
                return $this->row($actor, $entry, $branch, $now, $eligibleDoctorIds, $visitActionHints, $removedHints[$entry->visit_id] ?? false, $checkWaiting[$entry->visit_id] ?? false);
            })
            ->values();
    }

    /**
     * @param  Builder<QueueEntry>  $base
     * @return array{today: int, carryOver: int, tabs: array{all: int, waiting: int, serving: int, removed: int}}
     */
    private function tabCounts(Builder $base, string $today): array
    {
        $row = (array) (clone $base)->select([])->selectRaw(
            "COUNT(CASE WHEN queue_entries.status = 'waiting' AND queue_entries.operational_date = ? THEN 1 END) AS today,
             COUNT(CASE WHEN queue_entries.status = 'waiting' AND queue_entries.operational_date < ? THEN 1 END) AS carry_over,
             COUNT(CASE WHEN queue_entries.status = 'serving' THEN 1 END) AS serving,
             COUNT(CASE WHEN queue_entries.status = 'removed' THEN 1 END) AS removed",
            [$today, $today],
        )->toBase()->first();
        $current = (int) $row['today'];
        $carryOver = (int) $row['carry_over'];
        $waiting = $current + $carryOver;
        $serving = (int) $row['serving'];

        return ['today' => $current, 'carryOver' => $carryOver, 'tabs' => ['all' => $waiting + $serving, 'waiting' => $waiting, 'serving' => $serving, 'removed' => (int) $row['removed']]];
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return Builder<QueueEntry>
     */
    private function baseQuery(User $actor, Branch $branch, array $criteria): Builder
    {
        $query = QueueEntry::query()
            ->select([
                'queue_entries.id',
                'queue_entries.organisation_id',
                'queue_entries.branch_id',
                'queue_entries.visit_id',
                'queue_entries.operational_date',
                'queue_entries.queue_number',
                'queue_entries.status',
                'queue_entries.queued_at',
                'queue_entries.called_at',
                'queue_entries.removed_at',
                'queue_entries.removal_reason',
                'queue_entries.returned_from_dispensary_at',
                'queue_entries.lock_version',
            ])
            ->join('visits', 'visits.id', '=', 'queue_entries.visit_id')
            ->where('queue_entries.organisation_id', $actor->organisation_id)
            ->where('queue_entries.branch_id', $branch->id);

        if (! $actor->can('queue.view.branch')) {
            $query->where('visits.assigned_doctor_user_id', $actor->id);
        } elseif (filled($criteria['doctor_id'] ?? null)) {
            $query->where('visits.assigned_doctor_user_id', (int) $criteria['doctor_id']);
        }

        if (filled($criteria['priority'] ?? null)) {
            $query->where('visits.priority', $criteria['priority']);
        }

        $search = trim((string) ($criteria['query'] ?? ''));
        if ($search !== '') {
            if (preg_match('/\A(?:A-?)?(\d+)\z/i', $search, $number) === 1) {
                $query->where('queue_entries.queue_number', (int) $number[1]);
            } else {
                $query->whereHas('visit.patient', function ($patient) use ($search): void {
                    if (preg_match('/\AKP-\d{8}\z/i', $search) === 1) {
                        $patient->where('patient_number', Str::upper($search));
                    } else {
                        $patient->whereRaw(
                            "search_name LIKE ? ESCAPE '\\'",
                            ['%'.$this->escapeLike(Str::lower($search)).'%'],
                        );
                    }
                });
            }
        }

        return $query;
    }

    /**
     * @param  list<int>  $eligibleDoctorIds
     * @param  array<string, array{update: bool, cancel: bool}>  $visitActionHints
     * @return array<string, mixed>
     */
    private function row(
        User $actor,
        QueueEntry $entry,
        Branch $branch,
        CarbonInterface $now,
        array $eligibleDoctorIds,
        array &$visitActionHints,
        bool $removedCanOpen = false,
        bool $dispensaryCheckWaiting = false,
    ): array {
        $visit = $entry->visit;
        $entry->setRelation('visit', $visit);
        $visit->setRelation('queueEntry', $entry);
        $doctorEligible = $visit->assigned_doctor_user_id !== null
            && in_array($visit->assigned_doctor_user_id, $eligibleDoctorIds, true);
        $canCall = $actor->can('queue.call.branch')
            || ($actor->can('queue.call.own') && $visit->assigned_doctor_user_id === $actor->id);
        $canOpenEncounter = $entry->status === QueueEntry::STATUS_SERVING
            && $doctorEligible
            && $actor->is_active
            && $actor->hasRole('resident_doctor')
            && $actor->can('encounters.start.own')
            && $visit->assigned_doctor_user_id === $actor->id;
        if ($entry->status === QueueEntry::STATUS_REMOVED) {
            $canOpenEncounter = $removedCanOpen;
        }
        $actions = $visitActionHints[$entry->status] ??= $this->visitPolicy->actionHints($actor, $visit);
        $activeHold = $visit->clinicalEncounter?->holds
            ->first(fn ($hold): bool => $hold->resumed_at === null);
        $heldSeconds = $visit->clinicalEncounter?->holds->sum(
            fn ($hold): float => $hold->held_at->diffInSeconds($hold->resumed_at ?? $now),
        ) ?? 0;
        $elapsedSeconds = $entry->called_at ? $entry->called_at->diffInSeconds($now) : 0;

        return [
            // Q-13: true only on the assigned doctor's own removed row whose dispensary case waits for the doctor's check.
            'dispensaryCheckWaiting' => $dispensaryCheckWaiting,
            'callHold' => $entry->getRelation('callReadiness'),
            // TRI-1c: { zone, triaged } or null when this viewer may not see the zone; never a reading, never on the TV feed.
            'triage' => $entry->relationLoaded('triageZone') ? $entry->getRelation('triageZone') : null,
            // TRI-1e: a link for the patient's own doctor only (the route checks the exact rule again).
            // The own doctor: the attending clinician once a consultation exists, otherwise the assigned doctor (the encounter
            // is already eager-loaded, so this adds no query).
            'triageViewUrl' => $visit->visit_type === 'consultation'
                && ($visit->clinicalEncounter !== null ? $visit->clinicalEncounter->attending_clinician_user_id : $visit->assigned_doctor_user_id) === $actor->id
                && $actor->hasRole('resident_doctor') && $actor->can(TriagePermissions::VIEW_OWN)
                ? route('visits.triage.view', $visit) : null,
            // Q-2: only Serving consultations carry this; never on the TV feed.
            'treatmentProgress' => $entry->relationLoaded('treatmentProgress') ? $entry->getRelation('treatmentProgress') : null,
            'canWhatsapp' => VisitWhatsAppEligibility::allows($actor, $visit, $branch),
            'queueNumber' => QueueNumberFormat::format($entry->queue_number),
            'operationalDate' => $entry->operational_date->toDateString(),
            'patientNumber' => $visit->patient->patient_number,
            'patientName' => $visit->patient->full_name,
            'visitNumber' => $visit->visit_number,
            'visitReasonExcerpt' => ($summary = $this->visitReasons->summary($visit)) ? Str::limit($summary, 80) : null,
            'doctorName' => $visit->assignedDoctor?->name,
            // Only a viewer authorized to edit this visit receives its reassignment warning.
            'doctorWarning' => $actions['update'] && $visit->relationLoaded('doctorWarning') ? $visit->getRelation('doctorWarning') : null,
            'doctorEligible' => $doctorEligible,
            'panelNote' => $visit->coverage_type === 'panel' ? $visit->coverage_panel_note : null,
            'coverageLabel' => $visit->coverage_type === 'panel'
                ? $visit->coverage_panel_name_snapshot : 'Self-pay',
            'priority' => $visit->priority,
            'status' => $entry->status,
            'queuedAt' => $entry->queued_at->toIso8601String(),
            'queuedTime' => $entry->queued_at->setTimezone($branch->timezone)->format('H:i'),
            'calledAt' => $entry->called_at?->toIso8601String(),
            'isHeld' => $activeHold !== null,
            'isHeldTooLong' => $activeHold !== null
                && $activeHold->held_at->lt($now->copy()->subMinutes(ConsultationHold::HELD_TOO_LONG_WARNING_MINUTES)),
            'holdStartedAt' => $activeHold?->held_at->toIso8601String(),
            'heldMinutes' => (int) floor($heldSeconds / 60),
            'activeMinutes' => (int) floor(max(0, $elapsedSeconds - $heldSeconds) / 60),
            'removalReason' => $entry->removal_reason,
            'visitStatus' => $visit->status,
            'returnedFromDispensary' => $entry->status === QueueEntry::STATUS_SERVING
                && $entry->returned_from_dispensary_at !== null,
            'waitingMinutes' => $entry->status === QueueEntry::STATUS_WAITING
                ? max(0, (int) $entry->queued_at->diffInMinutes($now)) : null,
            'visitLockVersion' => $visit->lock_version,
            'queueLockVersion' => $entry->lock_version,
            // This is only a UI hint. The mutation route, policy, and locked
            // service re-authorize Call In; avoid a branch-assignment query
            // for every row in the three-second polling projection.
            'canCall' => $doctorEligible && $canCall,
            // Announcement authority is independent of calling a patient to the doctor.
            'canCallTreatment' => $actor->is_active
                && $visit->status === Visit::STATUS_REGISTERED
                && $entry->status === QueueEntry::STATUS_SERVING
                && ($actor->can(TreatmentRoomPermissions::TREATMENT_CALL_BRANCH)
                    || ($doctorEligible && $canCall && $activeHold === null)),
            // Clinical permissions are independent of Queue authority. This
            // remains a UI hint; Encounter start re-authorizes under lock.
            'canOpenEncounter' => $canOpenEncounter,
            'can' => [
                'viewPatient' => Gate::forUser($actor)->allows('view', $visit->patient),
                'update' => $actions['update'],
                'cancel' => $actions['cancel'],
            ],
        ];
    }

    private function activeBranch(User $actor): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        if (! $branch) {
            throw ValidationException::withMessages(['branch' => 'Select an active authorised branch.']);
        }

        return $branch;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
