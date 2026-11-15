<?php

namespace App\Domain\HR\Attendance;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-3a attendance (owner, 2026-11-06). Every human staff member clocks in and out on their own account and proposes
 * corrections to their own times; Director and HR Manager review, decide corrections (never their own, never one they
 * proposed) and manage the clinic-entered settings. The TV account gets nothing. None of these is people or access
 * authority. (Named for the clock to stay apart from the clinical attendance confirmation, DOC-05.)
 */
final class ClockPermissions
{
    public const CLOCK_OWN = 'hr.attendance.clock.own';

    public const REVIEW = 'hr.attendance.review.organisation';

    /** HR-3b: decide and reverse overtime requests (never one's own, never one they asked for). */
    public const OVERTIME_APPROVE = 'hr.overtime.approve.organisation';

    /** HR-16a: turn the location check at clock-in on or off for a branch (Director only, HR-PLAN 22.3). */
    public const LOCATION_MANAGE = 'hr.attendance.location.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::CLOCK_OWN => RosterPermissions::HUMAN_ROLES, self::REVIEW => ['director', 'hr_manager'], self::OVERTIME_APPROVE => ['director', 'hr_manager'],
            self::LOCATION_MANAGE => ['director']];
    }
}
