<?php

namespace App\Domain\HR\Roster\Services;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR roster permissions are organisation-scoped: the actor is locked and re-read inside the transaction (account,
 * roles and direct grants), must be active and hold the permission now; the branch must be an active branch of the
 * actor's own organisation. A revoked grant or deactivation takes effect on the next request.
 */
class RosterAuthority
{
    public function lock(User $actor, string $permission): User
    {
        $user = User::query()->whereKey($actor->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->firstOrFail();
        $roleIds = DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('role_id')->lockForUpdate()->pluck('role_id');
        DB::table('roles')->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->orderBy('role_id')->orderBy('permission_id')->lockForUpdate()->get();
        DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('permission_id')->lockForUpdate()->get();
        $user->load(['roles.permissions', 'permissions']);
        if (! $user->is_active || ! $user->can($permission)) {
            throw new AuthorizationException('You may not perform this operation.');
        }

        return $user;
    }

    public function branch(User $actor, mixed $branchId): Branch
    {
        $id = filter_var($branchId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $branch = $id === false ? null : Branch::query()->whereKey($id)->where('organisation_id', $actor->organisation_id)->where('is_active', true)->first();
        if ($branch === null) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a branch.']);
        }

        return $branch;
    }
}
