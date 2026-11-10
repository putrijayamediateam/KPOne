<?php

namespace App\Domain\HR\Dashboard;

use App\Domain\Audit\AuditRecorder;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Models\OvertimeRequest;
use App\Domain\HR\Attendance\Services\AttendanceDays;
use App\Domain\HR\Attendance\Services\AttendanceSettingsService;
use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Services\LeaveCalendar;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\MinimumStaffingService;
use App\Domain\HR\Roster\Services\RosterPageService;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * HR-9 HR dashboard (design note docs/teams/hr/HR-9-DESIGN.md). One read-only page of aggregates built from what HR-1
 * to HR-3 already store: who is rostered, clocked in or on leave today per branch, and a month summary. It writes
 * nothing except one structural audit row, sends nothing and stores nothing. Each section needs its own existing
 * permission (roster, attendance review, leave management); the Director and the HR Manager hold all three. No salary,
 * no leave type or reason and no score of a person ever appears here.
 */
final class HrDashboardService
{
    /** At most this many names on one "today" list; the full count is always shown. */
    public const MAX_NAMES = 200;

    public const MONTHS_BACK = 11;

    public function __construct(
        private AttendanceDays $days,
        private AttendanceSettingsService $settings,
        private MinimumStaffingService $minimums,
        private RosterPageService $roster,
        private LeaveCalendar $leave,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $actor = User::query()->whereKey($actor->id)->with(['roles.permissions', 'permissions'])->first();
        if (! $actor || ! $actor->is_active) {
            throw new AuthorizationException('You may not open the HR dashboard.');
        }
        $can = [
            'roster' => $actor->can(RosterPermissions::MANAGE),
            'attendance' => $actor->can(ClockPermissions::REVIEW),
            'leave' => $actor->can(LeavePermissions::MANAGE),
        ];
        if (! in_array(true, $can, true)) {
            throw new AuthorizationException('You may not open the HR dashboard.');
        }
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get();
        $now = CarbonImmutable::now('UTC');
        $months = $this->monthOptions($now);
        $month = $this->month($query['month'] ?? null, $months);
        $this->audit->record('hr.dashboard.viewed', null, ['branch_ids' => $branches->pluck('id')->all(), 'month' => $month, 'sections' => array_keys(array_filter($can))], $actor);

        $names = $this->names($actor->organisation_id);

        return [
            'can' => $can,
            'now' => $now->setTimezone('Asia/Kuala_Lumpur')->format('j M Y, g:i A'),
            'month' => $month,
            'months' => $months,
            'attendanceSettingsSaved' => $this->settings->forDate($actor->organisation_id, CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString()) !== null,
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->id,
                'name' => $branch->name,
                'today' => $this->today($actor, $branch, $now, $can, $names),
                'month' => $this->monthFigures($actor, $branch, $month, $now, $can),
            ])->values()->all(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function monthOptions(CarbonImmutable $now): array
    {
        $first = $now->setTimezone('Asia/Kuala_Lumpur')->startOfMonth();
        $out = [];
        for ($i = 0; $i <= self::MONTHS_BACK; $i++) {
            $m = $first->subMonthsNoOverflow($i);
            $out[] = ['value' => $m->format('Y-m'), 'label' => $m->format('F Y')];
        }

        return $out;
    }

    /** @param list<array{value: string, label: string}> $months */
    private function month(mixed $value, array $months): string
    {
        return is_string($value) && in_array($value, array_column($months, 'value'), true) ? $value : $months[0]['value'];
    }

    /** @return array<int, string> */
    private function names(int $organisationId): array
    {
        // Active staff only: a person who left with a surviving shift, session or leave is not listed by name.
        return User::query()->where('organisation_id', $organisationId)->where('is_active', true)->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all();
    }

    /**
     * @param  array<string, bool>  $can
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function today(User $actor, Branch $branch, CarbonImmutable $now, array $can, array $names): array
    {
        $localToday = $now->setTimezone($branch->timezone)->startOfDay();
        $out = ['date' => $localToday->format('D j M'), 'rostered' => null, 'clockedIn' => null, 'onLeave' => null, 'staffing' => null];
        if ($can['roster']) {
            $approved = RosterVersion::query()->select('id')->where('organisation_id', $actor->organisation_id)->where('status', 'approved');
            $shifts = RosterShift::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->whereIn('roster_version_id', $approved)->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->orderBy('starts_at')->get();
            // Someone whose approved leave covers now is on leave, not rostered (the other half of a half day still is).
            $away = $this->onLeaveNow($actor->organisation_id, $branch, $now, array_values($shifts->pluck('staff_user_id')->map(fn ($id): int => (int) $id)->all()));
            $onShift = $shifts->filter(fn (RosterShift $s): bool => isset($names[$s->staff_user_id]) && ! isset($away[$s->staff_user_id]))->values();
            $roles = $this->roles(array_values($onShift->pluck('staff_user_id')->map(fn ($id): int => (int) $id)->all()));
            $doctors = $onShift->filter(fn (RosterShift $s): bool => in_array('resident_doctor', $roles[$s->staff_user_id] ?? [], true))->pluck('staff_user_id')->unique()->count();
            $out['rostered'] = [
                'count' => $onShift->pluck('staff_user_id')->unique()->count(),
                'doctors' => $doctors,
                'people' => $onShift->unique('staff_user_id')->take(self::MAX_NAMES)->map(fn (RosterShift $s): array => [
                    'name' => $names[$s->staff_user_id],
                    'role' => $this->roleLabel($roles[$s->staff_user_id] ?? []),
                    'until' => $s->ends_at->setTimezone($s->timezone)->format('g:i A'),
                ])->values()->all(),
                // Only when someone is on now: a closed or quiet branch is not "without a doctor".
                'noDoctor' => $onShift->isNotEmpty() && $doctors === 0,
            ];
            $out['staffing'] = $this->staffing($actor, $branch, $localToday);
        }
        if ($can['attendance']) {
            $open = ClockSession::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->whereNull('clock_out_at')
                ->where('clock_in_at', '>=', $now->subHours(16))->orderBy('clock_in_at')->get();
            $active = $open->filter(fn (ClockSession $s): bool => isset($names[$s->user_id]));
            $out['clockedIn'] = [
                'count' => $active->pluck('user_id')->unique()->count(),
                'people' => $active->unique('user_id')->take(self::MAX_NAMES)->map(fn (ClockSession $s): array => [
                    'name' => $names[$s->user_id],
                    'since' => $s->clock_in_at->setTimezone($s->timezone)->format('g:i A'),
                ])->values()->all(),
            ];
        }
        if ($can['leave']) {
            // Leave slots are branch-local wall-clock times (LeaveCalendar::utcSlots), so compare with the branch's local now.
            $localNow = $now->setTimezone($branch->timezone)->format('Y-m-d H:i:s');
            $requests = LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('status', 'approved')
                ->where('slot_start', '<=', $localNow)->where('slot_end', '>', $localNow)->get()->filter(fn (LeaveRequest $r): bool => isset($names[$r->user_id]));
            $out['onLeave'] = [
                'count' => $requests->pluck('user_id')->unique()->count(),
                // Name and "On leave" or "half day" only: never the leave type or the reason.
                'people' => $requests->unique('user_id')->take(self::MAX_NAMES)->map(fn (LeaveRequest $r): array => [
                    'name' => $names[$r->user_id],
                    'half' => $r->halves === 1 && $r->start_date->equalTo($r->end_date),
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * Of these people, who has approved leave covering now (leave slots are branch-local wall-clock times).
     *
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function onLeaveNow(int $organisationId, Branch $branch, CarbonImmutable $now, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $localNow = $now->setTimezone($branch->timezone)->format('Y-m-d H:i:s');

        $away = [];
        foreach (LeaveRequest::query()->where('organisation_id', $organisationId)->whereIn('user_id', $userIds)->where('status', 'approved')
            ->where('slot_start', '<=', $localNow)->where('slot_end', '>', $localNow)->pluck('user_id') as $id) {
            $away[(int) $id] = true;
        }

        return $away;
    }

    /**
     * Enough or Short for today, by the Planner's own rule (RosterPageService::shortfalls; HR's decision: matching the
     * Planner matters more than a session refinement): the people on each session's shifts today, by role, against that
     * session's minimum. A branch with no shifts that does not work today shows nothing (state "closed"), because
     * minimums apply to every day (a known HR-1 limit).
     *
     * @return array<string, mixed>
     */
    private function staffing(User $actor, Branch $branch, CarbonImmutable $localToday): array
    {
        $shifts = $this->shiftsOn($actor->organisation_id, $branch, $localToday, $localToday);
        if ($shifts->isEmpty()) {
            $working = $this->leave->workingDays($branch);

            return ['state' => $working !== null && ! in_array($localToday->dayOfWeekIso, $working, true) ? 'closed' : 'no_roster', 'messages' => []];
        }
        if (collect($this->minimums->minimums($branch))->flatten()->sum() <= 0) {
            return ['state' => 'not_set', 'messages' => []];
        }
        $gaps = $this->gaps($branch, $localToday, $shifts);

        return ['state' => $gaps === [] ? 'enough' : 'short', 'messages' => array_map(fn (array $g): string => $g['message'], $gaps)];
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @return list<array{date: string, day: string, message: string}>
     */
    private function gaps(Branch $branch, CarbonImmutable $from, Collection $shifts): array
    {
        $people = User::query()->with('roles')->whereIn('id', $shifts->pluck('staff_user_id')->unique())->get()->keyBy('id');

        // The Planner's check looks at 7 days from its start date; only the day asked for counts here.
        return array_values(array_filter($this->roster->shortfalls($branch, $from, $shifts, $people), fn (array $g): bool => $g['date'] === $from->toDateString()));
    }

    /** @return Collection<int, RosterShift> approved shifts that start on a branch-local date in [$from, $to] */
    private function shiftsOn(int $organisationId, Branch $branch, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->days->shifts($organisationId, [], $from, $to, $branch->id);
    }

    /**
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    private function monthFigures(User $actor, Branch $branch, string $month, CarbonImmutable $now, array $can): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $branch->timezone);
        $end = $start->endOfMonth()->startOfDay();
        $today = $now->setTimezone($branch->timezone)->startOfDay();
        $out = ['attendance' => null, 'overtime' => null, 'leave' => null, 'shortfallDays' => null];
        if ($can['attendance']) {
            $out['attendance'] = $this->attendance($actor, $branch, $start, $end);
            $out['overtime'] = ['approvedMinutes' => (int) OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
                ->where('status', 'approved')->whereDate('local_date', '>=', $start->toDateString())->whereDate('local_date', '<=', $end->toDateString())->sum('minutes_approved')];
        }
        if ($can['leave']) {
            $out['leave'] = $this->leaveDays($actor, $branch, $start, $end);
        }
        if ($can['roster']) {
            $out['shortfallDays'] = $this->shortfallDays($actor, $branch, $start, $end->min($today));
        }

        return $out;
    }

    /** @return array<string, int> */
    private function attendance(User $actor, Branch $branch, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $sessions = ClockSession::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)
            ->whereDate('local_date', '>=', $start->toDateString())->whereDate('local_date', '<=', $end->toDateString())->orderBy('clock_in_at')->get();
        $rows = $this->days->rows($actor->organisation_id, $sessions, $this->shiftsOn($actor->organisation_id, $branch, $start, $end));
        $count = ['late' => 0, 'left_early' => 0, 'no_clock_in' => 0, 'no_clock_out' => 0];
        foreach ($rows as $row) {
            foreach ($row['flags'] as $flag) {
                if (isset($count[$flag['code']])) {
                    $count[$flag['code']]++;
                }
            }
        }

        return ['daysClocked' => $sessions->count(), 'late' => $count['late'], 'leftEarly' => $count['left_early'], 'noClockIn' => $count['no_clock_in'], 'noClockOut' => $count['no_clock_out']];
    }

    /**
     * Days of approved leave that fall in the month, counted as the leave screens count them (half days included).
     *
     * @return array{days: float|int, people: int}|null
     */
    private function leaveDays(User $actor, Branch $branch, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $requests = LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('branch_id', $branch->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->get();
        $halves = 0;
        try {
            foreach ($requests as $r) {
                $from = CarbonImmutable::parse($r->start_date->toDateString());
                $to = CarbonImmutable::parse($r->end_date->toDateString());
                $clampedFrom = $from->lessThan($start->startOfDay()) ? $start->startOfDay() : $from;
                $clampedTo = $to->greaterThan($end) ? $end : $to;
                $startPart = $clampedFrom->equalTo($from) ? $r->start_part : 'full';
                $endPart = $clampedTo->equalTo($to) ? $r->end_part : 'full';
                $halves += $this->leave->measure($branch, $clampedFrom, $clampedTo, $startPart, $endPart)['halves'];
            }
        } catch (ValidationException) {
            return null; // Working days are not set up for this branch.
        }

        return ['days' => $halves / 2, 'people' => $requests->pluck('user_id')->unique()->count()];
    }

    /** Days up to today with someone rostered and a minimum not met, by the Planner's rule. */
    private function shortfallDays(User $actor, Branch $branch, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        if ($end->lessThan($start) || collect($this->minimums->minimums($branch))->flatten()->sum() <= 0) {
            return null;
        }
        $shifts = $this->shiftsOn($actor->organisation_id, $branch, $start, $end);
        $days = 0;
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            $dayShifts = $shifts->filter(fn (RosterShift $s): bool => $s->starts_at->setTimezone($branch->timezone)->toDateString() === $d->toDateString());
            if ($dayShifts->isNotEmpty() && $this->gaps($branch, $d, $dayShifts) !== []) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private function roles(array $userIds): array
    {
        return User::query()->with('roles')->whereIn('id', array_unique($userIds))->get()->mapWithKeys(fn (User $u): array => [$u->id => array_values($u->roles->pluck('name')->map(fn ($n): string => (string) $n)->all())])->all();
    }

    /** @param list<string> $roles */
    private function roleLabel(array $roles): string
    {
        foreach (array_keys(RosterRules::ROLE_LABELS) as $role) {
            if (in_array($role, $roles, true)) {
                return RosterRules::ROLE_LABELS[$role];
            }
        }

        return 'Staff';
    }
}
