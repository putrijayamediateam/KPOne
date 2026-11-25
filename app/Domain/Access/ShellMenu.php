<?php

namespace App\Domain\Access;

use App\Models\User;

/**
 * Clean Look shell: the icon rail's areas and each area's menu panel, built from the same permission-filtered rows as Clinic
 * management (never from a role table), so a link shows only for someone who may open its page. An area with no visible link is
 * left out. Today (My day and the approvals) is always first. Reading only; nothing is cached.
 */
final class ShellMenu
{
    /** area key => [title, icon, href prefixes] in rail order; anything not matched falls into "settings". */
    private const AREAS = [
        'front_desk' => ['Front desk', 'clipboard', ['/registration', '/patients', '/patient-merges', '/check-in']],
        'consultation' => ['Consultation', 'stethoscope', ['/queue', '/consultation', '/encounters', '/triage', '/treatment', '/queue-display']],
        'dispensary' => ['Dispensary', 'pill', ['/dispensary']],
        'billing' => ['Billing', 'receipt', ['/payments', '/payment-', '/pay-later', '/cash-closings', '/billing', '/outstanding', '/invoices', '/terminal']],
        'panel' => ['Panel', 'building', ['/panel', '/checkout-settings']],
        'inventory' => ['Inventory', 'package', ['/inventory', '/stock', '/pricing', '/medicines', '/clinical-services', '/suppliers']],
        'hr' => ['HR', 'users', ['/hr']],
        'reports' => ['Reports', 'chart', ['/reports', '/insights', '/agents']],
        'settings' => ['Settings', 'settings', []],
    ];

    public function __construct(private ClinicManagementNavigation $management) {}

    /**
     * @return list<array{key: string, title: string, icon: string, href: string, sections: list<array{title: string, links: list<array{title: string, href: string}>}>}>
     */
    public function for(User $actor): array
    {
        $areas = [];
        $today = [['title' => 'My day', 'href' => route('my-day', absolute: false)]];
        $areas['today'] = ['key' => 'today', 'title' => 'Today', 'icon' => 'home', 'sections' => [['title' => 'Today', 'links' => $today]]];

        $bySection = [];
        foreach ($this->management->groupsForMenu($actor) as $group) {
            foreach ($group['links'] as $link) {
                $key = $this->areaOf($link['href']);
                if ($link['href'] === route('approvals.index', absolute: false)) {
                    $areas['today']['sections'][0]['links'][] = ['title' => $link['title'], 'href' => $link['href']];

                    continue;
                }
                $bySection[$key][$group['title']][] = ['title' => $link['title'], 'href' => $link['href']];
            }
        }

        foreach (self::AREAS as $key => [$title, $icon]) {
            if (! isset($bySection[$key])) {
                continue;
            }
            $sections = [];
            foreach ($bySection[$key] as $sectionTitle => $links) {
                $sections[] = ['title' => $sectionTitle, 'links' => $links];
            }
            $areas[$key] = ['key' => $key, 'title' => $title, 'icon' => $icon, 'sections' => $sections];
        }

        return array_values(array_map(static fn (array $a): array => $a + ['href' => $a['sections'][0]['links'][0]['href']], $areas));
    }

    private function areaOf(string $href): string
    {
        foreach (self::AREAS as $key => [, , $prefixes]) {
            foreach ($prefixes as $prefix) {
                if ($href === $prefix || str_starts_with($href, $prefix)) {
                    return $key;
                }
            }
        }

        return 'settings';
    }
}
