<?php

namespace App\Domain\HR\Compliance\Services;

use App\Domain\HR\Leave\Services\LeaveCalendar;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HR-5b roster compliance warnings (HR-PLAN 12.3 rule 7). Read only, and never a refusal: the planner shows them so the
 * person planning can see a gap in doctor cover, too many days in a row, short rest or too many planned hours, and a
 * month's count of weekend, public-holiday and night shifts per resident doctor. They read approved weeks and the week
 * being planned (the draft in place of its own approved version); approved leave counts as a day off.
 */
final class ComplianceWarnings
{
    /** A gap shorter than this is not shown (HR-PLAN 12.3 rule 7). */
    public const MIN_GAP_MINUTES = 15;

    /** How far either side of the week shifts are read for days in a row and rest. */
    private const WINDOW_DAYS = 14;

    public function __construct(private RosterRules $rules, private LeaveCalendar $leave) {}

    /**
     * @param  Collection<int, RosterShift>  $shifts  the shown version's shifts
     * @param  Collection<int, User>  $people  roles eager-loaded
     * @return array{setUp: bool, warnings: list<array{kind: string, message: string}>, rotation: array{month: string, rows: list<array{name: string, weekends: int, holidays: int, nights: int}>}}
     */
    public function forWeek(Branch $branch, CarbonImmutable $weekStart, RosterVersion $shown, Collection $shifts, Collection $people): array
    {
        $coverage = ComplianceRulesService::coverage($branch->id);
        $work = ComplianceRulesService::workHours($branch->organisation_id);
        $leave = $this->approvedLeave($branch, $weekStart, array_values($shifts->pluck('staff_user_id')->map(fn ($id): int => (int) $id)->unique()->all()));
        $warnings = [];
        if ($coverage !== null && $coverage['minDoctors'] > 0) {
            $warnings = [...$warnings, ...$this->coverageGaps($branch, $weekStart, $coverage, $shifts, $people, $leave)];
        }
        if ($work !== null) {
            $warnings = [...$warnings, ...$this->workHourWarnings($branch, $weekStart, $shown, $shifts, $people, $work)];
        }

        return ['setUp' => $coverage !== null || $work !== null, 'warnings' => $warnings, 'rotation' => $this->rotation($branch, $weekStart, $shown, $shifts, $people)];
    }

    /**
     * Sweeps each open day: every span where fewer resident doctors are on duty than the minimum, 15 minutes or longer.
     * A doctor's shift that overlaps their approved leave is not counted.
     *
     * @param  array{version: int, hours: array<int, array{open: string, close: string}>, minDoctors: int}  $coverage
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, User>  $people
     * @param  Collection<int, array{user: int, from: CarbonImmutable, to: CarbonImmutable}>  $leave
     * @return list<array{kind: string, message: string}>
     */
    private function coverageGaps(Branch $branch, CarbonImmutable $weekStart, array $coverage, Collection $shifts, Collection $people, Collection $leave): array
    {
        // Each doctor's on-duty spans: the shift less any approved leave inside it, so half-day leave removes only its half
        // (Suresh, M1).
        $doctors = collect();
        foreach ($shifts->filter(fn (RosterShift $s): bool => $this->isDoctor($people->get($s->staff_user_id))) as $s) {
            $spans = [[$s->starts_at->getTimestamp(), $s->ends_at->getTimestamp()]];
            foreach ($leave->where('user', $s->staff_user_id) as $l) {
                [$lf, $lt] = [$l['from']->getTimestamp(), $l['to']->getTimestamp()];
                $next = [];
                foreach ($spans as [$a, $b]) {
                    if ($lt <= $a || $lf >= $b) {
                        $next[] = [$a, $b];

                        continue;
                    }
                    if ($lf > $a) {
                        $next[] = [$a, $lf];
                    }
                    if ($lt < $b) {
                        $next[] = [$lt, $b];
                    }
                }
                $spans = $next;
            }
            foreach ($spans as [$a, $b]) {
                $doctors->push(['user' => (int) $s->staff_user_id, 'from' => $a, 'to' => $b]);
            }
        }
        $min = $coverage['minDoctors'];
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $day = CarbonImmutable::parse($weekStart->toDateString(), $branch->timezone)->addDays($i);
            $span = $coverage['hours'][$day->dayOfWeekIso] ?? null;
            if ($span === null) {
                continue;
            }
            $open = CarbonImmutable::parse($day->toDateString().' '.$span['open'], $branch->timezone)->utc();
            $close = CarbonImmutable::parse($day->toDateString().' '.$span['close'], $branch->timezone)->utc();
            $points = [$open->getTimestamp(), $close->getTimestamp()];
            foreach ($doctors as $d) {
                foreach ([$d['from'], $d['to']] as $t) {
                    if ($t > $points[0] && $t < $points[1]) {
                        $points[] = $t;
                    }
                }
            }
            $points = array_values(array_unique($points));
            sort($points);
            $gaps = [];
            for ($p = 0; $p < count($points) - 1; $p++) {
                [$a, $b] = [$points[$p], $points[$p + 1]];
                $count = $doctors->filter(fn (array $d): bool => $d['from'] <= $a && $d['to'] >= $b)->pluck('user')->unique()->count();
                if ($count < $min) {
                    $last = array_key_last($gaps);
                    if ($last !== null && $gaps[$last][1] === $a) {
                        $gaps[$last][1] = $b;
                    } else {
                        $gaps[] = [$a, $b];
                    }
                }
            }
            foreach ($gaps as [$a, $b]) {
                if ($b - $a < self::MIN_GAP_MINUTES * 60) {
                    continue;
                }
                $from = CarbonImmutable::createFromTimestamp($a)->setTimezone($branch->timezone);
                $to = CarbonImmutable::createFromTimestamp($b)->setTimezone($branch->timezone);
                $when = $from->format('D').' '.$this->rules->timeLabel($from).' – '.$this->rules->timeLabel($to);
                $out[] = ['kind' => 'coverage', 'message' => $min === 1
                    ? "No resident doctor {$when} ({$branch->name})"
                    : "Fewer than {$min} resident doctors {$when} ({$branch->name})"];
            }
        }

        return $out;
    }

    /**
     * Days in a row, rest between two shifts and planned hours, per person in the week, across every branch.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, User>  $people
     * @param  array{version: int, maxDaysInRow: int|null, minRestMinutes: int|null, maxWeekMinutes: int|null}  $work
     * @return list<array{kind: string, message: string}>
     */
    private function workHourWarnings(Branch $branch, CarbonImmutable $weekStart, RosterVersion $shown, Collection $shifts, Collection $people, array $work): array
    {
        $ids = array_values($shifts->pluck('staff_user_id')->map(fn ($id): int => (int) $id)->unique()->sort()->all());
        if ($ids === []) {
            return [];
        }
        $weekFrom = $weekStart->toDateString();
        $weekTo = $weekStart->addDays(6)->toDateString();
        $all = $this->plannedShifts($branch, $weekStart, $shown, $shifts, $ids, self::WINDOW_DAYS);
        $leaveDays = $this->leaveDates($ids, $weekStart->subDays(self::WINDOW_DAYS), $weekStart->addDays(7 + self::WINDOW_DAYS));
        $out = [];
        foreach ($ids as $id) {
            $name = $people->get($id)->name ?? 'A staff member';
            /** @var Collection<int, array{start: CarbonImmutable, end: CarbonImmutable, minutes: int, date: string}> $mine */
            $mine = $all->where('user', $id)->sortBy(fn (array $s): int => $s['start']->getTimestamp())->values();

            if ($work['maxDaysInRow'] !== null) {
                $dates = $mine->pluck('date')->unique()->diff($leaveDays[$id] ?? [])->sort()->values()->all();
                $run = [];
                $runs = [];
                foreach ($dates as $date) {
                    if ($run !== [] && CarbonImmutable::parse(end($run))->addDay()->toDateString() !== $date) {
                        $runs[] = $run;
                        $run = [];
                    }
                    $run[] = $date;
                }
                if ($run !== []) {
                    $runs[] = $run;
                }
                foreach ($runs as $r) {
                    if (count($r) > $work['maxDaysInRow'] && $r[0] <= $weekTo && end($r) >= $weekFrom) {
                        $first = CarbonImmutable::parse($r[0]);
                        $last = CarbonImmutable::parse(end($r));
                        $out[] = ['kind' => 'days_in_row', 'message' => "{$name}: ".count($r)." days in a row, {$this->rules->shortDayLabel($first)} to {$this->rules->shortDayLabel($last)} (limit {$work['maxDaysInRow']})"];
                    }
                }
            }

            if ($work['minRestMinutes'] !== null) {
                // Daily rest (Suresh, S1): from the end of the last shift that starts on one date to the start of the
                // first shift on the next date worked. Gaps between sessions on the same date are breaks, not rest.
                $byDate = $mine->groupBy('date')->sortKeys();
                $dates = $byDate->keys()->all();
                for ($i = 1; $i < count($dates); $i++) {
                    $prevEnd = $byDate[$dates[$i - 1]]->max(fn (array $s): int => $s['end']->getTimestamp());
                    $nextStart = $byDate[$dates[$i]]->min(fn (array $s): int => $s['start']->getTimestamp());
                    $rest = (int) (($nextStart - $prevEnd) / 60);
                    $date = (string) $dates[$i];
                    if ($rest >= 0 && $rest < $work['minRestMinutes'] && $date >= $weekFrom && $date <= $weekTo) {
                        $out[] = ['kind' => 'rest', 'message' => "{$name}: ".$this->hours($rest).' rest before '.$this->rules->shortDayLabel(CarbonImmutable::parse($date))
                            .' ('.$this->hours($work['minRestMinutes']).' set)'];
                    }
                }
            }

            if ($work['maxWeekMinutes'] !== null) {
                $minutes = $mine->filter(fn (array $s): bool => $s['date'] >= $weekFrom && $s['date'] <= $weekTo)->sum('minutes');
                if ($minutes > $work['maxWeekMinutes']) {
                    $out[] = ['kind' => 'hours', 'message' => "{$name}: planned ".$this->hours((int) $minutes).' this week (limit '.$this->hours($work['maxWeekMinutes']).')'];
                }
            }
        }

        return $out;
    }

    /**
     * The month's weekend, public-holiday and night shifts per resident doctor at this branch, approved weeks with the
     * shown week in place of its own approved version. Counts only; no limit and no warning.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, User>  $people
     * @return array{month: string, rows: list<array{name: string, weekends: int, holidays: int, nights: int}>}
     */
    private function rotation(Branch $branch, CarbonImmutable $weekStart, RosterVersion $shown, Collection $shifts, Collection $people): array
    {
        // The month that holds most of the week, its Thursday (Suresh, M4).
        $monthStart = CarbonImmutable::parse($weekStart->toDateString(), $branch->timezone)->addDays(3)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $approved = RosterShift::query()->with('version')->where('organisation_id', $branch->organisation_id)
            ->whereIn('roster_version_id', RosterVersion::query()->select('hr_roster_versions.id')->join('hr_roster_weeks', 'hr_roster_weeks.id', '=', 'hr_roster_versions.roster_week_id')
                ->where('hr_roster_weeks.branch_id', $branch->id)->where('hr_roster_versions.status', 'approved')->where('hr_roster_versions.roster_week_id', '<>', $shown->roster_week_id))
            ->where('starts_at', '>=', $monthStart->subDay()->utc())->where('starts_at', '<', $monthEnd->addDay()->utc())->get();
        $month = $approved->concat($shifts)->filter(function (RosterShift $s) use ($branch, $monthStart): bool {
            return $s->starts_at->setTimezone($branch->timezone)->format('Y-m') === $monthStart->format('Y-m');
        });
        $holidays = $this->leave->holidays($branch, $monthStart, $monthEnd);
        $userIds = $month->pluck('staff_user_id')->unique()->diff($people->keys());
        $all = $userIds->isEmpty() ? $people : $people->union(User::query()->with('roles')->whereIn('id', $userIds)->get()->keyBy('id'));
        $rows = [];
        foreach ($month->groupBy('staff_user_id') as $userId => $items) {
            $user = $all->get((int) $userId);
            if (! $this->isDoctor($user)) {
                continue;
            }
            $local = $items->map(fn (RosterShift $s): array => ['day' => $s->starts_at->setTimezone($branch->timezone), 'night' => $s->session_type === 'night']);
            $rows[] = ['name' => $user->name ?? 'Staff member',
                'weekends' => $local->filter(fn (array $x): bool => $x['day']->isoWeekday() >= 6)->count(),
                'holidays' => $local->filter(fn (array $x): bool => isset($holidays[$x['day']->toDateString()]))->count(),
                'nights' => $local->where('night', true)->count()];
        }
        usort($rows, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return ['month' => $monthStart->format('F Y'), 'rows' => $rows];
    }

    /**
     * The people's shifts in and around the week at every branch: approved versions, with the shown version in place of
     * its own week's approved version.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @param  list<int>  $ids
     * @return Collection<int, array{user: int, start: CarbonImmutable, end: CarbonImmutable, minutes: int, date: string}>
     */
    private function plannedShifts(Branch $branch, CarbonImmutable $weekStart, RosterVersion $shown, Collection $shifts, array $ids, int $windowDays): Collection
    {
        $from = CarbonImmutable::parse($weekStart->toDateString(), $branch->timezone)->subDays($windowDays)->utc();
        $to = CarbonImmutable::parse($weekStart->toDateString(), $branch->timezone)->addDays(7 + $windowDays)->utc();
        $other = RosterShift::query()->with('version.week.branch')->where('organisation_id', $branch->organisation_id)->whereIn('staff_user_id', $ids)
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')->where('status', 'approved')->where('roster_week_id', '<>', $shown->roster_week_id))
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->get();
        $map = fn (RosterShift $s, string $zone): array => ['user' => (int) $s->staff_user_id, 'start' => $s->starts_at, 'end' => $s->ends_at,
            'minutes' => max(0, (int) (($s->ends_at->getTimestamp() - $s->starts_at->getTimestamp()) / 60) - (int) $s->break_minutes),
            'date' => $s->starts_at->setTimezone($zone)->toDateString()];

        return $other->map(fn (RosterShift $s): array => $map($s, $s->version->week->branch->timezone))
            ->concat($shifts->map(fn (RosterShift $s): array => $map($s, $branch->timezone)))->values();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, array{user: int, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private function approvedLeave(Branch $branch, CarbonImmutable $weekStart, array $ids): Collection
    {
        // A day either side, so an overnight shift at either end of the week sees its leave (Suresh, M2).
        $from = CarbonImmutable::parse($weekStart->toDateString(), $branch->timezone)->subDay()->utc();
        $requests = $this->leave->overlapping($ids, $from, $from->addDays(9), ['approved']);
        $zones = Branch::query()->whereIn('id', $requests->pluck('branch_id')->unique())->pluck('timezone', 'id');

        return $requests->map(function ($r) use ($zones): array {
            [$start, $end] = $this->leave->utcSlots($r, (string) ($zones[$r->branch_id] ?? 'UTC'));

            return ['user' => (int) $r->user_id, 'from' => $start, 'to' => $end];
        })->values();
    }

    /**
     * Dates of approved leave per person (the leave's own dates), which count as days off.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function leaveDates(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        DB::table('hr_leave_requests')->whereIn('user_id', $ids)->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())
            ->get(['user_id', 'start_date', 'end_date'])->each(function (object $r) use (&$out): void {
                for ($d = CarbonImmutable::parse((string) $r->start_date); $d->lessThanOrEqualTo(CarbonImmutable::parse((string) $r->end_date)); $d = $d->addDay()) {
                    $out[(int) $r->user_id][] = $d->toDateString();
                }
            });

        return $out;
    }

    private function isDoctor(?User $user): bool
    {
        return $user !== null && $user->roles->contains('name', 'resident_doctor');
    }

    /** "9 h", "9.5 h" */
    private function hours(int $minutes): string
    {
        $h = round($minutes / 60, 1);

        return (fmod($h, 1.0) === 0.0 ? (string) (int) $h : (string) $h).' h';
    }
}
