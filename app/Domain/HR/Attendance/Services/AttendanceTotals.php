<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Models\OvertimeRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * HR-3b weekly hours and period summaries, worked out when a page opens and never stored. Both reuse HR-3a's day rows,
 * so a summary always adds up to what Review shows for the same days. Weekly hours count closed sessions' worked time,
 * an open session up to now ("so far"), and nothing for a session missing its clock-out until it is corrected.
 */
final class AttendanceTotals
{
    public function __construct(private AttendanceDays $days, private OvertimeSettingsService $settings) {}

    /**
     * Worked minutes per person for the branch-local dates [$from, $to], at every branch.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{minutes: int, soFar: bool}>
     */
    public function worked(int $organisationId, array $userIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = array_fill_keys($userIds, ['minutes' => 0, 'soFar' => false]);
        if ($userIds === []) {
            return $out;
        }
        $sessions = $this->sessions($userIds, $from, $to);
        $rows = $this->days->rows($organisationId, $sessions, $this->days->shifts($organisationId, $userIds, $from, $to));
        $now = CarbonImmutable::now('UTC');
        foreach ($rows as $row) {
            if ($row['session'] === null || ! isset($out[$row['userId']])) {
                continue;
            }
            if ($row['workedMinutes'] !== null) {
                $out[$row['userId']]['minutes'] += (int) $row['workedMinutes'];
            }
        }
        foreach ($sessions->where('status', 'open') as $open) {
            $until = $now->min($open->clock_in_at->addHours(AttendanceSupport::MAX_SESSION_HOURS));
            $out[$open->user_id]['minutes'] += max(0, (int) $open->clock_in_at->diffInMinutes($until));
            $out[$open->user_id]['soFar'] = true;
        }

        return $out;
    }

    /**
     * The weekly hours line and chip for each person for the Monday-to-Sunday week holding $date.
     *
     * @param  list<int>  $userIds
     * @return array<int, array{minutes: int, label: string, level: string, chip: string|null}>
     */
    public function week(int $organisationId, array $userIds, CarbonImmutable $date): array
    {
        $start = $date->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addDays(6);
        $today = CarbonImmutable::now($date->getTimezone())->startOfDay();
        $version = $this->settings->forDate($organisationId, $end->min($today));
        $out = [];
        foreach ($this->worked($organisationId, $userIds, $start, $end) as $userId => $total) {
            $hours = AttendanceSupport::duration($total['minutes']);
            $level = 'none';
            $chip = null;
            if ($version !== null && $total['minutes'] > $version->limitHours * 60) {
                $level = 'over';
                $chip = "Over {$version->limitHours} h this week: {$hours}";
            } elseif ($version !== null && $total['minutes'] >= $version->nearingHours * 60) {
                $level = 'nearing';
                $chip = "Nearing {$version->limitHours} h this week";
            }
            $out[$userId] = ['minutes' => $total['minutes'], 'label' => $hours.($total['soFar'] ? ' so far' : ''), 'level' => $level, 'chip' => $chip];
        }

        return $out;
    }

    /**
     * Per-person summary for the branch-local dates [$from, $to]: rostered and worked time, flag counts and approved
     * overtime by day type, across every branch. $branchId marks people with time at another branch.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    public function summary(int $organisationId, array $userIds, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null): array
    {
        $blank = ['rostered' => 0, 'worked' => 0, 'late' => 0, 'lateMinutes' => 0, 'leftEarly' => 0, 'noClockIn' => 0, 'noClockOut' => 0,
            'overtime' => ['normal' => 0, 'rest_day' => 0, 'public_holiday' => 0], 'otherBranch' => false];
        $out = array_fill_keys($userIds, $blank);
        if ($userIds === []) {
            return [];
        }
        $rows = $this->days->rows($organisationId, $this->sessions($userIds, $from, $to), $this->days->shifts($organisationId, $userIds, $from, $to));
        foreach ($rows as $row) {
            $id = $row['userId'];
            if (! isset($out[$id])) {
                continue;
            }
            $out[$id]['rostered'] += (int) ($row['rosteredMinutes'] ?? 0);
            $out[$id]['worked'] += (int) ($row['workedMinutes'] ?? 0);
            foreach ($row['flags'] as $flag) {
                match ($flag['code']) {
                    'late' => [$out[$id]['late']++, $out[$id]['lateMinutes'] += (int) $flag['minutes']],
                    'left_early' => $out[$id]['leftEarly']++,
                    'no_clock_in' => $out[$id]['noClockIn']++,
                    'no_clock_out' => $out[$id]['noClockOut']++,
                    default => null,
                };
            }
            if ($branchId !== null && (($row['session'] !== null && $row['session']['branchId'] !== $branchId) || ($row['shift'] !== null && $row['shift']['branchId'] !== $branchId))) {
                $out[$id]['otherBranch'] = true;
            }
        }
        OvertimeRequest::query()->where('organisation_id', $organisationId)->whereIn('user_id', $userIds)->where('status', 'approved')
            ->whereDate('local_date', '>=', $from->toDateString())->whereDate('local_date', '<=', $to->toDateString())->get()
            ->each(function (OvertimeRequest $r) use (&$out): void {
                $out[$r->user_id]['overtime'][$r->day_type] += (int) $r->minutes_approved;
            });

        return $out;
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, ClockSession>
     */
    private function sessions(array $userIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return ClockSession::query()->whereIn('user_id', $userIds)->whereDate('local_date', '>=', $from->toDateString())
            ->whereDate('local_date', '<=', $to->toDateString())->orderBy('clock_in_at')->get();
    }
}
