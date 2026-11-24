<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Identity\Models\StaffProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Fresh organisation-permission check under lock for the triage guideline (no branch: the permission is
 * organisation-scoped). Same rows as TransactionalActorAuthority::lock, in the same order, minus the branch assignment.
 */
final class TriageActorLock
{
    public static function organisation(User $actor, string $permission): User
    {
        $user = User::query()->whereKey($actor->id)->where('organisation_id', $actor->organisation_id)->lockForUpdate()->first();
        if (! $user) {
            throw new AuthorizationException('You may not perform this operation.');
        }
        StaffProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
        $roleIds = DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('role_id')->lockForUpdate()->pluck('role_id');
        DB::table('roles')->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->orderBy('role_id')->orderBy('permission_id')->lockForUpdate()->get();
        DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->orderBy('permission_id')->lockForUpdate()->get();
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $user->load(['roles.permissions', 'permissions']);
        if (! $user->is_active || ! $user->can($permission)) {
            throw new AuthorizationException('You may not perform this operation.');
        }

        return $user;
    }
}
