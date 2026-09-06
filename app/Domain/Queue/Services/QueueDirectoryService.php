<?php

namespace App\Domain\Queue\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Domain\Clinical\Services\CheckoutReopenEligibility;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Policies\VisitPolicy;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
        Gate::forUser($actor)->authorize('viewAny', QueueEntry::class);
        $branch = $this->activeBranch($actor);
        $now = now()->utc();
        $today = $now->setTimezone($branch->timezone)->toDateString();
        $eligibleDoctors = $this->doctors->eligibleDoctors($branch, $today);
        $eligibleDoctorIds = array_values(
            $eligibleDoctors->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );
        $base = $this->baseQuery($actor, $branch, $criteria);
        $status = (string) ($criteria['status'] ?? '');
        $page = (int) ($criteria['page'] ?? 1);
        $carryPage = (int) ($criteria['carry_page'] ?? 1);
        $visitActionHints = [];

        $waiting = ['data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1];
        if ($status === '' || $status === QueueEntry::STATUS_WAITING) {
            $paginator = (clone $base)
                ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
                ->whereDate('queue_entries.operational_date', $today)
                ->orderByRaw("CASE visits.priority WHEN 'urgent' THEN 0 ELSE 1 END")
                ->orderBy('queue_entries.queued_at')
                ->orderBy('queue_entries.id')
                ->paginate(25, page: $page);
            $waiting = [
                'data' => $paginator->getCollection()
                    ->map(function (QueueEntry $entry) use ($actor, $branch, $now, $eligibleDoctorIds, &$visitActionHints): array {
                        return $this->row($actor, $entry, $branch, $now, $eligibleDoctorIds, $visitActionHints);
                    })
                    ->values(),
                'total' => $paginator->total(),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
            ];
        }

        $carryOver = ['data' => [], 'total' => 0, 'currentPage' => 1, 'lastPage' => 1];
        if ($status === '' || $status === QueueEntry::STATUS_WAITING) {
            $carryPaginator = (clone $base)
                ->where('queue_entries.status', QueueEntry::STATUS_WAITING)
                ->whereDate('queue_entries.operational_date', '<', $today)
                ->orderByRaw("CASE visits.priority WHEN 'urgent' THEN 0 ELSE 1 END")
                ->orderBy('queue_entries.operational_date')
                ->orderBy('queue_entries.queued_at')
                ->orderBy('queue_entries.id')
                ->paginate(25, pageName: 'carry_page', page: $carryPage);
            $carryOver = [
                'data' => $carryPaginator->getCollection()
                    ->map(function (QueueEntry $entry) use ($actor, $branch, $now, $eligibleDoctorIds, &$visitActionHints): array {
                        return $this->row($actor, $entry, $branch, $now, $eligibleDoctorIds, $visitActionHints);
                    })
                    ->values(),
                'total' => $carryPaginator->total(),
                'currentPage' => $carryPaginator->currentPage(),
                'lastPage' => $carryPaginator->lastPage(),
            ];
        }

        $serving = [];
        if ($status === '' || $status === QueueEntry::STATUS_SERVING) {
            $serving = (clone $base)
                ->where('queue_entries.status', QueueEntry::STATUS_SERVING)
                ->latest('queue_entries.called_at')
                ->limit(25)
                ->get()
                ->map(function (QueueEntry $entry) use ($actor, $branch, $now, $eligibleDoctorIds, &$visitActionHints): array {
                    return $this->row($actor, $entry, $branch, $now, $eligibleDoctorIds, $visitActionHints);
                })
                ->values();
        }

        $removed = [];
        if ($status === QueueEntry::STATUS_REMOVED) {
            $removed = (clone $base)
                ->where('queue_entries.status', QueueEntry::STATUS_REMOVED)
                ->latest('queue_entries.removed_at')
                ->limit(25)
                ->get()
                ->map(function (QueueEntry $entry) use ($actor, $branch, $now, $eligibleDoctorIds, &$visitActionHints): array {
                    return $this->row($actor, $entry, $branch, $now, $eligibleDoctorIds, $visitActionHints);
                })
                ->values();
        }

        return [
            'branch' => $branch->only(['id', 'code', 'name', 'timezone']),
            'scope' => $actor->can('queue.view.branch') ? 'branch' : 'own',
            'serverNow' => $now->toIso8601String(),
            'operationalDate' => $today,
            'waiting' => $waiting,
            'carryOver' => $carryOver,
            'serving' => $serving,
            'removed' => $removed,
            'doctors' => $actor->can('queue.view.branch')
                ? $eligibleDoctors->map->only(['id', 'name'])->values()
                : [],
        ];
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
            ->where('queue_entries.branch_id', $branch->id)
            ->with([
                'visit' => fn ($visit) => $visit
                    ->select([
                        'id', 'organisation_id', 'branch_id', 'patient_id', 'visit_number', 'visit_reason', 'priority', 'coverage_type',
                        'coverage_panel_name_snapshot', 'assigned_doctor_user_id', 'status', 'lock_version',
                    ])
                    ->with(['patient:id,organisation_id,patient_number,full_name', 'assignedDoctor:id,name', 'reasonAssignments.reason:id,public_id,name']),
            ]);

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
            if (preg_match('/\A\d+\z/', $search) === 1) {
                $query->where('queue_entries.queue_number', (int) $search);
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
            $canOpenEncounter = $this->dispensaryAttention->hasPendingForVisit($actor, $visit)
                || app(CheckoutReopenEligibility::class)->allows($actor, $visit);
        }
        $actions = $visitActionHints[$entry->status] ??= $this->visitPolicy->actionHints($actor, $visit);

        return [
            'queueNumber' => sprintf('%03d', $entry->queue_number),
            'operationalDate' => $entry->operational_date->toDateString(),
            'patientNumber' => $visit->patient->patient_number,
            'patientName' => $visit->patient->full_name,
            'visitNumber' => $visit->visit_number,
            'visitReasonExcerpt' => ($summary = $this->visitReasons->summary($visit)) ? Str::limit($summary, 80) : null,
            'doctorName' => $visit->assignedDoctor?->name,
            'doctorEligible' => $doctorEligible,
            'coverageLabel' => $visit->coverage_type === 'panel'
                ? $visit->coverage_panel_name_snapshot : 'Self-pay',
            'priority' => $visit->priority,
            'status' => $entry->status,
            'queuedAt' => $entry->queued_at->toIso8601String(),
            'queuedTime' => $entry->queued_at->setTimezone($branch->timezone)->format('H:i'),
            'calledAt' => $entry->called_at?->toIso8601String(),
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
