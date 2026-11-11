<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\Services\AttendancePageService;
use App\Domain\HR\Claims\Services\ClaimsPageService;
use App\Domain\HR\Credentials\Services\CredentialsPageService;
use App\Domain\HR\Leave\Services\LeavePageService;
use App\Domain\HR\Payroll\Services\PayslipService;
use App\Domain\HR\Roster\Services\RosterPageService;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * DS-11: the Me space home. Everything here is the signed-in person's own data, read from the existing own-scope HR
 * pages under their own `.own` permissions. No amount, reason, purpose, reference number or file is read into the space:
 * the leave reason, claim purpose, credential reference and payslip amounts stay on their own pages. A widget the
 * person cannot open on its page is left out.
 */
final class MeSpace
{
    public const LIST_LIMIT = 5;

    public function __construct(
        private BranchAccessService $branches,
        private RosterPageService $roster,
        private AttendancePageService $attendance,
        private LeavePageService $leave,
        private ClaimsPageService $claims,
        private CredentialsPageService $credentials,
        private PayslipService $payslips,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function home(User $actor): array
    {
        $branch = $this->branches->activeBranch($actor);
        $primary = null;
        if ($branch !== null && $actor->can('hr.attendance.clock.own')) {
            $primary = ['title' => 'Open attendance', 'href' => route('hr.attendance.index', absolute: false)];
        } elseif ($branch !== null && $actor->can('hr.roster.view.own')) {
            $primary = ['title' => 'Open my roster', 'href' => route('hr.roster.index', absolute: false)];
        }
        $links = [];
        if ($branch !== null) {
            foreach ([
                [$actor->can('hr.roster.view.own'), 'My roster', 'hr.roster.index'],
                [$actor->can('hr.leave.request.own'), 'My leave', 'hr.leave.index'],
                [$actor->can('hr.attendance.clock.own'), 'My attendance', 'hr.attendance.index'],
                [$actor->can('hr.claims.submit.own'), 'My claims', 'hr.claims.index'],
                [$actor->can('hr.records.view.own'), 'My HR details', 'hr.records.index'],
                [$actor->can('hr.payslips.view.own') && $actor->can('hr.records.view.own'), 'My payslips', 'hr.records.index'],
            ] as [$ok, $title, $route]) {
                $href = route($route, absolute: false);
                if ($ok && ($primary === null || $primary['href'] !== $href)) {
                    $links[] = ['title' => $title, 'href' => $href];
                }
            }
        }
        $payslip = $this->latestPayslip($actor);

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'shift' => $this->nextShift($actor),
            'clock' => $this->clock($actor),
            'leave' => $this->leaveLeft($actor),
            'claims' => $this->claimCounts($actor),
            'credentials' => $this->credentialsDue($actor),
            'payslip' => $payslip,
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /**
     * The next shift: today's or the next one in this week or next week, from My roster.
     *
     * @return array{text: string, today: bool}|null
     */
    public function nextShift(User $actor): ?array
    {
        $days = $this->shiftDays($actor);
        if ($days === null) {
            return null;
        }
        foreach ($days['days'] as $date => $d) {
            if ($date >= $days['today']) {
                $s = $d['shifts'][0];

                return ['text' => $d['label'].', '.$s['startLabel'].' to '.$s['endLabel'].', '.$s['branch'], 'today' => $date === $days['today']];
            }
        }

        return ['text' => 'No shift planned.', 'today' => false];
    }

    /**
     * Clock state only: not clocked in, clocked in, or done for today. Clocking stays on the Attendance page.
     *
     * @return array{state: string, text: string}|null
     */
    public function clock(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.attendance.clock.own')) {
            return null;
        }
        $my = (array) ($this->attendance->page($actor, ['tab' => 'my'])['my'] ?? []);
        $state = (string) ($my['state'] ?? 'out');
        $text = match ($state) {
            'in' => (string) ($my['clockedInLabel'] ?? 'Clocked in'),
            'done' => (string) ($my['doneLabel'] ?? 'Done for today'),
            default => 'Not clocked in',
        };

        return ['state' => $state, 'text' => $text];
    }

    /**
     * Leave left per counted leave type (at most 5), from My leave. Never a reason, request or file.
     *
     * @return array{total: int, rows: list<array{name: string, left: string}>}|array{notSetUp: true}|null
     */
    public function leaveLeft(User $actor): ?array
    {
        $balances = $this->balances($actor);
        if ($balances === null) {
            return null;
        }
        $counted = array_values(array_filter($balances, fn (array $b): bool => (bool) $b['counted']));
        if ($counted === []) {
            return ['notSetUp' => true];
        }
        $rows = [];
        foreach (array_slice($counted, 0, self::LIST_LIMIT) as $b) {
            $rows[] = ['name' => (string) $b['name'], 'left' => SpaceDisplay::quantityText((string) $b['left'], 'day').' left'];
        }

        return ['total' => count($counted), 'rows' => $rows];
    }

    /**
     * My claims by status, as counts only: no amount, purpose or number.
     *
     * @return array{text: string}|null
     */
    public function claimCounts(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || ! $actor->can('hr.claims.submit.own')) {
            return null;
        }
        $rows = (array) ($this->claims->page($actor, $branch, ['tab' => 'my'])['my'] ?? []);
        $waiting = count(array_filter($rows, fn (array $r): bool => $r['status'] === 'waiting'));
        $unpaid = count(array_filter($rows, fn (array $r): bool => $r['status'] === 'approved'));
        $clauses = [];
        if ($waiting > 0) {
            $clauses[] = $waiting.' waiting for approval';
        }
        if ($unpaid > 0) {
            $clauses[] = $unpaid.' approved, not paid yet';
        }

        return ['text' => $clauses === [] ? 'No claim is waiting.' : implode(' · ', $clauses)];
    }

    /**
     * My credentials that are expiring or expired, with the date in words and no reference.
     *
     * @return array{total: int, rows: list<array{name: string, words: string, expired: bool}>}|null
     */
    public function credentialsDue(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.credentials.view.own')) {
            return null;
        }
        $cards = (array) (((array) ($this->credentials->page($actor, ['tab' => 'my'])['my'] ?? []))['cards'] ?? []);
        $rows = [];
        foreach ($cards as $c) {
            $code = (string) ($c['status']['code'] ?? '');
            if ($code === 'expiring' || $code === 'expired') {
                $rows[] = ['name' => (string) $c['name'], 'words' => (string) ($c['status']['words'] ?? ''), 'expired' => $code === 'expired'];
            }
        }
        if ($rows === []) {
            return null;
        }

        return ['total' => count($rows), 'rows' => array_slice($rows, 0, self::LIST_LIMIT)];
    }

    /**
     * The latest payslip, no amount: the month and the issue date only. The payslip opens on its own page.
     *
     * @return array{period: string, issuedOn: string, href: string}|null
     */
    public function latestPayslip(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.payslips.view.own')) {
            return null;
        }
        $latest = $this->payslips->mine($actor)[0] ?? null;
        if ($latest === null) {
            return null;
        }

        return ['period' => (string) $latest['period'], 'issuedOn' => (string) $latest['issuedOn'], 'href' => route('hr.payslips.show', ['payslip' => $latest['id']], absolute: false)];
    }

    /**
     * Up to three days off in the next two weeks, or null when no shift is planned in that window (so "off" would mean "no roster").
     *
     * @return list<string>|null
     */
    public function daysOff(User $actor): ?array
    {
        $days = $this->shiftDays($actor);
        if ($days === null) {
            return null;
        }
        if ($days['days'] === []) {
            return [];
        }
        $today = CarbonImmutable::parse($days['today']);
        $out = [];
        for ($i = 0; $i < 14 && count($out) < 3; $i++) {
            $d = $today->addDays($i);
            if (! isset($days['days'][$d->toDateString()])) {
                $out[] = $d->format('D j M');
            }
        }

        return $out;
    }

    /**
     * Leave taken and booked this year per counted type.
     *
     * @return list<array{name: string, taken: string, booked: string}>|null
     */
    public function leaveTaken(User $actor): ?array
    {
        $balances = $this->balances($actor);
        if ($balances === null) {
            return null;
        }
        $rows = [];
        foreach ($balances as $b) {
            if ($b['counted']) {
                $rows[] = ['name' => (string) $b['name'], 'taken' => SpaceDisplay::quantityText((string) $b['taken'], 'day'), 'booked' => SpaceDisplay::quantityText((string) $b['booked'], 'day')];
            }
        }

        return $rows;
    }

    /**
     * My leave requests that are waiting or still ahead: dates, days, type and status only. Never the reason or files.
     *
     * @return list<array{dates: string, days: string, type: string, status: string}>|null
     */
    public function leaveRequests(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.leave.request.own')) {
            return null;
        }
        $page = $this->leave->page($actor, ['tab' => 'my']);
        $today = (string) ($page['today'] ?? now()->toDateString());
        $rows = [];
        foreach ((array) ($page['my']['requests'] ?? []) as $r) {
            $open = in_array($r['status'], ['pending', 'approved'], true) && (string) $r['endDate'] >= $today;
            if ($open) {
                $rows[] = ['dates' => (string) $r['dates'], 'days' => SpaceDisplay::quantityText((string) $r['days'], 'day'), 'type' => (string) $r['type'], 'status' => (string) $r['statusLabel'], 'on' => (string) $r['startDate']];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['on'], $b['on']));

        return array_map(fn (array $r): array => ['dates' => $r['dates'], 'days' => $r['days'], 'type' => $r['type'], 'status' => $r['status']], array_slice($rows, 0, 10));
    }

    /** @return list<array<string, mixed>>|null */
    private function balances(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.leave.request.own')) {
            return null;
        }

        return array_values((array) ($this->leave->page($actor, ['tab' => 'my'])['my']['balances'] ?? []));
    }

    /**
     * My approved shifts keyed by branch-local date, for this week and next week.
     *
     * @return array{today: string, days: array<string, array{label: string, shifts: list<array<string, mixed>>}>}|null
     */
    private function shiftDays(User $actor): ?array
    {
        $branch = $this->branches->activeBranch($actor);
        if ($branch === null || ! $actor->can('hr.roster.view.own')) {
            return null;
        }
        $today = now($branch->timezone)->toDateString();
        $days = [];
        foreach ([$today, CarbonImmutable::parse($today)->addWeek()->toDateString()] as $week) {
            foreach ((array) ($this->roster->page($actor, ['tab' => 'my', 'week' => $week])['my']['days'] ?? []) as $d) {
                $days[(string) $d['date']] = ['label' => (string) $d['label'], 'shifts' => $d['shifts']];
            }
        }
        ksort($days);

        return ['today' => $today, 'days' => $days];
    }
}
