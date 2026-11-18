<?php

namespace App\Domain\DepartmentSpace;

use App\Models\User;

/**
 * DS-12: the person's own pages for the first group of the account menu, built on the server from the permissions they
 * already hold. A row appears only for someone who can open that page today. The waiting-room TV account gets none.
 * Navigation only: no data, route or permission change.
 */
final class AccountMenuLinks
{
    public function __construct(private DepartmentSpaceNavigation $spaces) {}

    /**
     * @return array{links: list<array{title: string, href: string}>, clock: bool}
     */
    public function for(User $actor): array
    {
        $links = [];
        if ($this->spaces->canEnter($actor, 'me')) {
            $links[] = ['title' => 'Me', 'href' => route('spaces.show', ['space' => 'me'], absolute: false)];
        }
        foreach ([
            ['hr.roster.view.own', 'My roster', 'hr.roster.index'],
            ['hr.leave.request.own', 'My leave', 'hr.leave.index'],
            ['hr.attendance.clock.own', 'My attendance', 'hr.attendance.index'],
            ['hr.claims.submit.own', 'My claims', 'hr.claims.index'],
            ['hr.credentials.view.own', 'My credentials', 'hr.credentials.index'],
            ['hr.sops.acknowledge.own', 'My SOPs', 'hr.sops.index'],
        ] as [$permission, $title, $route]) {
            if ($actor->can($permission)) {
                $links[] = ['title' => $title, 'href' => route($route, absolute: false)];
            }
        }

        return ['links' => $links, 'clock' => $actor->can('hr.attendance.clock.own')];
    }
}
