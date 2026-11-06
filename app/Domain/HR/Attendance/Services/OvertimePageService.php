<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\Access\BranchAccessService;
use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\OvertimeRequest;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** HR-3b page props: overtime on My attendance and Review, the Overtime and Summary tabs and the overtime settings card. Read only. */
final class OvertimePageService
{
    public const STATUS_LABELS = ['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn', 'reversed' => 'Reversed'];

    /** @var array<int, Branch|null> */
    private array $branchCache = [];

    public function __construct(
        private BranchAccessService $branches,
        private OvertimeSettingsService $settings,
        private AttendanceSettingsService $attendance,
        private OvertimeDayTypes $dayTypes,
        private AttendanceTotals $totals,
    ) {}

    /** @return array<string, array<int, array{value: string, label: string}>> */
    public function options(): array
    {
        $list = fn (array $map): array => collect($map)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();

        return ['reasons' => $list(OvertimeService::REASONS), 'lowerReasons' => $list(OvertimeService::LOWER_REASONS),
            'rejectReasons' => $list(OvertimeService::REJECT_REASONS), 'reverseReasons' => $list(OvertimeService::REVERSE_REASONS)];
    }

    public function pendingCount(User $actor): int
    {
        return OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('status', 'pending')
            ->where('user_id', '<>', $actor->id)->where('requested_by_user_id', '<>', $actor->id)->count();
    }

    /**
     * Overtime additions to My attendance: weekly hours, the days that can be asked for, my requests and my month.
     *
     * @param  array<int, array<string, mixed>>  $rows  HR-3a day rows of the last 31 days and this week
     * @return array<string, mixed>
     */
    public function my(User $actor, array $rows, CarbonImmutable $today): array
    {
        $version = $this->settings->forDate($actor->organisation_id, $today);
        $eligible = $version !== null && $this->settings->eligible($version, $actor);
        $week = $this->totals->week($actor->organisation_id, [$actor->id], $today)[$actor->id];
        $monthStart = $today->startOfMonth();

        return [
            'ready' => $version !== null,
            'eligible' => $eligible,
            'weekHours' => $week,
            'choices' => $eligible ? $this->choices($actor, $rows, $today) : [],
            'requests' => $this->requests(OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('user_id', $actor->id)
                ->orderByDesc('id')->limit(20)->get(), $actor),
            'month' => [
                'label' => $monthStart->format('F Y'),
                'figures' => $this->figures($this->totals->summary($actor->organisation_id, [$actor->id], $monthStart, $today)[$actor->id]),
            ],
        ];
    }

    /**
     * Each closed day in the rows that can still be asked for, keyed by session, with the most minutes allowed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function choices(User $subject, array $rows, CarbonImmutable $today): array
    {
        $attendance = $this->attendance->forDate($subject->organisation_id, $today);
        if ($attendance === null) {
            return [];
        }
        $earliest = $today->subDays($attendance->correctionDays)->toDateString();
        $sessionIds = collect($rows)->pluck('session.publicId')->filter()->values();
        $taken = DB::table('hr_overtime_requests')->join('hr_clock_sessions', 'hr_clock_sessions.id', '=', 'hr_overtime_requests.clock_session_id')
            ->whereIn('hr_overtime_requests.status', ['pending', 'approved'])->whereIn('hr_clock_sessions.public_id', $sessionIds)
            ->pluck('hr_clock_sessions.public_id')->flip();
        $out = [];
        foreach ($rows as $row) {
            $session = $row['session'];
            if ($session === null || $session['status'] !== 'closed' || $row['date'] < $earliest || $row['date'] > $today->toDateString()
                || $row['correctionWaiting'] || isset($taken[$session['publicId']]) || $row['userId'] !== $subject->id) {
                continue;
            }
            $version = $this->settings->forDate($subject->organisation_id, $row['date']);
            if ($version === null || ! $this->settings->eligible($version, $subject)) {
                continue;
            }
            $allowance = $this->allowance($row);
            if ($allowance['max'] < 1) {
                continue;
            }
            $out[] = ['sessionId' => $session['publicId'], 'date' => $row['date'], 'label' => $row['dateLabel'].', '.$session['in'].' – '.$session['out'].', '.$session['branch'],
                ...$allowance];
        }

        return $out;
    }

    /**
     * The same allowance as OvertimeService::allowance, from a day row already worked out.
     *
     * @param  array<string, mixed>  $row
     * @return array{max: int, worked: string, rostered: string|null, dayType: string, dayLabel: string}
     */
    public function allowance(array $row): array
    {
        $branch = $this->branch((int) $row['session']['branchId']);
        $day = $branch === null ? ['type' => 'normal', 'label' => OvertimeDayTypes::LABELS['normal']] : $this->dayTypes->of($branch, CarbonImmutable::parse($row['date']));
        $worked = (int) ($row['workedMinutes'] ?? 0);
        $rostered = $row['rosteredMinutes'];
        $max = $day['type'] === 'normal' && $rostered !== null ? max(0, $worked - (int) $rostered) : $worked;

        return ['max' => min($max, 1440), 'worked' => AttendanceSupport::duration($worked), 'rostered' => $rostered === null ? null : AttendanceSupport::duration((int) $rostered),
            'dayType' => $day['type'], 'dayLabel' => $day['label']];
    }

    /**
     * Overtime tab: requests waiting (with the person's hours that week) and decided ones for a month.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function overtimeTab(User $actor, array $query): array
    {
        $today = $this->attendance->today($actor->organisation_id);
        $month = is_string($query['month'] ?? null) && preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $query['month']) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $query['month'], $today->getTimezone()) : false;
        $month = $month instanceof CarbonImmutable ? $month->startOfMonth() : $today->startOfMonth();
        $pending = OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('status', 'pending')->orderBy('id')->limit(100)->get();
        $decided = OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->whereNotIn('status', ['pending'])
            ->whereDate('local_date', '>=', $month->toDateString())->whereDate('local_date', '<=', $month->endOfMonth()->toDateString())
            ->orderByDesc('local_date')->orderByDesc('id')->limit(200)->get();
        $pendingRows = $this->requests($pending, $actor, withWeek: true);
        $decidedRows = $this->requests($decided, $actor);
        $changed = count(array_filter($decidedRows, fn (array $r): bool => $r['hoursChanged']));
        $waiting = count($pendingRows);

        return [
            'ready' => $this->settings->forDate($actor->organisation_id, $today) !== null,
            'summary' => ($waiting === 1 ? '1 waiting' : "{$waiting} waiting").($changed > 0 ? ' · '.($changed === 1 ? '1 hours changed after approval' : "{$changed} hours changed after approval") : ''),
            'pending' => $pendingRows,
            'decided' => $decidedRows,
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'prevMonth' => $month->subMonth()->format('Y-m'),
            'nextMonth' => $month->addMonth()->format('Y-m'),
        ];
    }

    /**
     * Summary tab: one row per person assigned to the branch, or who worked there, in the week or month holding the date.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function summaryTab(User $actor, array $query): array
    {
        $branches = Branch::query()->where('organisation_id', $actor->organisation_id)->where('is_active', true)->orderBy('name')->get();
        $branch = $branches->firstWhere('id', filter_var($query['branch'] ?? null, FILTER_VALIDATE_INT))
            ?? $branches->firstWhere('id', $this->branches->activeBranch($actor)?->id) ?? $branches->first();
        if ($branch === null) {
            return ['branches' => [], 'branch' => null, 'rows' => []];
        }
        $period = ($query['period'] ?? null) === 'week' ? 'week' : 'month';
        $today = CarbonImmutable::now($branch->timezone)->startOfDay();
        $date = is_string($query['date'] ?? null) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $query['date']) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $query['date'], $branch->timezone) : false;
        $date = $date instanceof CarbonImmutable && $date->toDateString() === $query['date'] ? $date : $today;
        [$from, $to] = $period === 'week'
            ? [$date->startOfWeek(CarbonImmutable::MONDAY), $date->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)]
            : [$date->startOfMonth(), $date->endOfMonth()->startOfDay()];

        $assigned = StaffBranchAssignment::query()->where('branch_id', $branch->id)->whereDate('valid_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from->toDateString()))
            ->join('staff_profiles', 'staff_profiles.id', '=', 'staff_branch_assignments.staff_profile_id')->pluck('staff_profiles.user_id');
        $worked = DB::table('hr_clock_sessions')->where('branch_id', $branch->id)->whereDate('local_date', '>=', $from->toDateString())
            ->whereDate('local_date', '<=', $to->toDateString())->distinct()->pluck('user_id');
        // Human staff only: the TV account holds none of the human roles.
        $people = User::query()->where('organisation_id', $actor->organisation_id)->whereIn('id', $assigned->merge($worked)->unique()->values())
            ->whereHas('roles', fn ($q) => $q->whereIn('name', RosterPermissions::HUMAN_ROLES))->orderBy('name')->get(['id', 'name']);
        $ids = array_values($people->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $summary = $this->totals->summary($actor->organisation_id, $ids, $from, $to, $branch->id);

        return [
            'branches' => $branches->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'branch' => ['id' => $branch->id, 'name' => $branch->name],
            'period' => $period,
            'date' => $date->toDateString(),
            'periodLabel' => $period === 'week' ? AttendanceSupport::day($from).' to '.AttendanceSupport::day($to) : $from->format('F Y'),
            'prevDate' => ($period === 'week' ? $from->subWeek() : $from->subMonth())->toDateString(),
            'nextDate' => ($period === 'week' ? $from->addWeek() : $from->addMonth())->toDateString(),
            'settingsReady' => $this->attendance->forDate($actor->organisation_id, $to->min($today)) !== null,
            'rows' => $people->map(fn (User $u): array => ['userId' => $u->id, 'person' => $u->name, ...$this->figures($summary[$u->id])])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function settingsCard(User $actor): array
    {
        $versions = $this->settings->versions($actor->organisation_id);
        $today = $this->attendance->today($actor->organisation_id);
        $current = $this->settings->forDate($actor->organisation_id, $today);
        $creators = User::query()->whereIn('id', $versions->map(fn (OvertimeSettingsVersion $v): int => $v->createdByUserId)->unique()->values())->pluck('name', 'id');
        $roleLabel = fn (string $role): string => ucfirst(str_replace('_', ' ', $role));

        return [
            'current' => $current === null ? null : ['normalMultiplier' => $current->normalMultiplier, 'restDayMultiplier' => $current->restDayMultiplier,
                'publicHolidayMultiplier' => $current->publicHolidayMultiplier, 'nearingHours' => $current->nearingHours, 'limitHours' => $current->limitHours,
                'roles' => $current->roles, 'effectiveFrom' => AttendanceSupport::day(CarbonImmutable::parse($current->effectiveFrom))],
            'suggested' => ['normalMultiplier' => OvertimeSettingsService::SUGGESTED['normal_multiplier'], 'restDayMultiplier' => OvertimeSettingsService::SUGGESTED['rest_day_multiplier'],
                'publicHolidayMultiplier' => OvertimeSettingsService::SUGGESTED['public_holiday_multiplier'], 'nearingHours' => OvertimeSettingsService::SUGGESTED['nearing_hours'],
                'limitHours' => OvertimeSettingsService::SUGGESTED['limit_hours']],
            'roleOptions' => array_map(fn (string $r): array => ['value' => $r, 'label' => $roleLabel($r)], RosterPermissions::HUMAN_ROLES),
            'history' => $versions->take(10)->map(fn (OvertimeSettingsVersion $v): array => ['id' => $v->id, 'effectiveFrom' => AttendanceSupport::day(CarbonImmutable::parse($v->effectiveFrom)),
                'summary' => "Normal {$v->normalMultiplier}x · rest day {$v->restDayMultiplier}x · public holiday {$v->publicHolidayMultiplier}x · nearing {$v->nearingHours} h · limit {$v->limitHours} h · "
                    .($v->roles === [] ? 'no role may claim' : implode(', ', array_map($roleLabel, $v->roles))),
                'by' => (string) ($creators[$v->createdByUserId] ?? 'Staff member')])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function figures(array $s): array
    {
        $overtime = array_sum($s['overtime']);

        return [
            'rostered' => AttendanceSupport::duration($s['rostered']),
            'worked' => AttendanceSupport::duration($s['worked']),
            'late' => $s['late'] === 0 ? '0' : "{$s['late']} ({$s['lateMinutes']} min)",
            'leftEarly' => (string) $s['leftEarly'],
            'noClockIn' => (string) $s['noClockIn'],
            'noClockOut' => (string) $s['noClockOut'],
            'overtime' => $overtime === 0 ? 'None' : collect(['normal' => 'normal', 'rest_day' => 'rest day', 'public_holiday' => 'public holiday'])
                ->filter(fn (string $label, string $type): bool => $s['overtime'][$type] > 0)
                ->map(fn (string $label, string $type): string => AttendanceSupport::duration($s['overtime'][$type]).' '.$label)->implode(' · '),
            'otherBranch' => $s['otherBranch'],
        ];
    }

    /**
     * @param  Collection<int, OvertimeRequest>  $requests
     * @return array<int, array<string, mixed>>
     */
    private function requests(Collection $requests, User $actor, bool $withWeek = false): array
    {
        $names = User::query()->whereIn('id', $requests->pluck('user_id')->merge($requests->pluck('requested_by_user_id'))->unique())->pluck('name', 'id');
        $sessions = DB::table('hr_clock_sessions')->whereIn('id', $requests->pluck('clock_session_id'))->get()->keyBy('id');
        // An approved request whose session was corrected after the approval: its minutes stay, and a manager decides.
        $corrected = DB::table('hr_attendance_corrections')->whereIn('clock_session_id', $requests->where('status', 'approved')->pluck('clock_session_id'))
            ->where('status', 'approved')->get(['clock_session_id', 'decided_at'])->groupBy('clock_session_id');
        $weeks = [];
        if ($withWeek) {
            foreach ($requests->groupBy(fn (OvertimeRequest $r): string => $r->local_date->startOfWeek(CarbonImmutable::MONDAY)->toDateString()) as $start => $group) {
                $weeks[$start] = $this->totals->week($actor->organisation_id, array_values($group->pluck('user_id')->unique()->map(fn ($id): int => (int) $id)->all()), CarbonImmutable::parse((string) $start));
            }
        }

        return $requests->map(function (OvertimeRequest $r) use ($names, $sessions, $corrected, $weeks, $actor, $withWeek): array {
            $branch = $this->branch($r->branch_id);
            $session = $sessions[$r->clock_session_id] ?? null;
            $zone = $session !== null ? (string) $session->timezone : 'UTC';
            $times = $session === null ? '' : AttendanceSupport::time(CarbonImmutable::parse((string) $session->clock_in_at), $zone).' – '
                .AttendanceSupport::time($session->clock_out_at === null ? null : CarbonImmutable::parse((string) $session->clock_out_at), $zone);
            $hoursChanged = $r->status === 'approved' && $r->decided_at !== null
                && ($corrected[$r->clock_session_id] ?? collect())->contains(fn (object $c): bool => CarbonImmutable::parse((string) $c->decided_at)->greaterThan($r->decided_at));
            $week = $withWeek ? ($weeks[$r->local_date->startOfWeek(CarbonImmutable::MONDAY)->toDateString()][$r->user_id] ?? null) : null;
            $reason = $r->status === 'rejected' ? (OvertimeService::REJECT_REASONS[$r->decision_reason ?? ''] ?? null)
                : ($r->status === 'reversed' ? (OvertimeService::REVERSE_REASONS[$r->reverse_reason ?? ''] ?? null)
                    : ($r->decision_reason !== null ? (OvertimeService::LOWER_REASONS[$r->decision_reason] ?? null) : null));

            return [
                'publicId' => $r->public_id,
                'person' => (string) ($names[$r->user_id] ?? 'Staff member'),
                'dayLabel' => AttendanceSupport::day($r->local_date).($times !== '' ? ', '.$times : ''),
                'branch' => $branch !== null ? $branch->name : '',
                'dayType' => $r->day_type,
                'dayTypeLabel' => OvertimeDayTypes::LABELS[$r->day_type] ?? $r->day_type,
                'minutesRequested' => $r->minutes_requested,
                'requestedLabel' => AttendanceSupport::duration($r->minutes_requested),
                'approvedLabel' => $r->minutes_approved === null ? null : AttendanceSupport::duration($r->minutes_approved),
                'fewer' => $r->minutes_approved !== null && $r->minutes_approved < $r->minutes_requested,
                'reason' => OvertimeService::REASONS[$r->reason_code] ?? $r->reason_code,
                'note' => $r->note_encrypted,
                'status' => $r->status,
                'statusLabel' => $r->minutes_approved !== null && $r->minutes_approved < $r->minutes_requested && $r->status === 'approved'
                    ? 'Fewer minutes approved' : (self::STATUS_LABELS[$r->status] ?? $r->status),
                'decisionReason' => $reason,
                'onBehalf' => $r->requested_by_user_id !== $r->user_id,
                'requestedBy' => (string) ($names[$r->requested_by_user_id] ?? 'Staff member'),
                'hoursChanged' => $hoursChanged,
                'week' => $week,
                'canDecide' => $r->status === 'pending' && $actor->can(ClockPermissions::OVERTIME_APPROVE) && $r->user_id !== $actor->id && $r->requested_by_user_id !== $actor->id,
                'canReverse' => $r->status === 'approved' && $actor->can(ClockPermissions::OVERTIME_APPROVE) && $r->user_id !== $actor->id,
                'canWithdraw' => $r->status === 'pending' && $r->requested_by_user_id === $actor->id,
                'lockVersion' => $r->lock_version,
            ];
        })->values()->all();
    }

    private function branch(int $id): ?Branch
    {
        return array_key_exists($id, $this->branchCache) ? $this->branchCache[$id] : ($this->branchCache[$id] = Branch::query()->find($id));
    }
}
