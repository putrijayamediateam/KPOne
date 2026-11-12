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
     * Which space a page belongs to, by its first path segment (GUIDE section 18; Daniel, 2026-11-12). It drives the
     * crumb above the page title and where a Clinic management page is listed: under its space when the person can
     * enter that space, otherwise under "More". A page not listed here belongs to no space and stays under "More".
     */
    public const SPACE_FOR_PATH = [
        'registration' => 'front_desk',
        'registration-review' => 'front_desk',
        'patients' => 'front_desk',
        'queue' => 'consultation',
        'encounters' => 'consultation',
        'dispensary' => 'pharmacy',
        'billing' => 'billing',
        'financial-work' => 'billing',
        'pay-later-collections' => 'billing',
        'payment-reconciliations' => 'billing',
        'cash-closings' => 'billing',
        'panel-claims' => 'panel',
        'panel-invoices' => 'panel',
        'panel-remittances' => 'panel',
        'panel-payment-corrections' => 'panel',
        'panel-responsibility' => 'panel',
        'inventory' => 'stock',
        'medicines' => 'stock',
        'clinical-services' => 'stock',
        'pricing' => 'stock',
        'inventory-services-settings' => 'stock',
        'hr' => 'hr',
        'business-details' => 'management',
        'registration-settings' => 'management',
        'consultation-settings' => 'management',
        'checkout-settings' => 'management',
        'payment-methods' => 'management',
        'billing-approval-limits' => 'management',
        'public-checkin-links' => 'management',
        'staff' => 'management',
        'branches' => 'management',
        'access-control' => 'management',
        'audit-logs' => 'management',
    ];

    /**
     * Version 2 sidebar labels in sentence case (Daniel, 2026-11-12). V2 only: Clinic management, Classic pages and
     * every page title keep their own names. Proper names (Panel, Little Shield, KPOne HR, SOPs, QR, TV) keep capitals.
     */
    public const V2_LABELS = [
        'Access Control' => 'Access control',
        'Billing Approval Limits' => 'Billing approval limits',
        'Billing Detail' => 'Billing detail',
        'Clinical Service Catalogue' => 'Clinical service catalogue',
        'KPOne HR Memos' => 'KPOne HR memos',
        'Medicine Catalogue' => 'Medicine catalogue',
        'Panel Claims' => 'Panel claims',
        'Panel Responsibility' => 'Panel responsibility',
        'Patient Records' => 'Patient records',
        'Payment Methods' => 'Payment methods',
        'QR Intake' => 'QR intake',
        'Queue Display settings' => 'Queue display settings',
        'TV Screen' => 'TV screen',
        'Terminal Reconciliation' => 'Terminal reconciliation',
        'Transaction Detail' => 'Transaction detail',
    ];

    public static function label(string $title): string
    {
        return self::V2_LABELS[$title] ?? $title;
    }

    public function __construct(private DepartmentSpaceNavigation $spaces, private ClinicManagementNavigation $hub) {}

    /**
     * @return array{
     *     groups: list<array{title: string, items: list<array{key: string, title: string, href: string, links: list<array{title: string, href: string}>}>}>,
     *     more: list<array{title: string, links: list<array{title: string, href: string}>}>,
     *     current: array{key: string, title: string, href: string}|null
     * }
     */
    public function for(User $actor, string $path): array
    {
        /** @var array<string, array{key: string, title: string, href: string, links: list<array{title: string, href: string}>}> $spaces */
        $spaces = [];
        foreach ($this->spaces->spaces($actor) as $space) {
            $spaces[$space['key']] = ['key' => $space['key'], 'title' => $space['title'], 'href' => route('spaces.show', ['space' => $space['key']], absolute: false), 'links' => []];
        }

        // A Clinic management page goes under its space when the person can enter that space, otherwise under "More".
        $more = [];
        foreach ($this->hub->groups($actor) as $group) {
            $left = [];
            foreach ($group['links'] as $link) {
                $row = ['title' => self::label($link['title']), 'href' => $link['href']];
                $space = self::spaceFor($link['href']);
                if ($space !== null && isset($spaces[$space])) {
                    $spaces[$space]['links'][] = $row;
                } else {
                    $left[] = $row;
                }
            }
            if ($left !== []) {
                $more[] = ['title' => $group['title'], 'links' => $left];
            }
        }

        $groups = [];
        foreach (self::GROUPS as [$title, $keys]) {
            $items = [];
            foreach ($keys as $key) {
                if (isset($spaces[$key])) {
                    $items[] = $spaces[$key];
                }
            }
            if ($items !== []) {
                $groups[] = ['title' => $title, 'items' => $items];
            }
        }

        $currentKey = self::spaceFor($path);
        $current = $currentKey !== null && isset($spaces[$currentKey])
            ? ['key' => $spaces[$currentKey]['key'], 'title' => $spaces[$currentKey]['title'], 'href' => $spaces[$currentKey]['href']]
            : null;

        return ['groups' => $groups, 'more' => $more, 'current' => $current];
    }

    /** The space a path or href belongs to, by its first segment; null for a page that belongs to no space. */
    public static function spaceFor(string $pathOrHref): ?string
    {
        $path = (string) parse_url($pathOrHref, PHP_URL_PATH);

        return self::SPACE_FOR_PATH[explode('/', trim($path, '/'))[0]] ?? null;
    }
}
