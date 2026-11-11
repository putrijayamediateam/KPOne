<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\ClinicManagementNavigation;
use App\Domain\Identity\Services\StaffDirectoryService;
use App\Domain\Organisation\BusinessDetails\DocumentReadinessService;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * DS-10: the Management space home. Every widget calls the page's own service under that page's own permission: the staff
 * directory (counts only), Document readiness (a summary line) and the Clinic management hub's own links. A staff row
 * shows a short name, role and branch only; no email, phone or staff number is read from it. No patient data.
 */
final class ManagementSpace
{
    public const LINK_LIMIT = 6;

    public function __construct(
        private BranchAccessService $branches,
        private StaffDirectoryService $staff,
        private DocumentReadinessService $documents,
        private ClinicManagementNavigation $hub,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $staff = $this->staffCounts($actor);
        $documents = $this->documentSummary($actor);
        $links = [];
        if ($branch !== null) {
            foreach ($this->hub->groups($actor) as $group) {
                if ($group['title'] === 'Staff & branches') {
                    foreach ($group['links'] as $link) {
                        $links[] = ['title' => (string) $link['title'], 'href' => (string) $link['href']];
                    }
                }
            }
        }
        $primary = $branch !== null && $actor->can('staff.view.organisation')
            ? ['title' => 'Open Staff directory', 'href' => route('staff.index', absolute: false)]
            : ($branch !== null && $actor->can('catalogue.settings.manage.organisation') ? ['title' => 'Open Business details', 'href' => route('business-details.index', absolute: false)] : null);

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'staff' => $staff === null ? null : $staff + ['href' => route('staff.index', absolute: false)],
            'documents' => $documents === null ? null : ['text' => $documents['text'], 'attention' => $documents['attention'], 'href' => route('business-details.index', absolute: false)],
            'primaryAction' => $primary,
            'links' => array_slice($links, 0, self::LINK_LIMIT),
        ];
    }

    /**
     * Active and inactive staff accounts: the directory's own paginator totals.
     *
     * @return array{active: int, inactive: int}|null
     */
    public function staffCounts(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('staff.view.organisation')) {
            return null;
        }

        return [
            'active' => $this->staff->search($actor, ['status' => 'active'])->total(),
            'inactive' => $this->staff->search($actor, ['status' => 'inactive'])->total(),
        ];
    }

    /**
     * Inactive staff accounts (first 10 of the directory's own inactive list): short name, role and branch only.
     *
     * @return list<array{name: string, role: string, branch: string}>|null
     */
    public function inactiveStaff(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('staff.view.organisation')) {
            return null;
        }
        $rows = [];
        foreach ($this->staff->search($actor, ['status' => 'inactive'])->items() as $r) {
            $roles = $r['roles'];
            $first = $roles instanceof Collection ? $roles->first() : (is_array($roles) ? ($roles[0] ?? null) : null);
            $rows[] = [
                'name' => SpaceDisplay::shortName((string) $r['name']),
                'role' => ucfirst(str_replace('_', ' ', is_string($first) ? $first : '')),
                'branch' => (string) (($r['primaryBranch']['name'] ?? null) ?? 'No current branch'),
            ];
        }

        return array_slice($rows, 0, 10);
    }

    /**
     * One line from Document readiness, and the branches that need attention with what is missing.
     *
     * @return array{text: string, attention: list<array{name: string, text: string}>}|null
     */
    public function documentSummary(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('catalogue.settings.manage.organisation')) {
            return null;
        }
        $rows = $this->documents->branches($actor);
        $ready = 0;
        $footer = 0;
        $blocked = 0;
        $attention = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'ready') {
                $ready++;

                continue;
            }
            if ($r['status'] === 'needs_footer') {
                $footer++;
                $attention[] = ['name' => (string) $r['name'], 'text' => 'Needs its invoice and receipt footer'];

                continue;
            }
            $blocked++;
            $attention[] = ['name' => (string) $r['name'], 'text' => 'Letters blocked: '.implode(', ', (array) $r['blockedLetters']).'. Missing: '.implode(', ', (array) $r['missing'])];
        }
        $n = count($rows);

        return [
            'text' => $ready.' of '.$n.($n === 1 ? ' branch is' : ' branches are').' ready, '.$footer.' need'.($footer === 1 ? 's' : '').' a footer, '.$blocked.($blocked === 1 ? ' has' : ' have').' letters blocked.',
            'attention' => $attention,
        ];
    }
}
