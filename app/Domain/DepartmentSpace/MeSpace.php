<?php

namespace App\Domain\DepartmentSpace;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\Services\AttendancePageService;
use App\Domain\HR\Claims\Services\ClaimsPageService;
use App\Domain\HR\Credentials\Services\CredentialsPageService;
use App\Domain\HR\Leave\Services\LeavePageService;
use App\Domain\HR\Payroll\Services\PayslipService;
use App\Domain\HR\Roster\Services\RosterPageService;
use App\Domain\Organisation\Models\Branch;
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
        $my = $this->attendanceMine($actor);
        $ring = $my === null ? null : $this->ring($actor, $my);
        $week = $my === null ? null : $this->week($my);

        return [
            'branchName' => $branch?->name,
            'asOf' => $branch ? now()->setTimezone($branch->timezone)->format('g:i A') : null,
            'greeting' => $branch === null ? null : $this->greeting($actor, $branch, $ring['state'] ?? null),
            'time' => $branch === null ? null : ['serverNow' => now()->utc()->toIso8601String(), 'timezone' => $branch->timezone],
            'ring' => $ring,
            'week' => $week,
            'tiles' => $this->tiles($actor, $week),
            'credentials' => $this->credentialsDue($actor),
            'payslip' => $payslip,
            'primaryAction' => $primary,
            'links' => $links,
        ];
    }

    /**
     * My attendance's own data for the signed-in person (the same service My attendance uses, under its own permission).
     *
     * @return array<string, mixed>|null
     */
    public function attendanceMine(User $actor): ?array
    {
        if ($this->branches->activeBranch($actor) === null || ! $actor->can('hr.attendance.clock.own')) {
            return null;
        }

        return (array) ($this->attendance->page($actor, ['tab' => 'my'])['my'] ?? []);
    }

    /**
     * "Good morning, Aina", "Good afternoon" from 12:00 and "Good evening" from 18:00 in the branch time zone, or "Have a
     * good shift, Aina" once clocked in. The signed-in person's own first name only.
     *
     * @return array{text: string, date: string}
     */
    public function greeting(User $actor, Branch $branch, ?string $clockState): array
    {
        $now = now()->setTimezone($branch->timezone);
        $first = trim((string) strtok(trim($actor->name), ' '));
        $period = $now->hour < 12 ? 'Good morning' : ($now->hour < 18 ? 'Good afternoon' : 'Good evening');
        $text = $clockState === 'in' ? 'Have a good shift' : $period;

        return ['text' => $first === '' ? $text : $text.', '.$first, 'date' => $now->format('D j M').' · '.$branch->name];
    }

    /**
     * The ring: clock state and the times the browser needs to count Worked up from. Every time the page shows as a
     * fact comes from the server; the browser only ticks a display from the server's own "now".
     *
     * @param  array<string, mixed>  $my
     * @return array<string, mixed>
     */
    public function ring(User $actor, array $my): array
    {
        $zone = (string) ($my['timezone'] ?? 'UTC');
        $today = now($zone)->toDateString();
        $row = collect((array) ($my['week'] ?? []))->first(fn (array $r): bool => (string) $r['date'] === $today && ($r['session']['status'] ?? null) === 'open')
            ?? collect((array) ($my['week'] ?? []))->first(fn (array $r): bool => (string) $r['date'] === $today);
        $state = (string) ($my['state'] ?? 'out');
        $shiftLabel = null;
        if (is_array($row['shift'] ?? null)) {
            $parts = explode(', ', (string) $row['shift']['label'], 2);
            $shiftLabel = $parts[1] ?? $parts[0];
        }
        $flags = collect((array) ($row['flags'] ?? []));
        $late = $flags->firstWhere('code', 'late');
        $inOpen = is_array($row['session'] ?? null) && ($row['session']['status'] ?? null) === 'open';

        return [
            'state' => $state,
            'canPress' => (bool) ($my['canPress'] ?? false),
            'refusal' => $my['refusal'] ?? null,
            'needsAttendancePage' => ($my['location'] ?? null) !== null,
            'serverNow' => now()->utc()->toIso8601String(),
            'timezone' => $zone,
            'clockedInAt' => $state === 'in' && $inOpen ? CarbonImmutable::parse((string) $row['session']['inValue'], $zone)->utc()->toIso8601String() : null,
            'clockedInLabel' => $my['clockedInLabel'] ?? null,
            'doneLabel' => $my['doneLabel'] ?? null,
            'shiftLabel' => $shiftLabel,
            'rosteredMinutes' => isset($row['rosteredMinutes']) ? (int) $row['rosteredMinutes'] : null,
            'onTime' => $state === 'in' && $inOpen && $shiftLabel !== null ? $late === null : null,
            'lateMinutes' => $late === null ? null : (int) $late['minutes'],
        ];
    }

    /**
     * This week, Monday to Friday, from My attendance's own rows and the same Late rule as HR Review (the grace minutes
     * included): a tick for on time, "!" for late or missed, a dash for a day with nothing to show.
     *
     * @param  array<string, mixed>  $my
     * @return array{days: list<array{day: string, date: string, result: string, word: string}>, summary: string}
     */
    public function week(array $my): array
    {
        $zone = (string) ($my['timezone'] ?? 'UTC');
        $today = CarbonImmutable::now($zone)->startOfDay();
        $monday = $today->startOfWeek(CarbonImmutable::MONDAY);
        $rows = collect((array) ($my['week'] ?? []))->groupBy(fn (array $r): string => (string) $r['date']);
        $days = [];
        $onTime = 0;
        $late = 0;
        $missed = 0;
        for ($i = 0; $i < 5; $i++) {
            $date = $monday->addDays($i);
            $items = $rows->get($date->toDateString(), collect());
            $codes = $items->flatMap(fn (array $r): array => array_column((array) $r['flags'], 'code'))->all();
            $session = $items->first(fn (array $r): bool => is_array($r['session'] ?? null));
            $result = 'none';
            $word = $date->greaterThan($today) ? 'Not yet' : 'No shift';
            if (in_array('on_leave', $codes, true)) {
                $result = 'leave';
                $word = 'On leave';
            } elseif ($session !== null && in_array('late', $codes, true)) {
                $result = 'late';
                $word = 'Late';
                $late++;
            } elseif ($session !== null) {
                $result = 'ontime';
                $word = 'On time';
                $onTime++;
            } elseif (in_array('no_clock_in', $codes, true) && $date->lessThan($today)) {
                $result = 'missed';
                $word = 'Missed';
                $missed++;
            }
            $days[] = ['day' => $date->format('D'), 'date' => $date->toDateString(), 'result' => $result, 'word' => $word];
        }
        $parts = [];
        if ($onTime > 0) {
            $parts[] = $onTime.' on time';
        }
        if ($late > 0) {
            $parts[] = $late.' late';
        }
        if ($missed > 0) {
            $parts[] = $missed.' missed';
        }

        return ['days' => $days, 'summary' => $parts === [] ? 'Nothing clocked yet this week' : implode(', ', $parts)];
    }

    /**
     * The four tiles. Each reads its own page's data under that page's permission and is null without it.
     *
     * @param  array{days: list<array<string, mixed>>, summary: string}|null  $week
     * @return array<string, array{title: string, text: string, href: string}|null>
     */
    public function tiles(User $actor, ?array $week): array
    {
        $leave = $this->leaveLeft($actor);
        $leaveText = null;
        if ($leave !== null) {
            $leaveText = isset($leave['notSetUp']) ? 'Not set up yet' : ($leave['rows'][0]['name'].': '.$leave['rows'][0]['left']);
        }
        $shift = $this->nextShift($actor);
        $claims = $this->claimCounts($actor);

        return [
            'leave' => $leaveText === null ? null : ['title' => 'My leave', 'text' => $leaveText, 'href' => route('hr.leave.index', absolute: false)],
            'claims' => $claims === null ? null : ['title' => 'My claims', 'text' => $claims['text'], 'href' => route('hr.claims.index', absolute: false)],
            'roster' => $shift === null ? null : ['title' => 'My roster', 'text' => $shift['text'], 'href' => route('hr.roster.index', absolute: false)],
            'attendance' => $week === null ? null : ['title' => 'My attendance', 'text' => $week['summary'], 'href' => route('hr.attendance.index', absolute: false)],
        ];
    }

    /**
     * The next shift: today's or the next one in this week or next week, from My roster.
     *
     * @return array{text: string, today: bool, empty: bool, href: string}|null
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

                return ['text' => $d['label'].', '.$s['startLabel'].' to '.$s['endLabel'].', '.$s['branch'], 'today' => $date === $days['today'], 'empty' => false, 'href' => route('hr.roster.index', absolute: false)];
            }
        }

        return ['text' => 'No shift planned.', 'today' => false, 'empty' => true, 'href' => route('hr.roster.index', absolute: false)];
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
            $unset = (string) $b['entitled'] === '0' && (string) $b['taken'] === '0' && (string) $b['booked'] === '0';
            $rows[] = ['name' => (string) $b['name'], 'left' => $unset ? 'Not set up yet' : SpaceDisplay::quantityText((string) $b['left'], 'day').' left'];
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
