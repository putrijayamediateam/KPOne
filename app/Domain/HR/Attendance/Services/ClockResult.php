<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Roster\Models\RosterShift;

/**
 * HR-PHOTO confirmation screen: the press's branch-local time and one chip, worked out with the same matching and the
 * same grace minutes as Review's "Late N min" and "Left early N min", so the two always agree (owner: "grace yes").
 * Before HR saves a settings version, grace counts as 0 minutes.
 */
final class ClockResult
{
    public function __construct(private AttendanceDays $days, private AttendanceSettingsService $settings) {}

    /** @return array{kind: string, title: string, time: string, branch: string, chip: array{code: string, label: string}} */
    public function for(ClockSession $session, string $kind): array
    {
        $at = $kind === 'out' ? ($session->clock_out_at ?? $session->clock_in_at) : $session->clock_in_at;
        // A closed session is matched as Review matches it. At the clock-in instant an open session has no overlap yet, so
        // it takes the approved shift it falls in (from the early-arrival window to the shift's end), as Review will.
        $shift = $kind === 'out' ? $this->days->matchedShift($session) : $this->days->shifts($session->organisation_id, [$session->user_id],
            $session->local_date->subDay(), $session->local_date)
            ->filter(fn (RosterShift $s): bool => $at->greaterThanOrEqualTo($s->starts_at->subMinutes(AttendanceDays::EARLY_ARRIVAL_MINUTES)) && $at->lessThan($s->ends_at))
            ->sortBy(fn (RosterShift $s): int => abs($s->starts_at->getTimestamp() - $at->getTimestamp()))->first();
        $grace = $this->settings->forDate($session->organisation_id, $session->local_date)->graceMinutes ?? 0;
        if ($shift === null) {
            $chip = ['code' => 'no_shift', 'label' => 'No shift set'];
        } else {
            $minutes = $kind === 'out'
                ? (int) floor(($shift->ends_at->getTimestamp() - $at->getTimestamp()) / 60)
                : (int) floor(($at->getTimestamp() - $shift->starts_at->getTimestamp()) / 60);
            $chip = $minutes > $grace
                ? ['code' => $kind === 'out' ? 'left_early' : 'late', 'label' => ($kind === 'out' ? 'Left early ' : 'Late ')."{$minutes} min"]
                : ['code' => 'on_time', 'label' => 'On time'];
        }

        return ['kind' => $kind, 'title' => $kind === 'out' ? 'Clocked out' : 'Clocked in', 'time' => (string) AttendanceSupport::time($at, $session->timezone),
            'branch' => (string) $session->branch()->value('name'), 'chip' => $chip];
    }
}
