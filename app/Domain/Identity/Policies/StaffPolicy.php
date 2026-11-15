<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Access\StaffAccessService;
use App\Domain\Access\StaffAuthorityService;
use App\Models\User;

class StaffPolicy
{
    public function __construct(
        private StaffAccessService $access,
        private StaffAuthorityService $authority,
    ) {}

    public function view(User $viewer, User $subject): bool
    {
        return $this->access->canView($viewer, $subject);
    }

    public function create(User $actor): bool
    {
        return $actor->can('staff.manage.organisation')
            && $actor->can('access.manage.organisation');
    }

    public function update(User $actor, User $subject): bool
    {
        return ! $actor->is($subject)
            && $actor->can('staff.manage.organisation')
            && $this->authority->canManage($actor, $subject);
    }

    public function manageAccess(User $actor, User $subject): bool
    {
        return ! $actor->is($subject)
            && $actor->can('access.manage.organisation')
            && $this->authority->canManage($actor, $subject);
    }

    public function manageRoles(User $actor, User $subject): bool
    {
        return ! $actor->is($subject)
            && $actor->can('access.manage.organisation')
            && $this->authority->canManage($actor, $subject);
    }

    public function manageStatus(User $actor, User $subject): bool
    {
        return ! $actor->is($subject)
            && $actor->can('staff.manage.organisation')
            && $this->authority->canManage($actor, $subject);
    }
}
