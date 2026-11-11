<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
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
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $staff = $this->staffCounts($actor);
        $documents = $this->documentSummary($actor);
        $links = $this->links($actor, (bool) $branch);
        $primary = $branch !== null && $actor->can('staff.view.organisation')
            ? ['title' => 'Open Staff directory', 'href' => route('staff.index', absolute: false)]
            : ($branch !== null && $actor->can('catalogue.settings.manage.organisation') ? ['title' => 'Open Business details', 'href' => route('business-details.index', absolute: false)] : null);

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'staff' => $staff,
            'documents' => $documents === null ? null : ['text' => $documents['text'], 'warning' => $documents['warning'], 'attention' => $documents['attention']],
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /**
     * Fixed rows, each under its own permission. The header action and the cards carry their own links.
     *
     * @return list<array{title: string, href: string}>
     */
    private function links(User $actor, bool $hasBranch): array
    {
        if (! $hasBranch) {
            return [];
        }
        $rows = [];
        if ($actor->can('branches.view.branch') || $actor->can('branches.view.organisation')) {
            $rows[] = ['title' => 'Branches', 'href' => route('branches.index', absolute: false)];
        }
        if ($actor->can('access.view.organisation')) {
            $rows[] = ['title' => 'Roles and access', 'href' => route('access-control.index', absolute: false)];
        }
        if ($actor->can('clinician_credentials.manage.organisation')) {
            $rows[] = ['title' => 'Clinician credentials', 'href' => route('clinician-credentials.index', absolute: false)];
        }
        if ($actor->can('catalogue.settings.manage.organisation')) {
            $rows[] = ['title' => 'Registration settings', 'href' => route('registration-settings.index', absolute: false)];
        }

        return $rows;
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
     * @return array{text: string, warning: bool, attention: list<array{name: string, text: string}>}|null
     */
    public function documentSummary(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('catalogue.settings.manage.organisation')) {
            return null;
        }
        $rows = $this->documents->branches($actor);
        $n = count($rows);
        $ready = 0;
        $footer = 0;
        $blocked = 0;
        $groups = [];
        foreach ($rows as $r) {
            if ($r['status'] === 'ready') {
                $ready++;

                continue;
            }
            if ($r['status'] === 'needs_footer') {
                $footer++;
                $groups['footer|'][] = (string) $r['name'];

                continue;
            }
            $blocked++;
            $missing = implode(', ', array_map(fn ($m) => (string) $m, (array) $r['missing']));
            $groups['blocked|'.$missing][] = (string) $r['name'];
        }
        $attention = [];
        foreach ($groups as $key => $names) {
            $attention[] = ['name' => implode(', ', $names), 'text' => str_starts_with($key, 'footer|')
                ? 'Needs its invoice and receipt footer'
                : 'Letters blocked. Missing: '.substr($key, 8)];
        }
        $clauses = [];
        if ($blocked > 0) {
            $clauses[] = $blocked.' of '.$n.($n === 1 ? ' branch has' : ' branches have').' letters blocked';
        }
        if ($footer > 0) {
            $clauses[] = $footer.' need'.($footer === 1 ? 's' : '').' a footer';
        }
        if ($ready > 0 && $clauses !== []) {
            $clauses[] = $ready.' ready';
        }
        $text = $clauses === [] ? 'All '.$n.($n === 1 ? ' branch is' : ' branches are').' ready for letters.' : implode(' · ', $clauses);

        return ['text' => $text, 'warning' => $clauses !== [], 'attention' => $attention];
    }
}
