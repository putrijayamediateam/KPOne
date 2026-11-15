<?php

namespace App\Domain\HR\Attendance\Services;

use App\Domain\HR\Leave\Services\LeaveCalendar;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * HR-3b day types from HR-2a's branch calendar: a public holiday of the session's branch, else a rest day (a weekday
 * that is not one of the branch's working days), else a normal day. A branch with no working days set up has no rest
 * days. Per-person rest days are not built (HR-PLAN 10.9).
 */
final class OvertimeDayTypes
{
    public const LABELS = ['normal' => 'Normal day', 'rest_day' => 'Rest day', 'public_holiday' => 'Public holiday'];

    public function __construct(private LeaveCalendar $calendar) {}

    /** @return array{type: string, label: string} */
    public function of(Branch $branch, CarbonImmutable $date): array
    {
        $holiday = $this->calendar->holidays($branch, $date, $date)[$date->toDateString()] ?? null;
        if ($holiday !== null) {
            return ['type' => 'public_holiday', 'label' => 'Public holiday: '.$holiday];
        }
        $working = $this->calendar->workingDays($branch);
        if ($working !== null && ! in_array($date->dayOfWeekIso, $working, true)) {
            return ['type' => 'rest_day', 'label' => self::LABELS['rest_day']];
        }

        return ['type' => 'normal', 'label' => self::LABELS['normal']];
    }
}
