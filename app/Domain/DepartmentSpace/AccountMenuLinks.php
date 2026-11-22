<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\PermissionCatalogue;
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
     * `spaces`: Classic only (CO-1, 19 Nov 2026), the account menu's Spaces row, shown to everyone who can enter at least
     * one department space (Me is its own row). Before CO-1 it showed only with "Try the new layout" switched on.
     *
     * @return array{links: list<array{title: string, href: string}>, clock: bool, spaces: bool}
     */
    public function for(User $actor): array
    {
        $links = [];
        // DAY-1a: My day first, and the Main Menu as a link (owner, 22 Nov 2026). Neither is shown to the TV account, which gets none.
        $human = ! $actor->hasRole(PermissionCatalogue::QUEUE_DISPLAY_ROLE);
        if ($human) {
            $links[] = ['title' => 'My day', 'href' => route('my-day', absolute: false)];
        }
        if ($human && $actor->can('dashboard.view.own')) {
            $links[] = ['title' => 'Main menu', 'href' => route('dashboard', absolute: false)];
        }
        $keys = array_column($this->spaces->spaces($actor), 'key');
        if (in_array(DepartmentSpaceNavigation::ME[0], $keys, true)) {
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

        return ['links' => $links, 'clock' => $actor->can('hr.attendance.clock.own'), 'spaces' => array_diff($keys, [DepartmentSpaceNavigation::ME[0]]) !== []];
    }
}
