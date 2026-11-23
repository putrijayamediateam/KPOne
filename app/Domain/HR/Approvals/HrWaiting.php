<?php

namespace App\Domain\HR\Approvals;

use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\OvertimeRequest;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * APPR-1d: the one "waiting for this person" rule per HR approval kind. The owning page services and the Approvals inbox
 * sources both read these, so the two cannot drift. Each excludes the people the service bars: the subject and, where
 * there is one, the person who entered it.
 */
final class HrWaiting
{
    /**
     * Pending leave requests, not the person's own.
     *
     * @return Builder<LeaveRequest>
     */
    public static function leave(User $actor): Builder
    {
        return LeaveRequest::query()->where('organisation_id', $actor->organisation_id)->where('status', 'pending')
            ->where('user_id', '<>', $actor->id);
    }

    /**
     * Pending attendance corrections, neither about the person nor proposed by them.
     *
     * @return Builder<AttendanceCorrection>
     */
    public static function corrections(User $actor): Builder
    {
        return AttendanceCorrection::query()->where('organisation_id', $actor->organisation_id)->where('status', 'pending')
            ->where('user_id', '<>', $actor->id)->where('proposed_by_user_id', '<>', $actor->id);
    }

    /**
     * Pending overtime requests, neither for the person nor asked for by them.
     *
     * @return Builder<OvertimeRequest>
     */
    public static function overtime(User $actor): Builder
    {
        return OvertimeRequest::query()->where('organisation_id', $actor->organisation_id)->where('status', 'pending')
            ->where('user_id', '<>', $actor->id)->where('requested_by_user_id', '<>', $actor->id);
    }

    /** Every staff claim waiting for approval: the Approvals tab lists them all, each with why the viewer may not decide it. */
    public static function claimsListed(int $organisationId): QueryBuilder
    {
        return DB::table('hr_staff_claims')->where('organisation_id', $organisationId)->where('status', 'waiting');
    }

    /** Staff claims waiting for approval, not the person's own (the rank rules are applied by ClaimService::deciderRefusal). */
    public static function claims(User $actor): QueryBuilder
    {
        return self::claimsListed($actor->organisation_id)->where('claimant_user_id', '<>', $actor->id);
    }
}
