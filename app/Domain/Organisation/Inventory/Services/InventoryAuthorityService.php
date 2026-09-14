<?php

namespace App\Domain\Organisation\Inventory\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class InventoryAuthorityService
{
    public function __construct(private BranchAccessService $branches) {}

    /** @param array<string, mixed> $attributes */
    public function activeBranch(User $actor, array $attributes): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        if ((int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed. Review and retry.']);
        }

        return $branch;
    }

    public function lockForBranch(User $actor, Branch $branch, string $permission): User
    {
        $this->lockOrganisation($actor);
        $locked = $this->lockActor($actor, $permission);
        $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
        $assignments = $profile ? StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get() : collect();
        $date = now()->setTimezone($branch->timezone)->toDateString();
        $assigned = $assignments->contains(fn (StaffBranchAssignment $assignment): bool => $assignment->branch_id === $branch->id
            && $assignment->valid_from->toDateString() <= $date
            && ($assignment->valid_until === null || $assignment->valid_until->toDateString() >= $date));
        if (! $profile || ! $assigned) {
            throw new AuthorizationException('You may not access this Inventory branch workflow.');
        }

        return $locked;
    }

    public function lockForOrganisation(User $actor, string $permission): User
    {
        $this->lockOrganisation($actor);
        $locked = $this->lockActor($actor, $permission);
        $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
        if (! $profile) {
            throw new AuthorizationException('An active staff profile is required.');
        }
        $assignments = StaffBranchAssignment::query()
            ->where('staff_profile_id', $profile->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $branches = Branch::query()
            ->where('organisation_id', $locked->organisation_id)
            ->where('is_active', true)
            ->whereIn('id', $assignments->pluck('branch_id'))
            ->orderBy('id')
            ->get(['id', 'timezone'])
            ->keyBy('id');
        $assigned = $assignments->contains(function (StaffBranchAssignment $assignment) use ($branches): bool {
            $branch = $branches->get($assignment->branch_id);
            if (! $branch) {
                return false;
            }
            $date = now()->setTimezone($branch->timezone)->toDateString();

            return $assignment->valid_from->toDateString() <= $date
                && ($assignment->valid_until === null || $assignment->valid_until->toDateString() >= $date);
        });
        if (! $assigned) {
            throw new AuthorizationException('A current staff branch assignment is required.');
        }

        return $locked;
    }

    private function lockOrganisation(User $actor): Organisation
    {
        return Organisation::query()->whereKey($actor->organisation_id)->where('is_active', true)->sharedLock()->firstOrFail();
    }

    private function lockActor(User $actor, string $permission): User
    {
        $locked = User::query()->whereKey($actor->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        $locked->load(['roles.permissions', 'permissions']);
        if (! $locked->is_active || ! $locked->can($permission)) {
            throw new AuthorizationException('You may not perform this Inventory operation.');
        }

        return $locked;
    }
}
