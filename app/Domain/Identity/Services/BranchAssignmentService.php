<?php

namespace App\Domain\Identity\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The supported mutation boundary for staff branch assignments.
 *
 * Assignments are historical records, so destructive deletion is intentionally
 * unsupported. End an assignment instead.
 */
class BranchAssignmentService
{
    public function __construct(
        private AuditRecorder $audit,
        private StaffAuthorityService $authority,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(
        StaffProfile $profile,
        Branch $branch,
        array $attributes,
        User $actor,
    ): StaffBranchAssignment {
        $validated = Validator::make($attributes, [
            'assignment_type' => ['required', 'string', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required_if:assignment_type,temporary', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ])->validate();

        return DB::transaction(function () use ($profile, $branch, $validated, $actor) {
            $lockedProfile = $this->lockProfile($profile);
            $this->authorizeActor($actor, $lockedProfile->user);

            if ($lockedProfile->user->organisation_id !== $branch->organisation_id) {
                throw ValidationException::withMessages([
                    'branch' => 'The branch belongs to another organisation.',
                ]);
            }

            $assignment = new StaffBranchAssignment;
            $assignment->forceFill([
                'staff_profile_id' => $lockedProfile->id,
                'branch_id' => $branch->id,
                'is_primary' => false,
                'assignment_type' => $validated['assignment_type'],
                'valid_from' => $validated['valid_from'],
                'valid_until' => $validated['valid_until'] ?? null,
            ])->save();

            if ($validated['is_primary'] ?? false) {
                $this->promoteLocked($lockedProfile, $assignment, $actor);
                $assignment->refresh();
            }

            $this->audit->record(
                'staff.branch_assignment.created',
                $assignment,
                ['after' => $this->snapshot($assignment)],
                $actor,
                $branch,
            );

            return $assignment;
        });
    }

    /**
     * Give a Director an effective assignment at every active branch of the
     * organisation (owner decision: the Director is fully authorised at every
     * branch). Console/bootstrap use only: there is no actor, so this can never
     * be used for self-escalation from the application, and it is limited to the
     * protected Director role. The primary assignment is never changed and
     * existing effective assignments are left alone, so the method is idempotent.
     *
     * @return int the number of assignments created
     */
    public function ensureDirectorCoverage(User $director): int
    {
        if (! app()->runningInConsole()) {
            throw new AuthorizationException('Director branch coverage can only be granted from the server console.');
        }

        return DB::transaction(function () use ($director): int {
            $locked = User::query()->whereKey($director->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || ! $locked->hasRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)) {
                throw ValidationException::withMessages(['user' => 'Only an active Director can receive all-branch coverage.']);
            }

            $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
            if ($profile === null) {
                throw ValidationException::withMessages(['user' => 'The Director has no staff profile.']);
            }

            $assignments = StaffBranchAssignment::query()
                ->where('staff_profile_id', $profile->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $created = 0;
            $branches = Branch::query()
                ->where('organisation_id', $locked->organisation_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->get();

            foreach ($branches as $branch) {
                $today = now()->setTimezone($branch->timezone)->toDateString();
                $covered = $assignments->contains(
                    fn (StaffBranchAssignment $a): bool => $a->branch_id === $branch->id
                        && $a->valid_from->toDateString() <= $today
                        && ($a->valid_until === null || $a->valid_until->toDateString() >= $today),
                );
                if ($covered) {
                    continue;
                }

                $assignment = new StaffBranchAssignment;
                $assignment->forceFill([
                    'staff_profile_id' => $profile->id,
                    'branch_id' => $branch->id,
                    'is_primary' => false,
                    'assignment_type' => 'permanent',
                    'valid_from' => $today,
                    'valid_until' => null,
                ])->save();

                $this->audit->record(
                    'staff.director_branch_coverage.granted',
                    $assignment,
                    ['after' => $this->snapshot($assignment)],
                    null,
                    $branch,
                    $locked->organisation_id,
                );
                $created++;
            }

            return $created;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        StaffBranchAssignment $assignment,
        array $attributes,
        User $actor,
    ): StaffBranchAssignment {
        $this->rejectUnsupportedKeys($attributes, ['assignment_type', 'valid_from', 'valid_until']);

        return DB::transaction(function () use ($assignment, $attributes, $actor) {
            $profile = $this->lockProfile($assignment->staffProfile);
            $this->authorizeActor($actor, $profile->user);
            $lockedAssignment = $this->lockAssignment($profile, $assignment);
            $before = $this->snapshot($lockedAssignment);
            $validated = Validator::make([
                'assignment_type' => $attributes['assignment_type'] ?? $lockedAssignment->assignment_type,
                'valid_from' => $attributes['valid_from'] ?? $lockedAssignment->valid_from->toDateString(),
                'valid_until' => array_key_exists('valid_until', $attributes)
                    ? $attributes['valid_until']
                    : $lockedAssignment->valid_until?->toDateString(),
            ], [
                'assignment_type' => ['required', 'string', 'max:50'],
                'valid_from' => ['required', 'date_format:Y-m-d'],
                'valid_until' => ['required_if:assignment_type,temporary', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            ])->validate();

            $this->protectActivePrimary($profile, $lockedAssignment, $validated);

            $lockedAssignment->forceFill($validated);

            if ($lockedAssignment->isDirty()) {
                $lockedAssignment->save();
                $this->recordChange('staff.branch_assignment.updated', $lockedAssignment, $before, $actor);
            }

            return $lockedAssignment->refresh();
        });
    }

    public function end(
        StaffBranchAssignment $assignment,
        User $actor,
        ?string $validUntil = null,
    ): StaffBranchAssignment {
        return DB::transaction(function () use ($assignment, $validUntil, $actor) {
            $profile = $this->lockProfile($assignment->staffProfile);
            $this->authorizeActor($actor, $profile->user);
            $lockedAssignment = $this->lockAssignment($profile, $assignment);

            if ($profile->user->is_active && $lockedAssignment->is_primary) {
                throw ValidationException::withMessages([
                    'assignment' => 'Change the primary branch before ending this assignment.',
                ]);
            }
            $before = $this->snapshot($lockedAssignment);
            $validated = Validator::make([
                'valid_from' => $lockedAssignment->valid_from->toDateString(),
                'valid_until' => $validUntil ?? now()->toDateString(),
            ], [
                'valid_from' => ['required', 'date_format:Y-m-d'],
                'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            ])->validate();

            $lockedAssignment->forceFill(['valid_until' => $validated['valid_until']]);

            if ($lockedAssignment->isDirty()) {
                $lockedAssignment->save();
                $this->recordChange('staff.branch_assignment.ended', $lockedAssignment, $before, $actor);
            }

            return $lockedAssignment->refresh();
        });
    }

    public function changePrimary(
        StaffProfile $profile,
        StaffBranchAssignment $assignment,
        User $actor,
    ): StaffBranchAssignment {
        return DB::transaction(function () use ($profile, $assignment, $actor) {
            $lockedProfile = $this->lockProfile($profile);
            $this->authorizeActor($actor, $lockedProfile->user);
            $lockedAssignment = $this->lockAssignment($lockedProfile, $assignment);
            $this->promoteLocked($lockedProfile, $lockedAssignment, $actor);

            return $lockedAssignment->refresh();
        });
    }

    private function promoteLocked(
        StaffProfile $profile,
        StaffBranchAssignment $target,
        User $actor,
    ): void {
        if (! $target->newQuery()->whereKey($target->id)->effectiveAt()->exists()) {
            throw ValidationException::withMessages([
                'assignment' => 'Only a currently effective assignment can be primary.',
            ]);
        }

        if ($profile->user->is_active && $target->valid_until !== null) {
            throw ValidationException::withMessages([
                'assignment' => 'An active staff member cannot use an expiring assignment as primary.',
            ]);
        }

        $assignments = StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->lockForUpdate()
            ->get();

        foreach ($assignments->where('is_primary', true)->where('id', '!=', $target->id) as $previousPrimary) {
            $before = $this->snapshot($previousPrimary);
            $previousPrimary->forceFill(['is_primary' => false])->save();
            $this->recordChange(
                'staff.branch_assignment.primary.demoted',
                $previousPrimary,
                $before,
                $actor,
            );
        }

        if (! $target->is_primary) {
            $before = $this->snapshot($target);
            $target->forceFill(['is_primary' => true])->save();
            $this->recordChange('staff.branch_assignment.primary.promoted', $target, $before, $actor);
        }
    }

    private function lockProfile(StaffProfile $profile): StaffProfile
    {
        return StaffProfile::query()
            ->with('user')
            ->whereKey($profile->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function authorizeActor(User $actor, User $subject): void
    {
        if ($actor->is($subject)
            || ! $actor->can('access.manage.organisation')
            || ! $this->authority->canManage($actor, $subject)) {
            throw new AuthorizationException('You may not change this staff member\'s branch access.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function protectActivePrimary(
        StaffProfile $profile,
        StaffBranchAssignment $assignment,
        array $attributes,
    ): void {
        if (! $profile->user->is_active || ! $assignment->is_primary) {
            return;
        }

        $validFrom = $attributes['valid_from'] ?? null;
        $validUntil = $attributes['valid_until'] ?? null;

        if ($validUntil !== null
            || ! is_string($validFrom)
            || $validFrom > now()->toDateString()) {
            throw ValidationException::withMessages([
                'assignment' => 'An active staff member must retain a currently effective primary branch.',
            ]);
        }
    }

    private function lockAssignment(
        StaffProfile $profile,
        StaffBranchAssignment $assignment,
    ): StaffBranchAssignment {
        return StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->whereKey($assignment->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     */
    private function rejectUnsupportedKeys(array $attributes, array $allowed): void
    {
        $unsupported = array_values(array_diff(array_keys($attributes), $allowed));

        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                'assignment' => 'Unsupported assignment fields: '.implode(', ', $unsupported),
            ]);
        }
    }

    /** @return array<string, bool|int|string|null> */
    private function snapshot(StaffBranchAssignment $assignment): array
    {
        return [
            'staff_profile_id' => $assignment->staff_profile_id,
            'branch_id' => $assignment->branch_id,
            'assignment_type' => $assignment->assignment_type,
            'is_primary' => $assignment->is_primary,
            'valid_from' => $assignment->valid_from->toDateString(),
            'valid_until' => $assignment->valid_until?->toDateString(),
        ];
    }

    /** @param array<string, bool|int|string|null> $before */
    private function recordChange(
        string $event,
        StaffBranchAssignment $assignment,
        array $before,
        User $actor,
    ): void {
        $assignment->refresh();
        $this->audit->record(
            $event,
            $assignment,
            ['before' => $before, 'after' => $this->snapshot($assignment)],
            $actor,
            $assignment->branch,
        );
    }
}
