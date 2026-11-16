<?php

namespace Tests\Unit;

use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Services\AttendanceDays;
use App\Domain\HR\Roster\Models\RosterShift;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HR month dry run, S1: a late or early session keeps no more of the shift's break than the part of the shift it covered.
 * The roster stores only the break's length, so the break comes off in proportion to the overlap with the shift.
 */
final class AttendanceBreakShareTest extends TestCase
{
    private function shift(string $start, string $end, int $break): RosterShift
    {
        return (new RosterShift)->forceFill(['starts_at' => "2027-03-08 {$start}:00", 'ends_at' => "2027-03-08 {$end}:00", 'break_minutes' => $break]);
    }

    private function clockSession(string $in, ?string $out): ClockSession
    {
        return (new ClockSession)->forceFill(['clock_in_at' => "2027-03-08 {$in}:00", 'clock_out_at' => $out === null ? null : "2027-03-08 {$out}:00"]);
    }

    /** @return array<string, array{string, string, int}> */
    public static function cases(): array
    {
        // Shift 09:00 to 17:00 (480 min) with a 60 min break.
        return [
            'whole shift, a little early and late: the whole break, as before' => ['08:55', '17:02', 487 - 60],
            'exactly the shift' => ['09:00', '17:00', 420],
            '30 min late: 450 of 480 min covered, so 56 min of break' => ['09:30', '17:02', 452 - 56],
            'left 2 h early: 360 of 480 min covered, so 45 min of break' => ['09:00', '15:00', 360 - 45],
            'early in and 3 h 20 min early out: 280 of 480 min covered, so 35 min of break' => ['08:12', '13:40', 328 - 35],
            'entirely outside the shift: no break' => ['17:30', '19:00', 90],
        ];
    }

    #[DataProvider('cases')]
    public function test_worked_minutes_take_off_only_the_covered_share_of_the_break(string $in, string $out, int $expected): void
    {
        $this->assertSame($expected, AttendanceDays::worked($this->clockSession($in, $out), $this->shift('09:00', '17:00', 60)));
    }

    public function test_arriving_later_or_leaving_earlier_never_adds_worked_time(): void
    {
        $shift = $this->shift('09:00', '17:00', 60);
        $previous = PHP_INT_MAX;
        foreach (['08:30', '09:00', '09:01', '09:20', '10:00', '12:00', '16:59'] as $in) {
            $worked = AttendanceDays::worked($this->clockSession($in, '17:00'), $shift);
            $this->assertLessThanOrEqual($previous, $worked, "clock-in at {$in}");
            $previous = $worked;
        }
        $previous = PHP_INT_MAX;
        foreach (['17:30', '17:00', '16:59', '16:00', '13:00', '09:01'] as $out) {
            $worked = AttendanceDays::worked($this->clockSession('09:00', $out), $shift);
            $this->assertLessThanOrEqual($previous, $worked, "clock-out at {$out}");
            $previous = $worked;
        }
    }

    public function test_two_sessions_in_one_shift_each_keep_their_own_share_and_never_take_the_break_twice(): void
    {
        // Suresh M1, the owner's (a): out for lunch from 12:00 to 13:00 on a 09:00 to 17:00 shift with a 60 min break.
        // 180 of 480 min covered gives 22.5, rounded to 23; 240 of 480 gives 30. Together 53 min, never more than 60.
        $shift = $this->shift('09:00', '17:00', 60);
        $morning = $this->clockSession('09:00', '12:00');
        $afternoon = $this->clockSession('13:00', '17:00');
        $this->assertSame(23, AttendanceDays::breakShare($morning, $shift));
        $this->assertSame(30, AttendanceDays::breakShare($afternoon, $shift));
        $this->assertSame(157 + 210, AttendanceDays::worked($morning, $shift) + AttendanceDays::worked($afternoon, $shift));
        $this->assertLessThanOrEqual(60, AttendanceDays::breakShare($morning, $shift) + AttendanceDays::breakShare($afternoon, $shift));
    }

    public function test_no_shift_no_break_and_an_open_session_has_no_worked_time(): void
    {
        $this->assertSame(240, AttendanceDays::worked($this->clockSession('10:00', '14:00'), null));
        $this->assertNull(AttendanceDays::worked($this->clockSession('10:00', null), $this->shift('09:00', '17:00', 60)));
        $this->assertSame(0, AttendanceDays::breakShare($this->clockSession('09:00', '17:00'), $this->shift('09:00', '17:00', 0)));
    }
}
