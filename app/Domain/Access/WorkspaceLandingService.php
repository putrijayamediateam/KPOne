<?php

namespace App\Domain\Access;

use App\Models\User;

final class WorkspaceLandingService
{
    public function routeName(User $actor): string
    {
        if ($actor->hasRole(PermissionCatalogue::QUEUE_DISPLAY_ROLE) && $actor->can('queue.display.branch')) {
            return 'queue-display.screen';
        }

        // HR-15a: a locum's sign-in shows their own shifts.
        if ($actor->hasRole(PermissionCatalogue::LOCUM_ROLE) && $actor->can('hr.roster.view.own')) {
            return 'hr.roster.index';
        }
        if ($actor->hasRole('resident_doctor') && $actor->can('queue.view.own')) {
            return 'queue.index';
        }

        if ($actor->hasAnyRole(['ca', 'ca_supervisor', 'medical_assistant']) && $actor->can('visits.view.branch')) {
            return 'registration.index';
        }

        // DAY-1a (owner, 22 Nov 2026): the roles that used to land on the Main Menu now land on My day; the Main Menu stays one link away.
        return 'my-day';
    }

    public function path(User $actor): string
    {
        return route($this->routeName($actor), absolute: false);
    }

    public function canEnterClinic(User $actor): bool
    {
        return $actor->can('visits.view.branch')
            || $actor->can('queue.view.own')
            || $actor->can('queue.view.branch');
    }
}
