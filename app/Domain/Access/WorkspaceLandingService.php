<?php

namespace App\Domain\Access;

use App\Models\User;

final class WorkspaceLandingService
{
    public function routeName(User $actor): string
    {
        if ($actor->hasRole('resident_doctor') && $actor->can('queue.view.own')) {
            return 'queue.index';
        }

        if ($actor->hasAnyRole(['ca', 'ca_supervisor']) && $actor->can('visits.view.branch')) {
            return 'registration.index';
        }

        return 'dashboard';
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
