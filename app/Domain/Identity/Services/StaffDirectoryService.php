<?php

namespace App\Domain\Identity\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAccessService;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class StaffDirectoryService
{
    public function __construct(
        private StaffAccessService $access,
        private BranchAccessService $branches,
        private StaffAuthorityService $authority,
    ) {}

    /**
     * @param  array{search?:string|null,branch?:int|null,department?:int|null,role?:string|null,status?:string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function search(User $viewer, array $filters): LengthAwarePaginator
    {
        $query = $this->access->visibleUsers($viewer)
            ->with([
                'roles:id,name',
                'staffProfile.department:id,name',
                'staffProfile.branchAssignments' => fn ($query) => $query
                    ->with('branch:id,code,name')
                    ->orderByDesc('is_primary')
                    ->orderBy('valid_from'),
            ]);

        $search = trim((string) ($filters['search'] ?? ''));

        $query->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('staffProfile', fn ($profile) => $profile
                    ->where('staff_number', 'like', "%{$search}%")
                    ->orWhere('job_title', 'like', "%{$search}%"));
        }));

        if ($branchId = $filters['branch'] ?? null) {
            $allowedBranchIds = $viewer->can('staff.view.organisation')
                ? Branch::query()
                    ->where('organisation_id', $viewer->organisation_id)
                    ->where('is_active', true)
                    ->pluck('id')
                : $this->branches->availableBranches($viewer)->pluck('id');

            if (! $allowedBranchIds->contains((int) $branchId)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas('staffProfile.branchAssignments', fn ($assignment) => $assignment
                    ->whereDate('valid_from', '<=', now()->toDateString())
                    ->where(fn ($period) => $period
                        ->whereNull('valid_until')
                        ->orWhereDate('valid_until', '>=', now()->toDateString()))
                    ->where('branch_id', $branchId));
            }
        }
        $query->when($filters['department'] ?? null, fn ($query, $departmentId) => $query
            ->whereHas('staffProfile', fn ($profile) => $profile->where('department_id', $departmentId)));
        $query->when($filters['role'] ?? null, fn ($query, $role) => $query
            ->whereHas('roles', fn ($roles) => $roles->where('name', $role)));
        $query->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->where('is_active', true));
        $query->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false));

        return $query->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user): array => $this->summary($viewer, $user));
    }

    /** @return array<string, mixed> */
    public function detail(User $viewer, User $subject): array
    {
        $subject->load([
            'roles:id,name',
            'staffProfile.department:id,name',
            'staffProfile.branchAssignments.branch:id,code,name',
        ]);

        $profile = $subject->staffProfile;
        $assignments = $profile ? $profile->branchAssignments : new EloquentCollection;

        return [
            ...$this->summary($viewer, $subject),
            'signInConfiguration' => $subject->password
                ? 'password'
                : ($subject->google_subject ? 'google_linked' : 'google_awaiting_first_sign_in'),
            'createdAt' => $subject->created_at?->toIso8601String(),
            'assignments' => $this->visibleAssignments($viewer, $subject, $assignments)
                ->map(fn (StaffBranchAssignment $assignment): array => $this->assignment($assignment, $subject))
                ->values(),
            'recentAudit' => $this->recentAudit($viewer, $subject),
            'can' => [
                'update' => $viewer->can('update', $subject),
                'manageAccess' => $viewer->can('manageAccess', $subject),
                'manageRoles' => $viewer->can('manageRoles', $subject),
                'manageStatus' => $viewer->can('manageStatus', $subject),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function options(User $actor): array
    {
        return [
            'branches' => Branch::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'departments' => Department::query()
                ->where('organisation_id', $actor->organisation_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'roles' => collect(array_keys(PermissionCatalogue::roles()))
                ->filter(fn (string $role) => $this->authority->canAssignRoles($actor, [$role]))
                ->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function filterOptions(User $viewer): array
    {
        $branches = $viewer->can('staff.view.organisation')
            ? Branch::query()
                ->where('organisation_id', $viewer->organisation_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
            : $this->branches->availableBranches($viewer)->map->only(['id', 'code', 'name'])->values();

        return [
            'branches' => $branches,
            'departments' => Department::query()
                ->where('organisation_id', $viewer->organisation_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'roles' => array_keys(PermissionCatalogue::roles()),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(User $viewer, User $subject): array
    {
        $profile = $subject->staffProfile;
        $assignments = $profile ? $profile->branchAssignments : new EloquentCollection;
        $visible = $this->visibleAssignments($viewer, $subject, $assignments);
        $current = $visible->filter(
            fn (StaffBranchAssignment $assignment) => $this->assignmentState($assignment) === 'current',
        );

        return [
            'id' => $subject->id,
            'name' => $subject->name,
            'email' => $subject->email,
            'staffNumber' => $profile?->staff_number,
            'jobTitle' => $profile?->job_title,
            'department' => $profile?->department?->name,
            'departmentId' => $profile?->department_id,
            'isActive' => $subject->is_active,
            'roles' => $subject->roles->pluck('name')->sort()->values(),
            'primaryBranch' => ($primary = $current->firstWhere('is_primary', true))
                ? $primary->branch->only(['id', 'code', 'name'])
                : null,
            'currentAssignments' => $current
                ->map(fn (StaffBranchAssignment $assignment): array => $this->assignment($assignment, $subject))
                ->values(),
            'assignmentState' => $this->overallAssignmentState($assignments),
        ];
    }

    /**
     * @param  EloquentCollection<int, StaffBranchAssignment>  $assignments
     * @return Collection<int, StaffBranchAssignment>
     */
    private function visibleAssignments(User $viewer, User $subject, EloquentCollection $assignments): Collection
    {
        if ($viewer->can('staff.view.organisation') || $viewer->is($subject)) {
            return $assignments;
        }

        $activeBranch = $this->branches->activeBranch($viewer);

        return $assignments->filter(
            fn (StaffBranchAssignment $assignment) => $assignment->branch_id === $activeBranch?->id,
        );
    }

    /** @return array<string, mixed> */
    private function assignment(StaffBranchAssignment $assignment, User $subject): array
    {
        $state = $this->assignmentState($assignment);

        return [
            'id' => $assignment->id,
            'branch' => $assignment->branch->only(['id', 'code', 'name']),
            'assignmentType' => $assignment->assignment_type,
            'isPrimary' => $assignment->is_primary,
            'validFrom' => $assignment->valid_from->toDateString(),
            'validUntil' => $assignment->valid_until?->toDateString(),
            'state' => $state,
            'canBecomePrimary' => ! $assignment->is_primary
                && $state === 'current'
                && (! $subject->is_active || $assignment->valid_until === null),
        ];
    }

    private function assignmentState(StaffBranchAssignment $assignment): string
    {
        $today = now()->toDateString();

        if ($assignment->valid_from->toDateString() > $today) {
            return 'future';
        }

        if ($assignment->valid_until
            && $assignment->valid_until->toDateString() < $today) {
            return 'ended';
        }

        return 'current';
    }

    /** @param EloquentCollection<int, StaffBranchAssignment> $assignments */
    private function overallAssignmentState(EloquentCollection $assignments): string
    {
        if ($assignments->contains(fn (StaffBranchAssignment $assignment) => $this->assignmentState($assignment) === 'current')) {
            return 'current';
        }

        if ($assignments->contains(fn (StaffBranchAssignment $assignment) => $this->assignmentState($assignment) === 'future')) {
            return 'future';
        }

        return 'ended';
    }

    /** @return list<array{id:int,event:string,roleNames:list<string>,actor:string|null,occurredAt:string}> */
    private function recentAudit(User $viewer, User $subject): array
    {
        if (! $viewer->can('audit.view.organisation')) {
            return [];
        }

        $profile = $subject->staffProfile;
        $assignmentIds = $profile?->branchAssignments->pluck('id') ?? collect();

        return array_values(AuditLog::query()
            ->with('actor:id,name')
            ->where('organisation_id', $viewer->organisation_id)
            ->where(function ($query) use ($subject, $profile, $assignmentIds): void {
                $query->where(function ($query) use ($subject): void {
                    $query->where('subject_type', $subject->getMorphClass())
                        ->where('subject_id', $subject->id);
                });

                if ($profile) {
                    $query->orWhere(function ($query) use ($profile): void {
                        $query->where('subject_type', $profile->getMorphClass())
                            ->where('subject_id', $profile->id);
                    });
                }

                if ($assignmentIds->isNotEmpty()) {
                    $query->orWhere(function ($query) use ($assignmentIds): void {
                        $query->where('subject_type', (new StaffBranchAssignment)->getMorphClass())
                            ->whereIn('subject_id', $assignmentIds);
                    });
                }
            })
            ->latest('occurred_at')
            ->limit(15)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'roleNames' => $log->roleNames(),
                'actor' => $log->actor?->name,
                'occurredAt' => $log->occurred_at->toIso8601String(),
            ])
            ->values()
            ->all());
    }
}
