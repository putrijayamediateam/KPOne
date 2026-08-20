<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Compares administrative capability without inventing a role-name hierarchy.
 *
 * Director is an explicit protected authority role because the Phase 0 catalogue
 * intentionally gives director and technical_admin the same administrative
 * permissions while preserving director governance over technical accounts.
 */
class StaffAuthorityService
{
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->organisation_id !== $target->organisation_id) {
            return false;
        }

        if ($target->hasRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)
            && ! $actor->hasRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)) {
            return false;
        }

        $actorPermissions = $this->effectivePermissions($actor);
        $targetAuthority = $this->effectivePermissions($target)
            ->filter(fn (string $permission) => PermissionCatalogue::isAdministrativeAuthority($permission));

        return $targetAuthority->diff($actorPermissions)->isEmpty();
    }

    /** @param list<string> $roles */
    public function canAssignRoles(User $actor, array $roles): bool
    {
        $catalogue = PermissionCatalogue::roles();
        $roles = array_values(array_unique($roles));

        if (array_diff($roles, array_keys($catalogue)) !== []) {
            return false;
        }

        if (in_array(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE, $roles, true)
            && ! $actor->hasRole(PermissionCatalogue::PROTECTED_AUTHORITY_ROLE)) {
            return false;
        }

        $roleModels = Role::query()
            ->with('permissions:id,name')
            ->where('guard_name', 'web')
            ->whereIn('name', $roles)
            ->get();

        if ($roleModels->count() !== count($roles)) {
            return false;
        }

        $proposedPermissions = $roleModels
            ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
            ->unique();
        $actorPermissions = $this->effectivePermissions($actor);

        return $proposedPermissions->every(
            fn (string $permission) => $this->covers($actorPermissions, $permission),
        );
    }

    /** @return Collection<int, string> */
    private function effectivePermissions(User $user): Collection
    {
        return $user->getAllPermissions()->pluck('name')->values();
    }

    /** @param Collection<int, string> $actorPermissions */
    private function covers(Collection $actorPermissions, string $proposedPermission): bool
    {
        if ($actorPermissions->contains($proposedPermission)) {
            return true;
        }

        $segments = explode('.', $proposedPermission);
        $scope = array_pop($segments);

        if (! in_array($scope, ['own', 'branch', 'organisation'], true)) {
            return false;
        }

        $capability = implode('.', $segments);
        $acceptedScopes = match ($scope) {
            'own' => ['own', 'branch', 'organisation'],
            'branch' => ['branch', 'organisation'],
            'organisation' => ['organisation'],
        };

        return collect($acceptedScopes)->contains(
            fn (string $acceptedScope) => $actorPermissions->contains("{$capability}.{$acceptedScope}"),
        );
    }
}
