<?php

namespace App\Domain\Access;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionalActorAuthority
{
    public function __construct(private BranchAccessService $branches) {}

    public function branch(User $actor, mixed $expected): Branch
    {
        $branch = $this->branches->activeBranch($actor);
        abort_unless($branch && $branch->organisation_id === $actor->organisation_id, 404);
        if (filter_var($expected, FILTER_VALIDATE_INT) !== $branch->id) {
            throw ValidationException::withMessages(['expected_branch_id' => 'The branch changed. Reload before continuing.']);
        }

        return $branch;
    }

    public function lock(User $actor, Branch $branch, string $permission): User
    {
        $user = User::query()->whereKey($actor->id)->where('organisation_id', $branch->organisation_id)->lockForUpdate()->firstOrFail();
        $profile = StaffProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
        // Existing permission writers and PostgreSQL fixtures serialize actor changes on User.
        // Lock the current grant rows as well; reload rather than trusting cached relations.
        $roleIds = DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('role_id')->lockForUpdate()->pluck('role_id');
        DB::table('roles')->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->orderBy('role_id')->orderBy('permission_id')->lockForUpdate()->get();
        DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('permission_id')->lockForUpdate()->get();
        $user->load(['roles.permissions', 'permissions']);
        $assignments = $profile ? StaffBranchAssignment::query()->where('staff_profile_id', $profile->id)->orderBy('id')->lockForUpdate()->get() : collect();
        $date = now()->setTimezone($branch->timezone)->toDateString();
        $assigned = $assignments->contains(fn ($a): bool => $a->branch_id === $branch->id && $a->valid_from->toDateString() <= $date && ($a->valid_until === null || $a->valid_until->toDateString() >= $date));
        if (! $user->is_active || ! $profile || ! $assigned || ! $user->can($permission)) {
            throw new AuthorizationException('You may not perform this operation.');
        }

        return $user;
    }
}
