<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\Services\AttendancePageService;
use App\Domain\HR\Claims\Services\ClaimsPageService;
use App\Domain\HR\Credentials\Services\CredentialsPageService;
use App\Domain\HR\Leave\Services\LeavePageService;
use App\Domain\HR\Roster\Services\RosterPageService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * DS-9: the HR space home. Every widget calls the HR page's own service under that page's own permission; a widget the
 * person cannot open on its page is left out. Counts and short names only: no leave type or reason, no salary and no
 * per-person score. HrDashboardService is not called (each call writes an audit row and carries the staff cost), so the
 * space only links to the HR dashboard.
 */
final class HrSpace
{
    public const LIST_LIMIT = 5;

    public function __construct(
        private BranchAccessService $branches,
        private LeavePageService $leave,
        private AttendancePageService $attendance,
        private ClaimsPageService $claims,
        private RosterPageService $roster,
        private CredentialsPageService $credentials,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $approvals = $this->approvals($actor);
        $away = $this->away($actor);
        $roster = $this->rosterWeek($actor);
        $links = [];
        $hasBranch = $branch !== null;
        foreach ([
            [$actor->can('hr.roster.manage.organisation') || $actor->can('hr.leave.manage.organisation') || $actor->can('hr.attendance.review.organisation'), 'Who is on duty today', 'hr.dashboard.index'],
            [$actor->can('hr.roster.manage.organisation') || $actor->can('hr.roster.approve.organisation'), 'Roster', 'hr.roster.index'],
            [$actor->can('hr.leave.approve.organisation') || $actor->can('hr.leave.manage.organisation'), 'Leave', 'hr.leave.index'],
            [$actor->can('hr.attendance.review.organisation'), 'Attendance', 'hr.attendance.index'],
            [$actor->can('hr.claims.approve.organisation'), 'Staff claims', 'hr.claims.index'],
        ] as [$ok, $title, $route]) {
            if ($hasBranch && $ok) {
                $links[] = ['title' => $title, 'href' => route($route, absolute: false)];
            }
        }
        $first = $approvals === null ? null : collect($approvals['kinds'])->first();
        $canRoster = $hasBranch && $actor->can('hr.roster.manage.organisation');
        $primary = $first !== null
            ? ['title' => 'Open approvals', 'href' => $first['href']]
            : ($canRoster ? ['title' => 'Open roster', 'href' => route('hr.roster.index', absolute: false)]
                : ($hasBranch && ($actor->can('hr.leave.manage.organisation') || $actor->can('hr.attendance.review.organisation'))
                    ? ['title' => 'Open HR dashboard', 'href' => route('hr.dashboard.index', absolute: false)] : null));

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'approvals' => $approvals === null ? null : ['total' => $approvals['total'], 'kinds' => $approvals['kinds']],
            'away' => $away === null ? null : ['total' => count($away['today']), 'limit' => self::LIST_LIMIT, 'seeAllHref' => route('hr.leave.index', ['tab' => 'calendar'], absolute: false), 'rows' => array_slice($away['today'], 0, self::LIST_LIMIT)],
            'roster' => $roster,
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /**
     * Waiting approvals per kind, each from its own page service and permission.
     *
     * @return array{total: int, kinds: list<array<string, mixed>>}|null
     */
    public function approvals(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null) {
            return null;
        }
        $kinds = [];
        if ($actor->can('hr.leave.approve.organisation')) {
            $kinds[] = ['key' => 'leave', 'title' => 'Leave requests', 'count' => (int) $this->leave->page($actor, ['tab' => 'approvals'])['approvalCount'], 'href' => route('hr.leave.index', ['tab' => 'approvals'], absolute: false)];
        }
        if ($actor->can('hr.attendance.review.organisation') || $actor->can('hr.overtime.approve.organisation')) {
            $att = $this->attendance->page($actor, []);
            if ($actor->can('hr.attendance.review.organisation')) {
                $kinds[] = ['key' => 'corrections', 'title' => 'Attendance corrections', 'count' => (int) $att['pendingCount'], 'href' => route('hr.attendance.index', ['tab' => 'review'], absolute: false)];
            }
            if ($actor->can('hr.overtime.approve.organisation')) {
                $kinds[] = ['key' => 'overtime', 'title' => 'Overtime requests', 'count' => (int) $att['overtimePendingCount'], 'href' => route('hr.attendance.index', ['tab' => 'overtime'], absolute: false)];
            }
        }
        if ($actor->can('hr.claims.approve.organisation')) {
            $branch = $this->branches->activeBranch($actor);
            $claims = $this->claims->page($actor, $branch, ['tab' => 'approvals']);
            $kinds[] = ['key' => 'claims', 'title' => 'Staff claims', 'count' => count((array) ($claims['approvals']['claims'] ?? [])), 'href' => route('hr.claims.index', ['tab' => 'approvals'], absolute: false)];
        }
        if ($kinds === [] && ! $this->mayApprove($actor)) {
            return null;
        }
        $kinds = array_values(array_filter($kinds, fn (array $k): bool => $k['count'] > 0));
        foreach ($kinds as &$k) {
            $k['detail'] = $k['key'] === 'leave' ? $this->earliestLeave($actor) : null;
        }
        unset($k);

        return ['total' => array_sum(array_column($kinds, 'count')), 'kinds' => $kinds];
    }

    private function mayApprove(User $actor): bool
    {
        return $actor->can('hr.leave.approve.organisation') || $actor->can('hr.attendance.review.organisation') || $actor->can('hr.overtime.approve.organisation') || $actor->can('hr.claims.approve.organisation');
    }

    /**
     * When the earliest waiting leave starts, from the leave Approvals tab's own rows (ordered by start date). The page
     * rows carry no sent-at time, so how long a request has waited is not known here.
     */
    private function earliestLeave(User $actor): ?string
    {
        $page = $this->leave->page($actor, ['tab' => 'approvals']);
        $first = ((array) ($page['approvals']['pending'] ?? []))[0] ?? null;
        if (! is_array($first) || ! isset($first['startDate'])) {
            return null;
        }
        $days = (int) CarbonImmutable::parse((string) $page['today'])->startOfDay()->diffInDays(CarbonImmutable::parse((string) $first['startDate'])->startOfDay(), false);

        $text = $days <= 0 ? 'Earliest leave started or starts today' : 'Earliest leave starts in '.$days.($days === 1 ? ' day' : ' days');

        return $days <= 2 ? $text.' (soon)' : $text;
    }

    /**
     * Who is on leave today and this week at the active branch, from the leave page's own team calendar (one month at a
     * time, so the other month is read too when the week crosses a month edge): "On leave until ..." or "Half day"
     * only, never the type or reason. People are kept apart by calendar row, not by short name.
     *
     * @return array{today: list<array{name: string, text: string}>, week: list<array{name: string, text: string}>}|null
     */
    public function away(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || ! $actor->can('hr.leave.manage.organisation')) {
            return null;
        }
        $page = $this->leave->page($actor, ['tab' => 'calendar', 'branch' => $branch->id]);
        $today = CarbonImmutable::parse((string) $page['today'])->startOfDay();
        $weekStart = $today->startOfWeek();
        $weekEnd = $today->endOfWeek()->startOfDay();
        // Cells per calendar row (full name as the key), merged across the months the week and the leave touch.
        $people = [];
        $merge = function (array $calendar) use (&$people): void {
            foreach ((array) ($calendar['rows'] ?? []) as $row) {
                $key = (string) $row['name'];
                $people[$key] = ((array) ($people[$key] ?? [])) + (array) $row['cells'];
            }
        };
        $merge((array) $page['calendar']);
        $otherMonths = [];
        foreach ([$weekStart, $weekEnd, $today->addDays(14)] as $d) {
            if ($d->format('Y-m') !== $today->format('Y-m')) {
                $otherMonths[$d->format('Y-m')] = true;
            }
        }
        foreach (array_keys($otherMonths) as $month) {
            $merge((array) ($this->leave->page($actor, ['tab' => 'calendar', 'branch' => $branch->id, 'month' => $month])['calendar'] ?? []));
        }

        $todayRows = [];
        $week = [];
        foreach ($people as $fullName => $cells) {
            ksort($cells);
            $name = SpaceDisplay::shortName((string) $fullName);
            $mine = $cells[$today->toDateString()] ?? null;
            if ($mine !== null) {
                $todayRows[] = ['name' => $name, 'text' => $mine === 'half' ? 'Half day' : 'On leave until '.$this->runEnd($cells, $today)->format('j M Y')];
            }
            $days = 0;
            $halves = 0;
            foreach ($cells as $date => $kind) {
                $d = CarbonImmutable::parse((string) $date);
                if ($d->greaterThanOrEqualTo($weekStart) && $d->lessThanOrEqualTo($weekEnd)) {
                    $kind === 'half' ? $halves++ : $days++;
                }
            }
            if ($days > 0 || $halves > 0) {
                $week[] = ['name' => $name, 'text' => trim(($days > 0 ? $days.($days === 1 ? ' day' : ' days') : '').($days > 0 && $halves > 0 ? ' and ' : '').($halves > 0 ? $halves.($halves === 1 ? ' half day' : ' half days') : ''))];
            }
        }

        return ['today' => $todayRows, 'week' => $week];
    }

    /**
     * The last day of the run of leave that includes `$from`: cells are working days only, so a weekend or holiday gap
     * of up to three days does not end the run.
     *
     * @param  array<string, string>  $cells
     */
    private function runEnd(array $cells, CarbonImmutable $from): CarbonImmutable
    {
        $end = $from;
        foreach (array_keys($cells) as $date) {
            $d = CarbonImmutable::parse((string) $date);
            if ($d->greaterThan($end) && (int) $end->diffInDays($d) <= 3) {
                $end = $d;
            }
        }

        return $end;
    }

    /**
     * This week's roster for the active branch: Approved, Draft or none, and how many days are short, from the
     * roster planner's own figures.
     *
     * @return array{label: string, status: string, shortDays: int, href: string}|null
     */
    public function rosterWeek(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.roster.manage.organisation')) {
            return null;
        }
        $page = $this->roster->page($actor, ['tab' => 'planner']);
        $planner = (array) $page['planner'];
        $open = $planner['openVersion'] ?? null;
        $status = ($planner['approvedVersion'] ?? null) !== null ? 'Approved'
            : (is_array($open) ? (($open['status'] ?? '') === 'draft' ? 'Draft, not sent for approval' : 'Waiting for approval') : 'No roster yet');

        return ['label' => (string) $page['week']['label'], 'status' => $status, 'shortDays' => count((array) ($planner['shortDays'] ?? [])), 'href' => route('hr.roster.index', absolute: false)];
    }

    /**
     * Credentials due for renewal soon, from the credentials register's own "expiring" filter: name, credential and expiry
     * date only (no reference). How soon is each credential type's own warning window.
     *
     * @return list<array{name: string, credential: string, expires: string}>|null
     */
    public function credentialsDue(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.credentials.manage.organisation')) {
            return null;
        }
        $register = (array) ($this->credentials->page($actor, ['tab' => 'register', 'status' => 'expiring'])['register'] ?? []);
        $types = [];
        foreach ((array) ($register['types'] ?? []) as $t) {
            $types[(string) $t['typeId']] = (string) $t['name'];
        }
        $due = [];
        $today = now()->setTimezone((string) $this->branches->activeBranch($actor)->timezone)->startOfDay()->toImmutable();
        foreach ((array) ($register['rows'] ?? []) as $row) {
            foreach ((array) $row['cells'] as $typeId => $cell) {
                if (is_array($cell) && ($cell['status']['code'] ?? '') === 'expiring' && ($cell['expiresOn'] ?? null) !== null) {
                    $due[] = ['name' => SpaceDisplay::shortName((string) $row['name']), 'credential' => $types[(string) $typeId] ?? 'Credential', 'expires' => self::expiryWords($today, (string) $cell['expiresOn']), 'on' => (string) $cell['expiresOn']];
                }
            }
        }
        usort($due, fn (array $a, array $b): int => strcmp($a['on'], $b['on']));

        return array_map(fn (array $d): array => ['name' => $d['name'], 'credential' => $d['credential'], 'expires' => $d['expires']], $due);
    }

    private static function expiryWords(CarbonImmutable $today, string $on): string
    {
        $date = CarbonImmutable::parse($on)->startOfDay();
        $days = (int) $today->diffInDays($date, false);
        $words = $days === 0 ? 'due today' : ($days > 0 ? 'due in '.$days.($days === 1 ? ' day' : ' days') : 'expired '.abs($days).(abs($days) === 1 ? ' day' : ' days').' ago');

        return $date->format('j M Y').', '.$words;
    }
}
