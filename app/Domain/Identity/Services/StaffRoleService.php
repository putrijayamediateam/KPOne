<?php

namespace App\Domain\Identity\Services;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\AccessChangeActorContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffRoleService
{
    public function __construct(
        private StaffAuthorityService $authority,
        private AccessChangeActorContext $actors,
    ) {}

    /** @param list<string> $roles */
    public function sync(User $subject, array $roles, User $actor): User
    {
        $roles = array_values(array_unique($roles));

        if ($roles === []) {
            throw ValidationException::withMessages([
                'roles' => 'At least one KPOne role is required.',
            ]);
        }

        if (array_diff($roles, array_keys(PermissionCatalogue::roles())) !== []) {
            throw ValidationException::withMessages([
                'roles' => 'One or more selected roles are not in the KPOne role catalogue.',
            ]);
        }

        return DB::transaction(function () use ($subject, $roles, $actor): User {
            $lockedSubject = User::query()->whereKey($subject->id)->lockForUpdate()->firstOrFail();

            if (! $actor->can('access.manage.organisation')
                || $actor->is($lockedSubject)
                || ! $this->authority->canManage($actor, $lockedSubject)
                || ! $this->authority->canAssignRoles($actor, $roles)) {
                throw new AuthorizationException('You may not change this staff member\'s roles.');
            }

            $this->actors->run($actor, fn () => $lockedSubject->syncRoles($roles));

            return $lockedSubject->refresh()->load('roles');
        });
    }
}
