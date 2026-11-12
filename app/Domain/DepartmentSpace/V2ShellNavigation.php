<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\ClinicManagementNavigation;
use App\Models\User;

/**
 * UX-17: the Version 2 sidebar around every working page (GUIDE sections 18 and 19). Built on the server from the
 * person's own department spaces and their own Clinic management rows, so every link is one they can already open.
 * Navigation only: it grants nothing, and each page's controller stays the authority for access and branch scope.
 */
final class V2ShellNavigation
{
    /** Sidebar groups of spaces, in order (the same grouping as the spaces home). "Me" lives in the account menu. */
    public const GROUPS = [
        ['Clinic', ['front_desk', 'pharmacy', 'consultation']],
        ['Money', ['billing', 'panel']],
        ['Operations', ['stock', 'hr', 'management']],
    ];

    /**
     * Which space a working page belongs to, by its first path segment, for the small crumb above the page title.
     * A page not listed here shows no crumb; it is still reachable from "More".
     */
    public const SPACE_FOR_PATH = [
        'registration' => 'front_desk',
        'patients' => 'front_desk',
        'dispensary' => 'pharmacy',
        'queue' => 'consultation',
        'encounters' => 'consultation',
        'billing' => 'billing',
        'cash-closings' => 'billing',
        'pay-later-collections' => 'billing',
        'panel-claims' => 'panel',
        'panel-invoices' => 'panel',
        'panel-remittances' => 'panel',
        'inventory' => 'stock',
        'hr' => 'hr',
        'business-details' => 'management',
    ];

    public function __construct(private DepartmentSpaceNavigation $spaces, private ClinicManagementNavigation $hub) {}

    /**
     * @return array{
     *     groups: list<array{title: string, items: list<array{key: string, title: string, href: string}>}>,
     *     more: list<array{title: string, links: list<array{title: string, href: string}>}>,
     *     current: array{key: string, title: string, href: string}|null
     * }
     */
    public function for(User $actor, string $path): array
    {
        $spaces = [];
        foreach ($this->spaces->spaces($actor) as $space) {
            $spaces[$space['key']] = ['key' => $space['key'], 'title' => $space['title'], 'href' => route('spaces.show', ['space' => $space['key']], absolute: false)];
        }

        $groups = [];
        foreach (self::GROUPS as [$title, $keys]) {
            $items = array_values(array_filter(array_map(fn (string $key) => $spaces[$key] ?? null, $keys)));
            if ($items !== []) {
                $groups[] = ['title' => $title, 'items' => $items];
            }
        }

        $more = [];
        foreach ($this->hub->groups($actor) as $group) {
            $more[] = [
                'title' => $group['title'],
                'links' => array_map(fn (array $link) => ['title' => $link['title'], 'href' => $link['href']], $group['links']),
            ];
        }

        $segment = explode('/', trim($path, '/'))[0];
        $currentKey = self::SPACE_FOR_PATH[$segment] ?? null;

        return [
            'groups' => $groups,
            'more' => $more,
            'current' => $currentKey !== null ? ($spaces[$currentKey] ?? null) : null,
        ];
    }
}
