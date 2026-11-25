<?php

namespace App\Domain\HR\Roster\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Compliance\Services\ComplianceRulesService;
use App\Domain\HR\Compliance\Services\ComplianceWarnings;
use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Services\LeaveCalendar;
use App\Domain\HR\OpenShifts\OpenShiftPermissions;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Models\RosterWeek;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\BranchRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR-1 read model for /hr/roster. A staff member sees only their own approved shifts; only roster managers and
 * approvers see other people's shifts, staff names and the planner. Reads only roster, branch, room, staff and
 * assignment tables; never patient, clinical, billing or stock data.
 */
final class RosterPageService
{
    public function __construct(private BranchAccessService $branches, private RosterRules $rules, private MinimumStaffingService $minimums, private LeaveCalendar $leave,
        private ComplianceWarnings $compliance) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function page(User $actor, array $query): array
    {
        $canManage = $actor->can(RosterPermissions::MANAGE);
        $canApprove = $actor->can(RosterPermissions::APPROVE);
        $canOwn = $actor->can(RosterPermissions::VIEW_OWN);
        abort_unless($canManage || $canApprove || $canOwn, 403);
        $planner = $canManage || $canApprove;
        $tabs = array_values(array_filter([$canOwn ? 'my' : null, $planner ? 'planner' : null, $canManage ? 'minimums' : null]));
        $tab = in_array($query['tab'] ?? null, $tabs, true) ? $query['tab'] : ($tabs[0] ?? 'my');

        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('id')->get();
        $active = $this->branches->activeBranch($actor);
        $branch = $planner ? $branches->firstWhere('id', filter_var($query['branch'] ?? null, FILTER_VALIDATE_INT)) : null;
        $branch ??= $active !== null && $active->organisation_id === $actor->organisation_id ? $active : $branches->first();
        abort_if($branch === null, 404);
        try {
            $weekStart = $this->rules->weekStart($branch, $query['week'] ?? null);
        } catch (ValidationException) {
            $weekStart = $this->rules->weekStart($branch, now($branch->timezone)->toDateString());
        }

        return [
            'tab' => $tab, 'tabs' => $tabs,
            'week' => $this->week($weekStart) + ['today' => now($branch->timezone)->toDateString()],
            'sessions' => collect(RosterRules::SESSIONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            // HR-20: on call is no clinic hours, so the minimum staffing grid lists clinic sessions only.
            'staffingSessions' => collect(RosterRules::clinicSessions())->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'my' => $canOwn ? $this->my($actor, $weekStart) : null,
            // HR-15f: the month calendar on My roster, only when asked for (owner, via B.E.R.N.I.S.S, 18 Nov 2026).
            // Its own key, so it never meets the planner's "view" (Suresh, m1).
            'view' => $canOwn && ($query['myView'] ?? null) === 'month' ? 'month' : 'week',
            'month' => $canOwn && ($query['myView'] ?? null) === 'month' ? $this->myMonth($actor, $branch, $query['month'] ?? null) : null,
            'planner' => $planner ? $this->planner($actor, $branches, $branch, $weekStart, $query['view'] ?? null, $canManage, $canApprove) : null,
            // HR-22e: the planner's month view, only when asked for; its own key, so it never meets "view" or "myView".
            'plannerView' => $planner && ($query['plannerView'] ?? null) === 'month' ? 'month' : 'week',
            'plannerMonth' => $planner && ($query['plannerView'] ?? null) === 'month' ? $this->plannerMonth($actor, $branch, $query['month'] ?? null, $canManage) : null,
            'minimums' => $canManage ? $this->minimumsPage($actor, $branches, $branch) : null,
        ];
    }

    /**
     * HR-22e (HR-PLAN 29.6): one branch's month, Monday-first weeks that touch the month. Each week shows its shown version
     * (the open draft or week waiting for approval, else the approved one) with its status, and its shifts per person and
     * day in short form. Read only: editing a shift stays in the week view. Synthetic data only.
     *
     * @return array<string, mixed>
     */
    private function plannerMonth(User $actor, Branch $branch, mixed $month, bool $canManage): array
    {
        $zone = $branch->timezone;
        $first = is_string($month) && preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month) === 1 ? CarbonImmutable::createFromFormat('!Y-m', $month, $zone) : null;
        $first = $first instanceof CarbonImmutable ? $first->startOfMonth() : CarbonImmutable::now($zone)->startOfMonth();
        if ($first->year < 2000 || $first->year > 2100) {
            $first = CarbonImmutable::now($zone)->startOfMonth();
        }
        $last = $first->endOfMonth()->startOfDay();
        $starts = [];
        for ($w = $first->startOfWeek(CarbonImmutable::MONDAY); $w->lte($last); $w = $w->addWeek()) {
            $starts[] = $w;
        }
        $weeks = RosterWeek::query()->where('branch_id', $branch->id)
            ->whereIn('week_start', array_map(fn (CarbonImmutable $w): string => $w->toDateString(), $starts))->get()
            ->keyBy(fn (RosterWeek $w): string => CarbonImmutable::parse($w->week_start)->toDateString());
        $versions = RosterVersion::query()->whereIn('roster_week_id', $weeks->pluck('id'))->whereIn('status', ['draft', 'submitted', 'approved'])->get()->groupBy('roster_week_id');
        $shown = $weeks->map(function (RosterWeek $w) use ($versions): ?RosterVersion {
            $list = $versions->get($w->id, collect());

            return $list->first(fn (RosterVersion $v): bool => $v->isOpen()) ?? $list->first(fn (RosterVersion $v): bool => $v->status === 'approved');
        })->filter();
        $shifts = RosterShift::query()->whereIn('roster_version_id', $shown->pluck('id'))->orderBy('starts_at')->orderBy('id')->get()->groupBy('roster_version_id');
        $people = User::query()->with('roles')->where('organisation_id', $actor->organisation_id)
            ->whereIn('id', $shifts->flatten()->pluck('staff_user_id')->unique())->get()->keyBy('id');
        $statusLabels = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'approved' => 'Approved'];

        $out = [];
        foreach ($starts as $start) {
            $key = $start->toDateString();
            $version = $shown->get($key);
            $items = $version === null ? collect() : $shifts->get($version->id, collect());
            $rows = $items->groupBy('staff_user_id')->map(function (Collection $list, int|string $userId) use ($people, $start, $zone): array {
                $user = $people->get((int) $userId);
                $days = array_fill(0, 7, []);
                foreach ($list as $s) {
                    $local = $s->starts_at->setTimezone($zone);
                    $index = (int) $start->diffInDays($local->startOfDay(), false);
                    if ($index < 0 || $index > 6) {
                        continue;
                    }
                    $days[$index][] = ['time' => self::compactTime($local).'–'.self::compactTime($s->ends_at->setTimezone($zone)),
                        'station' => $s->station === null ? null : (RosterRules::STATION_SHORT[$s->station] ?? null),
                        'stationLabel' => $s->station === null ? null : (RosterRules::STATIONS[$s->station] ?? null), 'onCall' => RosterRules::isOnCall($s->session_type)];
                }

                return ['staffUserId' => (int) $userId, 'name' => $user->name ?? 'Staff member', 'role' => $this->roleLabel($user), 'days' => $days];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
            $hasLater = $start->addWeek()->lte($last);
            $out[] = [
                'start' => $key, 'label' => 'Week of '.$this->rules->shortDayLabel($start),
                'days' => collect(range(0, 6))->map(fn (int $d): array => ['date' => $start->addDays($d)->toDateString(), 'label' => $start->addDays($d)->format('D j'),
                    'inMonth' => $start->addDays($d)->month === $first->month])->all(),
                'status' => $version?->status, 'statusLabel' => $version === null ? 'No roster yet' : $statusLabels[$version->status],
                // A change in progress over an approved week: say so, so nobody thinks the approved week is gone.
                'approvedToo' => $version !== null && $version->status !== 'approved' && $versions->get($version->roster_week_id, collect())->contains(fn (RosterVersion $v): bool => $v->status === 'approved'),
                'versionId' => $version?->public_id, 'shiftCount' => $items->count(), 'rows' => $rows,
                'canCopy' => $canManage && $version !== null && $items->isNotEmpty() && $hasLater,
            ];
        }

        return ['key' => $first->format('Y-m'), 'label' => $first->format('F Y'), 'previous' => $first->subMonth()->format('Y-m'),
            'next' => $first->addMonth()->format('Y-m'), 'current' => CarbonImmutable::now($zone)->format('Y-m'), 'weeks' => $out];
    }

    /** "8 AM", "2:30 PM": the planner month's short time. */
    private static function compactTime(CarbonImmutable $time): string
    {
        return $time->minute === 0 ? $time->format('g A') : $time->format('g:i A');
    }

    /** @return array<string, string> */
    private function week(CarbonImmutable $weekStart): array
    {
        $end = $weekStart->addDays(6);

        return ['start' => $weekStart->toDateString(), 'end' => $end->toDateString(), 'previous' => $weekStart->subWeek()->toDateString(),
            'next' => $weekStart->addWeek()->toDateString(), 'label' => $weekStart->format($weekStart->month === $end->month ? 'j' : 'j M').' – '.$end->format('j M Y')];
    }

    /** @return array<string, mixed> Own approved shifts whose branch-local start date falls in the week. */
    private function my(User $actor, CarbonImmutable $weekStart): array
    {
        $shifts = RosterShift::query()->with(['version.week.branch'])->where('organisation_id', $actor->organisation_id)->where('staff_user_id', $actor->id)
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')->where('organisation_id', $actor->organisation_id)->where('status', 'approved'))
            ->where('starts_at', '>=', $weekStart->subDay()->utc())->where('starts_at', '<', $weekStart->addDays(8)->utc())
            ->orderBy('starts_at')->get()
            ->filter(function (RosterShift $s) use ($weekStart): bool {
                $date = $s->starts_at->setTimezone($s->version->week->branch->timezone)->toDateString();

                return $date >= $weekStart->toDateString() && $date <= $weekStart->addDays(6)->toDateString();
            });
        $rooms = BranchRoom::query()->whereIn('id', $shifts->pluck('branch_room_id')->filter())->pluck('name', 'id');
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->addDays($i);
            $items = $shifts->filter(fn (RosterShift $s): bool => $s->starts_at->setTimezone($s->version->week->branch->timezone)->toDateString() === $day->toDateString())
                ->map(fn (RosterShift $s): array => $this->shift($s, $s->version->week->branch, $rooms))->values()->all();
            if ($items !== []) {
                $days[] = ['date' => $day->toDateString(), 'label' => $this->rules->shortDayLabel($day), 'shifts' => $items];
            }
        }

        // HR-3a: an outline "Clock in" link on My roster for staff who clock (Daniel: never a second primary).
        // HR-15b: a locum finds open shifts from their own roster.
        return ['days' => $days, 'canClock' => $actor->can(ClockPermissions::CLOCK_OWN), 'canAskOpenShifts' => $actor->can(OpenShiftPermissions::REQUEST_OWN)];
    }

    /**
     * HR-15f: one month of the person's own roster, Monday-first weeks, for every branch. Approved shifts by their
     * branch-local start date, plus their own open-shift asks still waiting or confirmed into a week that is not approved
     * yet. Nothing about anyone else, no leave type and no pay.
     *
     * @return array<string, mixed>
     */
    private function myMonth(User $actor, Branch $branch, mixed $month): array
    {
        $zone = $branch->timezone;
        $first = is_string($month) && preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $month, $zone) : null;
        $first = $first instanceof CarbonImmutable ? $first->startOfMonth() : CarbonImmutable::now($zone)->startOfMonth();
        // A bounded window: 2000 to 2100, so a hand-typed address cannot ask for any year.
        if ($first->year < 2000 || $first->year > 2100) {
            $first = CarbonImmutable::now($zone)->startOfMonth();
        }
        $last = $first->endOfMonth()->startOfDay();
        $gridStart = $first->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $last->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay();
        $today = CarbonImmutable::now($zone)->toDateString();

        $shifts = RosterShift::query()->with(['version.week.branch'])->where('organisation_id', $actor->organisation_id)->where('staff_user_id', $actor->id)
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')->where('organisation_id', $actor->organisation_id)->where('status', 'approved'))
            ->where('starts_at', '>=', $gridStart->subDay()->utc())->where('starts_at', '<', $gridEnd->addDays(2)->utc())
            ->orderBy('starts_at')->orderBy('id')->get();
        $rooms = BranchRoom::query()->whereIn('id', $shifts->pluck('branch_room_id')->filter())->pluck('name', 'id');
        $approvedShiftIds = $shifts->pluck('id')->all();

        // Own asks: waiting, or confirmed into a draft that is not approved yet (an approved one is already a shift).
        $asks = DB::table('hr_open_shift_requests as r')->join('hr_open_shifts as o', 'o.id', '=', 'r.open_shift_id')->join('branches as b', 'b.id', '=', 'o.branch_id')
            ->where('r.organisation_id', $actor->organisation_id)->where('r.locum_user_id', $actor->id)
            // A waiting ask shows only while its open shift is still open (Suresh, m3).
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('r.status', 'asked')->where('o.status', 'open'))->orWhere('r.status', 'confirmed'))
            ->where('o.starts_at', '>=', $gridStart->subDay()->utc())->where('o.starts_at', '<', $gridEnd->addDays(2)->utc())
            ->orderBy('o.starts_at')->get(['r.public_id', 'r.status', 'r.roster_shift_id', 'o.starts_at', 'o.ends_at', 'b.name', 'b.timezone']);
        // A confirmed ask whose draft shift HR has since removed is not shown; one lookup for all of them.
        // Only a shift still the person's own, in a draft or a week waiting for approval: a discarded draft, a replaced
        // approved week or a shift HR gave to someone else never shows as "confirmed, not approved" (Suresh, M1).
        $draftIds = RosterShift::query()->whereIn('id', $asks->pluck('roster_shift_id')->filter())->where('organisation_id', $actor->organisation_id)
            ->where('staff_user_id', $actor->id)
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')->where('organisation_id', $actor->organisation_id)->whereIn('status', ['draft', 'submitted']))
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $asks = $asks->reject(fn (\stdClass $r): bool => $r->status === 'confirmed' && ($r->roster_shift_id === null
            || in_array((int) $r->roster_shift_id, $approvedShiftIds, true) || ! in_array((int) $r->roster_shift_id, $draftIds, true)));

        $byDay = [];
        $minutes = 0;
        foreach ($shifts as $s) {
            $shiftBranch = $s->version->week->branch;
            $item = $this->shift($s, $shiftBranch, $rooms);
            $byDay[$item['date']]['shifts'][] = $item + ['hours' => self::hoursLabel($s)];
            if ($item['date'] >= $first->toDateString() && $item['date'] <= $last->toDateString()) {
                $minutes += (int) max(0, $s->starts_at->diffInMinutes($s->ends_at) - $s->break_minutes);
            }
        }
        foreach ($asks as $r) {
            $start = CarbonImmutable::parse($r->starts_at)->setTimezone((string) $r->timezone);
            $end = CarbonImmutable::parse($r->ends_at)->setTimezone((string) $r->timezone);
            $byDay[$start->toDateString()]['asks'][] = ['id' => (string) $r->public_id, 'status' => (string) $r->status, 'branch' => (string) $r->name,
                'startLabel' => $this->rules->timeLabel($start), 'endLabel' => $this->rules->timeLabel($end), 'nextDay' => $end->toDateString() !== $start->toDateString(),
                'label' => $r->status === 'asked' ? 'Asked, waiting for HR' : 'Confirmed, roster not approved yet',
                // A short word for the grid cell; the full line is in the day sheet (Daniel).
                'shortLabel' => $r->status === 'asked' ? 'Asked' : 'Not approved yet'];
        }

        $days = [];
        for ($day = $gridStart; $day->lte($gridEnd); $day = $day->addDay()) {
            $date = $day->toDateString();
            $inMonth = $day->month === $first->month;
            // Days outside the month are muted and carry nothing; they belong to the month next door (Daniel).
            $days[] = ['date' => $date, 'day' => (int) $day->format('j'), 'label' => $this->rules->shortDayLabel($day), 'inMonth' => $inMonth,
                'today' => $date === $today, 'shifts' => $inMonth ? ($byDay[$date]['shifts'] ?? []) : [], 'asks' => $inMonth ? ($byDay[$date]['asks'] ?? []) : []];
        }

        return ['key' => $first->format('Y-m'), 'label' => $first->format('F Y'), 'previous' => $first->subMonth()->format('Y-m'),
            'next' => $first->addMonth()->format('Y-m'), 'current' => CarbonImmutable::now($zone)->format('Y-m'), 'days' => $days,
            'hoursLabel' => self::durationLabel($minutes)];
    }

    private static function hoursLabel(RosterShift $s): string
    {
        return self::durationLabel((int) max(0, $s->starts_at->diffInMinutes($s->ends_at) - $s->break_minutes));
    }

    private static function durationLabel(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h} h" : "{$h} h {$m} min";
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    private function planner(User $actor, Collection $branches, Branch $branch, CarbonImmutable $weekStart, mixed $view, bool $canManage, bool $canApprove): array
    {
        $week = RosterWeek::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->toDateString())->first();
        $versions = $week === null ? collect() : RosterVersion::query()->where('roster_week_id', $week->id)->orderBy('version_number')->get();
        $approved = $versions->first(fn (RosterVersion $v): bool => $v->status === 'approved');
        $open = $versions->first(fn (RosterVersion $v): bool => $v->isOpen());
        $shown = $view === 'approved' && $approved !== null ? $approved : ($open ?? $approved);
        $previous = RosterWeek::query()->where('branch_id', $branch->id)->whereDate('week_start', $weekStart->subWeek()->toDateString())->first();
        $previousApproved = $previous !== null && RosterVersion::query()->where('roster_week_id', $previous->id)->where('status', 'approved')->exists();

        $shifts = $shown === null ? collect() : RosterShift::query()->where('roster_version_id', $shown->id)->orderBy('starts_at')->orderBy('id')->get();
        $people = User::query()->with('roles')->where('organisation_id', $actor->organisation_id)
            ->whereIn('id', $shifts->pluck('staff_user_id')->merge($versions->pluck('created_by_user_id'))->merge($versions->pluck('submitted_by_user_id'))->merge($versions->pluck('decided_by_user_id'))->filter()->unique())
            ->get()->keyBy('id');
        $rooms = BranchRoom::query()->where('organisation_id', $branch->organisation_id)->where('branch_id', $branch->id)->orderBy('sort_order')->orderBy('id')->get();
        $roomNames = $rooms->pluck('name', 'id');

        $leave = $this->leaveInWeek($branch, $weekStart, array_values($shifts->pluck('staff_user_id')->map(fn ($id): int => (int) $id)->unique()->all()));
        $leavePeople = $leave->where('status', 'approved')->pluck('user_id')->diff($people->keys());
        if ($leavePeople->isNotEmpty()) {
            $people = $people->union(User::query()->with('roles')->whereIn('id', $leavePeople)->get()->keyBy('id'));
        }
        $rows = $shifts->groupBy('staff_user_id')->map(fn (Collection $items): Collection => $items)
            ->union($leave->where('status', 'approved')->pluck('user_id')->unique()->mapWithKeys(fn (int $id): array => [$id => collect()])->diffKeys($shifts->groupBy('staff_user_id')))
            ->map(function (Collection $items, int|string $userId) use ($people, $branch, $roomNames, $leave, $weekStart): array {
                $user = $people->get((int) $userId);

                return ['staffUserId' => (int) $userId, 'name' => $user->name ?? 'Staff member', 'role' => $this->roleLabel($user),
                    'shifts' => $items->map(fn (RosterShift $s): array => $this->shift($s, $branch, $roomNames))->values()->all(),
                    'leaveDays' => $this->leaveDays($leave->where('user_id', (int) $userId)->where('status', 'approved'), $weekStart)];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        $isDraft = $shown !== null && $shown->status === 'draft';

        return [
            'branches' => $branches->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'timezone' => $branch->timezone],
            'weekLockVersion' => $week->lock_version ?? null,
            'days' => collect(range(0, 6))->map(fn (int $i): array => ['date' => $weekStart->addDays($i)->toDateString(),
                'label' => $weekStart->addDays($i)->format('D j'), 'long' => $this->rules->shortDayLabel($weekStart->addDays($i))])->all(),
            'approvedVersion' => $approved === null ? null : $this->versionSummary($approved, $people, $branch),
            'openVersion' => $open === null ? null : $this->versionSummary($open, $people, $branch),
            'shown' => $shown === null ? null : $this->versionSummary($shown, $people, $branch) + [
                'sentByYou' => $shown->submitted_by_user_id === $actor->id,
                'returnReason' => $shown->return_reason === null ? null : RosterService::RETURN_REASONS[$shown->return_reason] ?? null,
            ],
            'rows' => $rows,
            // HR-20 (Daniel): on call never folds into the "on shift" count; it has its own count beside it.
            'totals' => $this->dayCounts($branch, $weekStart, $shifts->reject(fn (RosterShift $s): bool => RosterRules::isOnCall($s->session_type))),
            'onCallTotals' => $this->dayCounts($branch, $weekStart, $shifts->filter(fn (RosterShift $s): bool => RosterRules::isOnCall($s->session_type))),
            'shortDays' => $shown === null ? [] : $this->shortDays($branch, $weekStart, $shifts, $people),
            'warnings' => $shown === null ? [] : [...$this->warnings($branch, $weekStart, $shown, $shifts, $people, $roomNames), ...$this->leaveWarnings($branch, $shifts, $leave, $people)],
            // HR-5b: compliance warnings in words and the month's rotation counts; warnings only, never a refusal.
            'compliance' => $shown === null ? null : $this->compliance->forWeek($branch, $weekStart, $shown, $shifts, $people),
            'staffOptions' => $isDraft ? $this->staffOptions($actor, $branch, $weekStart) : [],
            'rooms' => $rooms->where('is_active', true)->map(fn (BranchRoom $r): array => ['id' => $r->id, 'name' => $r->name])->values()->all(),
            'stations' => collect(RosterRules::STATIONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'planStations' => $this->minimums->plansStations($branch),
            'returnReasons' => collect(RosterService::RETURN_REASONS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'previousWeekApproved' => $previousApproved,
            'can' => [
                'start' => $canManage && $open === null && $approved === null,
                'copy' => $canManage && $open === null && $approved === null && $previousApproved,
                'change' => $canManage && $open === null && $approved !== null,
                'edit' => $canManage && $isDraft,
                'submit' => $canManage && $isDraft,
                'discard' => $canManage && $isDraft,
                'decide' => $canApprove && $shown !== null && $shown->status === 'submitted' && $shown->submitted_by_user_id !== $actor->id,
            ],
        ];
    }

    /**
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>
     */
    private function versionSummary(RosterVersion $version, Collection $people, Branch $branch): array
    {
        $name = fn (?int $id): ?string => $id === null ? null : ($people->get($id)->name ?? 'Staff member');
        $at = fn ($time): ?string => $time?->setTimezone($branch->timezone)->format('j M Y, g:i A');

        return ['id' => $version->public_id, 'number' => $version->version_number, 'status' => $version->status, 'lockVersion' => $version->lock_version,
            'createdBy' => $name($version->created_by_user_id), 'submittedBy' => $name($version->submitted_by_user_id), 'submittedAt' => $at($version->submitted_at),
            'decidedBy' => $name($version->decided_by_user_id), 'decidedAt' => $at($version->decided_at), 'changedAt' => $at($version->updated_at)];
    }

    /**
     * People with a shift starting on each branch-local day of the week.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @return array<int, int>
     */
    private function dayCounts(Branch $branch, CarbonImmutable $weekStart, Collection $shifts): array
    {
        return collect(range(0, 6))->map(fn (int $i): int => $shifts->filter(fn (RosterShift $s): bool => $s->starts_at->setTimezone($branch->timezone)->toDateString() === $weekStart->addDays($i)->toDateString())
            ->pluck('staff_user_id')->unique()->count())->values()->all();
    }

    /**
     * @param  Collection<int|string, string>  $rooms
     * @return array<string, mixed>
     */
    private function shift(RosterShift $s, Branch $branch, Collection $rooms): array
    {
        $start = $s->starts_at->setTimezone($branch->timezone);
        $end = $s->ends_at->setTimezone($branch->timezone);

        return ['id' => $s->public_id, 'staffUserId' => $s->staff_user_id, 'branch' => $branch->name, 'date' => $start->toDateString(),
            'startTime' => $start->format('H:i'), 'endTime' => $end->format('H:i'), 'startLabel' => $this->rules->timeLabel($start), 'endLabel' => $this->rules->timeLabel($end),
            'nextDay' => $end->toDateString() !== $start->toDateString(), 'breakMinutes' => $s->break_minutes, 'sessionType' => $s->session_type,
            'sessionLabel' => RosterRules::SESSIONS[$s->session_type] ?? 'Other', 'roomId' => $s->branch_room_id, 'roomName' => $s->branch_room_id === null ? null : ($rooms[$s->branch_room_id] ?? null),
            'station' => $s->station, 'stationLabel' => $s->station === null ? null : (RosterRules::STATIONS[$s->station] ?? null)];
    }

    /**
     * HR-2a: approved and pending leave touching this branch week, for the people rostered in it and anyone whose
     * leave is counted at this branch. Only "On leave" is shown; never the type or reason.
     *
     * @param  list<int>  $rostered
     * @return Collection<int, LeaveRequest>
     */
    private function leaveInWeek(Branch $branch, CarbonImmutable $weekStart, array $rostered): Collection
    {
        $from = CarbonImmutable::parse($weekStart->toDateString(), $branch->timezone)->utc();
        $to = $from->addDays(7);
        $atBranch = LeaveRequest::query()->where('branch_id', $branch->id)->where('status', 'approved')
            ->whereDate('start_date', '<=', $weekStart->addDays(6)->toDateString())->whereDate('end_date', '>=', $weekStart->toDateString())->pluck('user_id')->map(fn ($id): int => (int) $id)->all();

        return $this->leave->overlapping(array_values(array_unique([...$rostered, ...$atBranch])), $from, $to, ['approved', 'pending']);
    }

    /**
     * @param  Collection<int, LeaveRequest>  $requests
     * @return array<string, string> date => full|half
     */
    private function leaveDays(Collection $requests, CarbonImmutable $weekStart): array
    {
        $out = [];
        $calendars = [];
        foreach ($requests as $r) {
            // Only the days the leave counts (the leave branch's working days, not its public holidays), as on the team calendar.
            $calendars[$r->branch_id] ??= ($b = Branch::query()->find($r->branch_id)) === null ? [[], []]
                : [$this->leave->workingDays($b) ?? [], $this->leave->holidays($b, $weekStart, $weekStart->addDays(6))];
            [$working, $holidays] = $calendars[$r->branch_id];
            for ($d = CarbonImmutable::parse($r->start_date->toDateString()); $d->lessThanOrEqualTo(CarbonImmutable::parse($r->end_date->toDateString())); $d = $d->addDay()) {
                $date = $d->toDateString();
                if ($date < $weekStart->toDateString() || $date > $weekStart->addDays(6)->toDateString() || isset($holidays[$date]) || ! in_array($d->dayOfWeekIso, $working, true)) {
                    continue;
                }
                $half = ($r->start_date->toDateString() === $date && $r->start_part !== 'full') || ($r->end_date->toDateString() === $date && $r->end_part === 'am');
                $out[$date] = isset($out[$date]) || ! $half ? 'full' : 'half';
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, LeaveRequest>  $leave
     * @param  Collection<int, User>  $people
     * @return list<string>
     */
    private function leaveWarnings(Branch $branch, Collection $shifts, Collection $leave, Collection $people): array
    {
        $out = [];
        $zones = Branch::query()->whereIn('id', $leave->pluck('branch_id')->unique())->pluck('timezone', 'id');
        foreach ($shifts as $s) {
            foreach ($leave->where('user_id', $s->staff_user_id) as $r) {
                [$from, $to] = $this->leave->utcSlots($r, (string) ($zones[$r->branch_id] ?? 'UTC'));
                if (! ($from->lessThan($s->ends_at) && $to->greaterThan($s->starts_at))) {
                    continue;
                }
                $day = $this->rules->dayLabel(CarbonImmutable::parse($s->starts_at)->setTimezone($branch->timezone));
                $name = $people->get($s->staff_user_id)->name ?? 'A staff member';
                $out[] = $r->status === 'approved'
                    ? "{$name} is on approved leave on {$day}. Change or remove this shift."
                    : "{$name} has asked for leave on {$day}.";
            }
        }

        return array_values(array_unique($out));
    }

    private function roleLabel(?User $user): string
    {
        if ($user === null) {
            return '';
        }
        $roles = $user->roles->pluck('name')->all();
        foreach (array_keys(RosterRules::ROLE_LABELS) as $role) {
            if (in_array($role, $roles, true)) {
                return RosterRules::ROLE_LABELS[$role];
            }
        }

        // HR-15a: a locum sign-in holds the Locum role only (kept out of ROLE_LABELS, which lists HR audiences).
        return in_array(PermissionCatalogue::LOCUM_ROLE, $roles, true) ? 'Locum' : '';
    }

    /** @return list<array{id:int, label:string}> Active human staff assigned to the branch on at least one day of the week. */
    private function staffOptions(User $actor, Branch $branch, CarbonImmutable $weekStart): array
    {
        $start = $weekStart->toDateString();
        $end = $weekStart->addDays(6)->toDateString();
        $profiles = StaffBranchAssignment::query()->where('branch_id', $branch->id)->whereDate('valid_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $start))->pluck('staff_profile_id');

        return array_values(User::query()->with('roles')->where('organisation_id', $actor->organisation_id)->where('is_active', true)
            ->whereHas('staffProfile', fn ($q) => $q->whereIn('id', $profiles))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'queue_display'))
            ->orderBy('name')->get()
            ->map(fn (User $u): array => ['id' => $u->id, 'label' => trim($u->name.' · '.$this->roleLabel($u), ' ·'),
                // HR-22c: only a CA, CA Supervisor or Medical Assistant takes a station.
                'takesStation' => RosterRules::takesStation($u)])->all());
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, User>  $people
     * @return list<array{date:string, label:string}>
     */
    private function shortDays(Branch $branch, CarbonImmutable $weekStart, Collection $shifts, Collection $people): array
    {
        $out = [];
        foreach ($this->shortfalls($branch, $weekStart, $shifts, $people) as $s) {
            $out[$s['date']] = ['date' => $s['date'], 'label' => $s['day']];
        }

        return array_values($out);
    }

    /**
     * One line per short day. A "Full day" shift also counts towards Morning and Afternoon. The HR dashboard (HR-9,
     * HrDashboardService) depends on this method so that it can never disagree with the Planner: keep its output shape.
     *
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, User>  $people
     * @return list<array{date:string, day:string, message:string}>
     */
    public function shortfalls(Branch $branch, CarbonImmutable $weekStart, Collection $shifts, Collection $people): array
    {
        $minimums = $this->minimums->minimums($branch);
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->addDays($i);
            $gaps = [];
            foreach ($minimums as $session => $roles) {
                foreach ($roles as $role => $minimum) {
                    if ($minimum <= 0) {
                        continue;
                    }
                    $count = $shifts->filter(fn (RosterShift $s): bool => ($s->session_type === $session || ($s->session_type === 'full_day' && in_array($session, ['morning', 'afternoon'], true)))
                        && $s->starts_at->setTimezone($branch->timezone)->toDateString() === $day->toDateString()
                        && ($people->get($s->staff_user_id)?->roles->contains('name', $role) ?? false))
                        ->pluck('staff_user_id')->unique()->count();
                    if ($count < $minimum) {
                        [$one, $many] = RosterRules::STAFFING_ROLES[$role];
                        $gaps[] = RosterRules::SESSIONS[$session].' '.$count.' '.($count === 1 ? $one : $many)." (minimum {$minimum})";
                    }
                }
            }
            if ($gaps !== []) {
                $out[] = ['date' => $day->toDateString(), 'day' => $this->rules->shortDayLabel($day), 'message' => "Short on {$this->rules->shortDayLabel($day)}: ".implode('; ', $gaps).'.'];
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, RosterShift>  $shifts
     * @param  Collection<int, User>  $people
     * @param  Collection<int|string, string>  $rooms
     * @return list<string>
     */
    private function warnings(Branch $branch, CarbonImmutable $weekStart, RosterVersion $version, Collection $shifts, Collection $people, Collection $rooms): array
    {
        $out = array_map(fn (array $s): string => $s['message'], $this->shortfalls($branch, $weekStart, $shifts, $people));
        $withRoom = $shifts->filter(fn (RosterShift $s): bool => $s->branch_room_id !== null)->values();
        $seen = [];
        foreach ($withRoom as $i => $a) {
            foreach ($withRoom->slice($i + 1) as $b) {
                if ($a->branch_room_id === $b->branch_room_id && $a->staff_user_id !== $b->staff_user_id && $a->starts_at < $b->ends_at && $b->starts_at < $a->ends_at) {
                    $at = $a->starts_at->greaterThan($b->starts_at) ? $a->starts_at : $b->starts_at;
                    $local = $at->setTimezone($branch->timezone);
                    $message = ($rooms[$a->branch_room_id] ?? 'A room')." is proposed for two people on {$this->rules->shortDayLabel($local)} at {$this->rules->timeLabel($local)}.";
                    $seen[$message] = true;
                }
            }
        }
        $out = [...$out, ...array_keys($seen)];
        // HR-22c: only when the branch plans stations, one quiet line per person whose CA, CA Supervisor or MA shifts lack one.
        if ($this->minimums->plansStations($branch)) {
            $missing = $shifts->filter(fn (RosterShift $s): bool => $s->station === null && ! RosterRules::isOnCall($s->session_type)
                && ($person = $people->get($s->staff_user_id)) !== null && RosterRules::takesStation($person));
            foreach ($missing->groupBy('staff_user_id') as $userId => $items) {
                $name = $people->get((int) $userId)->name ?? 'A staff member';
                $days = $items->map(fn (RosterShift $s): string => $this->rules->shortDayLabel($s->starts_at->setTimezone($branch->timezone)))->unique()->values()->all();
                $out[] = "No station for {$name} on ".implode(', ', $days).'.';
            }
        }
        if ($version->isOpen()) {
            foreach ($shifts as $s) {
                foreach ($this->rules->draftOverlaps($version, $s->staff_user_id, $s->starts_at, $s->ends_at) as $other) {
                    $otherBranch = $other->version->week->branch;
                    $start = $other->starts_at->setTimezone($otherBranch->timezone);
                    $end = $other->ends_at->setTimezone($otherBranch->timezone);
                    $name = $people->get($s->staff_user_id)->name ?? 'A staff member';
                    $out[] = "{$name} also has a draft shift at {$otherBranch->name} on {$this->rules->shortDayLabel($start)}, {$this->rules->timeLabel($start)} – {$this->rules->timeLabel($end)}.";
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    private function minimumsPage(User $actor, Collection $branches, Branch $branch): array
    {
        $canRules = $actor->can(CredentialPermissions::MANAGE);
        $coverage = $canRules ? ComplianceRulesService::coverage($branch->id) : null;
        $work = $canRules ? ComplianceRulesService::workHours($branch->organisation_id) : null;

        return [
            // HR-5b rules (Director and HR Manager, hr.credentials.manage.organisation). KPOne ships no number.
            'compliance' => ! $canRules ? null : [
                'suggested' => ComplianceRulesService::SUGGESTED,
                'coverage' => ['version' => $coverage['version'] ?? 0, 'hours' => $coverage['hours'] ?? [], 'minDoctors' => $coverage['minDoctors'] ?? null],
                'workHours' => ['version' => $work['version'] ?? 0, 'maxDaysInRow' => $work['maxDaysInRow'] ?? null,
                    'minRestHours' => isset($work['minRestMinutes']) ? intdiv($work['minRestMinutes'], 60) : null,
                    'maxWeekHours' => isset($work['maxWeekMinutes']) ? intdiv($work['maxWeekMinutes'], 60) : null],
            ],
            'branches' => $branches->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'values' => $this->minimums->minimums($branch),
            'lockVersion' => $this->minimums->lockVersion($branch),
            'planStations' => $this->minimums->plansStations($branch),
            'roles' => collect(RosterRules::STAFFING_ROLES)->map(fn (array $labels, string $value): array => ['value' => $value, 'label' => $labels[0], 'plural' => $labels[1]])->values()->all(),
        ];
    }
}
