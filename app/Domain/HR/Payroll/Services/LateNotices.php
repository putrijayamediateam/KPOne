<?php

namespace App\Domain\HR\Payroll\Services;

use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;

/**
 * G2b: the words added to a notice when a leave or attendance decision lands in a month whose payroll is already
 * locked, so HR knows the change is paid or taken only on an adjustment run. Reads only; null when nothing to say.
 */
final class LateNotices
{
    public function __construct(private LateAfterLock $late) {}

    public function leaveApproved(LeaveRequest $request): ?string
    {
        if ($request->status !== 'approved' || LeaveType::query()->whereKey($request->leave_type_id)->value('is_paid') !== false) {
            return null;
        }
        $month = $this->late->lockedMonths((int) $request->organisation_id,
            [(int) $request->id => ['userId' => (int) $request->user_id, 'date' => $request->start_date->toDateString()]], 'monthly')[(int) $request->id] ?? null;

        return $month === null ? null : "{$month}'s payroll is already locked: add it on an adjustment run.";
    }

    public function leaveCancelled(LeaveRequest $request): ?string
    {
        if ($request->status !== 'cancelled') {
            return null;
        }
        $month = $this->late->takenOffPay((int) $request->organisation_id, [(int) $request->id])[(int) $request->id] ?? null;

        return $month === null ? null : "{$month}'s payroll is already locked and took this leave off pay: add it back on an adjustment run.";
    }

    public function correctionApproved(AttendanceCorrection $correction): ?string
    {
        if ($correction->status !== 'approved') {
            return null;
        }
        $date = ClockSession::query()->whereKey($correction->clock_session_id ?? $correction->created_session_id)->value('local_date');
        if ($date === null) {
            return null;
        }
        $month = $this->late->lockedMonths((int) $correction->organisation_id,
            [(int) $correction->id => ['userId' => (int) $correction->user_id, 'date' => substr((string) $date, 0, 10)]], 'hourly')[(int) $correction->id] ?? null;

        return $month === null ? null : "{$month}'s payroll is already locked: add the hours on an adjustment run.";
    }
}
