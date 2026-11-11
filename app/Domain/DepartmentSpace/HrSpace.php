<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\Services\AttendancePageService;
use App\Domain\HR\Claims\Services\ClaimsPageService;
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
            [$actor->can('hr.roster.manage.organisation') || $actor->can('hr.leave.manage.organisation') || $actor->can('hr.attendance.review.organisation'), 'HR dashboard', 'hr.dashboard.index'],
            [$actor->can('hr.roster.manage.organisation') || $actor->can('hr.roster.approve.organisation'), 'Roster', 'hr.roster.index'],
            [$actor->can('hr.leave.approve.organisation') || $actor->can('hr.leave.manage.organisation'), 'Leave', 'hr.leave.index'],
            [$actor->can('hr.attendance.review.organisation'), 'Attendance', 'hr.attendance.index'],
            [$actor->can('hr.claims.approve.organisation'), 'Staff claims', 'hr.claims.index'],
        ] as [$ok, $title, $route]) {
            if ($hasBranch && $ok) {
                $links[] = ['title' => $title, 'href' => route($route, absolute: false)];
            }
        }
        $first = $approvals === null ? null : collect($approvals['kinds'])->firstWhere('count', '>', 0);
        $primary = $first !== null
            ? ['title' => 'Open approvals', 'href' => $first['href']]
            : ($hasBranch && ($actor->can('hr.roster.manage.organisation') || $actor->can('hr.leave.manage.organisation') || $actor->can('hr.attendance.review.organisation'))
                ? ['title' => 'Open HR dashboard', 'href' => route('hr.dashboard.index', absolute: false)] : null);

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
     * @return array{total: int, kinds: list<array{key: string, title: string, count: int, href: string}>}|null
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
        if ($kinds === []) {
            return null;
        }

        return ['total' => array_sum(array_column($kinds, 'count')), 'kinds' => $kinds];
    }

    /**
     * Who is on leave today and this week at the active branch, from the leave page's own team calendar: "On leave" or
     * "half day" only, never the type or reason.
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
        $todayRows = [];
        $weekRows = [];
        foreach ((array) ($page['calendar']['rows'] ?? []) as $row) {
            $cells = (array) $row['cells'];
            $name = SpaceDisplay::shortName((string) $row['name']);
            if (isset($cells[$today->toDateString()])) {
                $todayRows[] = ['name' => $name, 'text' => $cells[$today->toDateString()] === 'half' ? 'Half day' : 'On leave'];
            }
            $days = 0;
            foreach ($cells as $date => $kind) {
                $d = CarbonImmutable::parse((string) $date);
                if ($d->greaterThanOrEqualTo($weekStart) && $d->lessThanOrEqualTo($weekEnd)) {
                    $days += $kind === 'half' ? 0 : 1;
                    $weekRows[$name]['half'] = ($weekRows[$name]['half'] ?? 0) + ($kind === 'half' ? 1 : 0);
                }
            }
            if ($days > 0 || ($weekRows[$name]['half'] ?? 0) > 0) {
                $weekRows[$name]['days'] = $days;
            }
        }
        $week = [];
        foreach ($weekRows as $name => $v) {
            $d = (int) ($v['days'] ?? 0);
            $h = (int) ($v['half'] ?? 0);
            $week[] = ['name' => (string) $name, 'text' => trim(($d > 0 ? $d.($d === 1 ? ' day' : ' days') : '').($d > 0 && $h > 0 ? ' and ' : '').($h > 0 ? $h.($h === 1 ? ' half day' : ' half days') : ''))];
        }

        return ['today' => $todayRows, 'week' => $week];
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
        $status = ($planner['approvedVersion'] ?? null) !== null ? 'Approved' : (($planner['openVersion'] ?? null) !== null ? 'Draft' : 'No roster yet');

        return ['label' => (string) $page['week']['label'], 'status' => $status, 'shortDays' => count((array) ($planner['shortDays'] ?? [])), 'href' => route('hr.roster.index', absolute: false)];
    }
}
