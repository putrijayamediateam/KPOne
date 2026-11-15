<?php

namespace App\Domain\Clinical\Dispensary\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class DispensaryAuthorityService
{
    public function __construct(private BranchAccessService $branches) {}

    /** @param array<string,mixed> $attributes */
    public function activeBranch(User $actor, array $attributes): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        if ((int) ($attributes['expected_branch_id'] ?? 0) !== $branch->id) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The active branch changed. Review and retry.']);
        }

        return $branch;
    }

    public function lock(User $actor, Branch $branch, string $permission): User
    {
        $locked = User::query()->whereKey($actor->id)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->firstOrFail();
        $locked->load(['roles.permissions', 'permissions']);
        $profile = StaffProfile::query()->where('user_id', $locked->id)->lockForUpdate()->first();
        $assignments = $profile ? StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get() : collect();
        $date = now()->setTimezone($branch->timezone)->toDateString();
        $assigned = $assignments->contains(fn (StaffBranchAssignment $a): bool => $a->branch_id === $branch->id && $a->valid_from->toDateString() <= $date && ($a->valid_until === null || $a->valid_until->toDateString() >= $date));
        if (! $locked->is_active || ! $profile || ! $locked->hasAnyRole(['ca', 'ca_supervisor']) || ! $locked->can($permission) || ! $assigned) {
            throw new AuthorizationException('You may not access this Dispensary workflow.');
        }

        return $locked;
    }
}
