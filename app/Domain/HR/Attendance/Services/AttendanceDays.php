<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Roster\Models\RosterShift;
use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * HR-3a roster against attendance. Flags are worked out when a page is opened and never stored as the truth. Each
 * session is matched to the approved shift it overlaps most (one session per shift). Without a saved settings version
 * for the day, rows show times only and no flags ("Attendance is not set up yet").
 */
final class AttendanceDays
{
    /** An open clock-in this long before a shift starts belongs to that shift. */
    private const EARLY_ARRIVAL_MINUTES = 120;

    public function __construct(private AttendanceSettingsService $settings) {}

    /**
     * Approved shifts of these people whose start falls on a branch-local date in [$from, $to].
     *
     * @param  list<int>  $userIds
     * @return Collection<int, RosterShift>
     */
    public function shifts(int $organisationId, array $userIds, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null): Collection
    {
        return RosterShift::query()->with(['version.week.branch'])->where('organisation_id', $organisationId)
            ->when($userIds !== [], fn ($q) => $q->whereIn('staff_user_id', $userIds))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('roster_version_id', RosterVersion::query()->select('id')->where('organisation_id', $organisationId)->where('status', 'approved'))
            ->where('starts_at', '>=', $from->subDay()->utc())->where('starts_at', '<', $to->addDays(2)->utc())->orderBy('starts_at')->get()
            ->filter(function (RosterShift $s) use ($from, $to): bool {
                $date = $s->starts_at->setTimezone($s->timezone)->toDateString();

                return $date >= $from->toDateString() && $date <= $to->toDateString();
            })->values();
    }

    /**
     * @param  Collection<int, ClockSession>  $sessions
     * @param  Collection<int, RosterShift>  $shifts
     * @param  array<int, true>  $waiting  session ids with a correction waiting
     * @return list<array<string, mixed>>
     */
    public function rows(int $organisationId, Collection $sessions, Collection $shifts, array $waiting = []): array
    {
        $now = CarbonImmutable::now('UTC');
        $pairs = $this->match($sessions, $shifts, $now);
        $leave = $this->leaveDays($organisationId, $sessions, $shifts);
        $branches = Branch::query()->whereIn('id', $sessions->pluck('branch_id')->merge($shifts->pluck('branch_id'))->unique())->pluck('name', 'id');
        $rows = [];
        $matchedSessions = [];
        foreach ($shifts as $shift) {
            $session = isset($pairs[$shift->id]) ? $sessions->firstWhere('id', $pairs[$shift->id]) : null;
            if ($session !== null) {
                $matchedSessions[$session->id] = true;
            }
            $rows[] = $this->row($organisationId, $shift, $session, $now, $leave, $branches, $waiting);
        }
        foreach ($sessions as $session) {
            if (! isset($matchedSessions[$session->id])) {
                $rows[] = $this->row($organisationId, null, $session, $now, $leave, $branches, $waiting);
            }
        }
        usort($rows, fn (array $a, array $b): int => [$a['sortAt'], $a['userId']] <=> [$b['sortAt'], $b['userId']]);

        return $rows;
    }

    /**
     * A split shift (Suresh): the open clock-in's shift ended more than the forgotten window ago and the person's next
     * approved shift that day starts within the early-arrival window, so the morning was never clocked out. Staying late
     * on one shift is not this: without a later shift the open clock-in stays open (it may be overtime, HR-3b).
     */
    public function forgottenBeforeNextShift(ClockSession $open, CarbonImmutable $now): bool
    {
        $settings = $this->settings->forDate($open->organisation_id, $open->local_date);
        if ($settings === null || $open->clock_out_at !== null) {
            return false;
        }
        $shifts = $this->shifts($open->organisation_id, [$open->user_id], $open->local_date, $open->local_date);
        $own = $shifts->first(fn (RosterShift $s): bool => $s->ends_at->greaterThan($open->clock_in_at)
            && $s->starts_at->lessThanOrEqualTo($open->clock_in_at->addMinutes(self::EARLY_ARRIVAL_MINUTES)));
        if ($own === null || ! $now->greaterThan($own->ends_at->addMinutes($settings->forgottenWindowMinutes))) {
            return false;
        }

        return $shifts->contains(fn (RosterShift $s): bool => $s->starts_at->greaterThanOrEqualTo($own->ends_at)
            && $now->greaterThanOrEqualTo($s->starts_at->subMinutes(self::EARLY_ARRIVAL_MINUTES)));
    }

    /** Minutes worked in a closed session: the shift's break comes off only when the session covers the whole shift. */
    public static function worked(ClockSession $session, ?RosterShift $shift): ?int
    {
        if ($session->clock_out_at === null) {
            return null;
        }
        $minutes = (int) $session->clock_in_at->diffInMinutes($session->clock_out_at);
        if ($shift !== null && $session->clock_in_at->lessThanOrEqualTo($shift->starts_at) && $session->clock_out_at->greaterThanOrEqualTo($shift->ends_at)) {
            $minutes -= $shift->break_minutes;
        }

        return max(0, $minutes);
    }

    /**
     * @param  Collection<int, ClockSession>  $sessions
     * @param  Collection<int, RosterShift>  $shifts
     * @return array<int, int> shift id => session id
     */
    private function match(Collection $sessions, Collection $shifts, CarbonImmutable $now): array
    {
        $candidates = [];
        foreach ($shifts as $shift) {
            foreach ($sessions as $session) {
                if ($session->user_id !== $shift->staff_user_id) {
                    continue;
                }
                $end = $session->clock_out_at ?? CarbonImmutable::createFromTimestamp(min($now->getTimestamp(), $session->clock_in_at->addHours(AttendanceSupport::MAX_SESSION_HOURS)->getTimestamp()));
                $from = max($shift->starts_at->getTimestamp(), $session->clock_in_at->getTimestamp());
                $to = min($shift->ends_at->getTimestamp(), $end->getTimestamp());
                if ($to > $from) {
                    $candidates[] = [$to - $from, $shift->id, $session->id];
                } elseif ($session->clock_out_at === null && $session->clock_in_at->lessThanOrEqualTo($shift->starts_at)
                    && $shift->starts_at->getTimestamp() - $session->clock_in_at->getTimestamp() <= self::EARLY_ARRIVAL_MINUTES * 60) {
                    // Clocked in before the shift starts and still on: the shift it is waiting for (lowest score, so a real overlap wins).
                    $candidates[] = [0, $shift->id, $session->id];
                }
            }
        }
        usort($candidates, fn (array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $pairs = [];
        $taken = [];
        foreach ($candidates as [, $shiftId, $sessionId]) {
            if (! isset($pairs[$shiftId]) && ! isset($taken[$sessionId])) {
                $pairs[$shiftId] = $sessionId;
                $taken[$sessionId] = true;
            }
        }

        return $pairs;
    }

    /**
     * @param  array<string, true>  $leave
     * @param  Collection<int|string, mixed>  $branches
     * @param  array<int, true>  $waiting
     * @return array<string, mixed>
     */
    private function row(int $organisationId, ?RosterShift $shift, ?ClockSession $session, CarbonImmutable $now, array $leave, Collection $branches, array $waiting): array
    {
        $zone = $shift !== null ? $shift->timezone : ($session !== null ? $session->timezone : 'UTC');
        $date = $session !== null ? $session->local_date->toDateString() : ($shift !== null ? $shift->starts_at->setTimezone($zone)->toDateString() : $now->toDateString());
        $userId = $shift !== null ? $shift->staff_user_id : ($session !== null ? $session->user_id : 0);
        $settings = $this->settings->forDate($organisationId, $date);
        $flags = [];
        if ($settings !== null) {
            $grace = $settings->graceMinutes;
            $window = $settings->forgottenWindowMinutes;
            if ($shift !== null && $session !== null) {
                $late = (int) floor(($session->clock_in_at->getTimestamp() - $shift->starts_at->getTimestamp()) / 60);
                if ($late > $grace) {
                    $flags[] = ['code' => 'late', 'label' => "Late {$late} min", 'minutes' => $late];
                }
                if ($session->clock_out_at !== null) {
                    $early = (int) floor(($shift->ends_at->getTimestamp() - $session->clock_out_at->getTimestamp()) / 60);
                    if ($early > $grace) {
                        $flags[] = ['code' => 'left_early', 'label' => "Left early {$early} min", 'minutes' => $early];
                    }
                }
                if ($session->branch_id !== $shift->branch_id) {
                    $flags[] = ['code' => 'other_branch', 'label' => 'Different branch from the roster'];
                }
            }
            if ($shift !== null && $session === null && isset($leave[$userId.'|'.$date])) {
                // A shift kept on an approved leave day (HR-2a never changes shifts) is not a missed clock-in (Suresh).
                $flags[] = ['code' => 'on_leave', 'label' => 'On approved leave'];
            } elseif ($shift !== null && $session === null && $now->greaterThan($shift->ends_at->addMinutes($grace))) {
                $flags[] = ['code' => 'no_clock_in', 'label' => 'No clock-in'];
            }
            if ($session !== null && $session->clock_out_at === null) {
                $deadline = $shift !== null ? $shift->ends_at->addMinutes($window) : $session->clock_in_at->addHours(AttendanceSupport::MAX_SESSION_HOURS);
                if ($session->status === 'missing_out' || $now->greaterThan($deadline)) {
                    $flags[] = ['code' => 'no_clock_out', 'label' => 'No clock-out'];
                }
            }
            if ($shift === null && $session !== null) {
                $flags[] = ['code' => 'not_rostered', 'label' => 'Not on the roster'];
            }
            if ($session !== null && isset($leave[$userId.'|'.$date])) {
                $flags[] = ['code' => 'on_leave', 'label' => 'On approved leave'];
            }
        }
        $worked = $session === null ? null : self::worked($session, $shift);
        $shiftBranch = $shift === null ? null : (string) ($branches[$shift->branch_id] ?? '');
        $sessionZone = $session !== null ? $session->timezone : $zone;

        return [
            'key' => ($shift !== null ? $shift->public_id : '-').':'.($session !== null ? $session->public_id : '-'),
            'userId' => $userId,
            'date' => $date,
            'dateLabel' => CarbonImmutable::parse($date)->format('D j M'),
            'sortAt' => ($shift !== null ? $shift->starts_at : ($session !== null ? $session->clock_in_at : $now))->getTimestamp(),
            'shift' => $shift === null ? null : ['label' => (RosterRules::SESSIONS[$shift->session_type] ?? 'Other').', '.AttendanceSupport::time($shift->starts_at, $shift->timezone)
                .' to '.AttendanceSupport::time($shift->ends_at, $shift->timezone), 'branch' => $shiftBranch],
            'session' => $session === null ? null : ['publicId' => $session->public_id, 'status' => $session->status, 'branch' => (string) ($branches[$session->branch_id] ?? ''),
                'branchId' => $session->branch_id, 'in' => AttendanceSupport::time($session->clock_in_at, $sessionZone), 'out' => AttendanceSupport::time($session->clock_out_at, $sessionZone),
                'inValue' => $session->clock_in_at->setTimezone($sessionZone)->format('Y-m-d\TH:i'),
                'outValue' => $session->clock_out_at?->setTimezone($sessionZone)->format('Y-m-d\TH:i')],
            'worked' => $worked === null ? null : AttendanceSupport::duration($worked),
            'workedMinutes' => $worked,
            'flags' => $flags,
            'correctionWaiting' => $session !== null && isset($waiting[$session->id]),
            'needsClockOut' => collect($flags)->contains('code', 'no_clock_out'),
        ];
    }

    /**
     * @param  Collection<int, ClockSession>  $sessions
     * @param  Collection<int, RosterShift>  $shifts
     * @return array<string, true> "userId|Y-m-d" for each approved leave day of these people (session and shift days)
     */
    private function leaveDays(int $organisationId, Collection $sessions, Collection $shifts): array
    {
        $dates = $sessions->map(fn (ClockSession $s): string => $s->local_date->toDateString())
            ->merge($shifts->map(fn (RosterShift $s): string => $s->starts_at->setTimezone($s->timezone)->toDateString()));
        if ($dates->isEmpty()) {
            return [];
        }
        $out = [];
        LeaveRequest::query()->where('organisation_id', $organisationId)->where('status', 'approved')
            ->whereIn('user_id', $sessions->pluck('user_id')->merge($shifts->pluck('staff_user_id'))->unique()->values())
            ->whereDate('start_date', '<=', $dates->max())->whereDate('end_date', '>=', $dates->min())->get()
            ->each(function (LeaveRequest $r) use (&$out): void {
                for ($d = CarbonImmutable::parse($r->start_date->toDateString()); $d->toDateString() <= $r->end_date->toDateString(); $d = $d->addDay()) {
                    $out[$r->user_id.'|'.$d->toDateString()] = true;
                }
            });

        return $out;
    }
}
