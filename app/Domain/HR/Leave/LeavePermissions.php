<?php

namespace App\Domain\HR\Leave;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-2a leave (B.E.R.N.I.S.S for the owner, 2026-11-05). Every human staff member applies for their own leave;
 * Director and HR Manager approve (never their own) and manage the clinic-entered settings. The TV account gets
 * nothing. None of these is people or access authority.
 */
final class LeavePermissions
{
    public const REQUEST_OWN = 'hr.leave.request.own';

    public const APPROVE = 'hr.leave.approve.organisation';

    public const MANAGE = 'hr.leave.manage.organisation';

    /** HR-2b: open other people's evidence files (each open is recorded). Applicants open their own with REQUEST_OWN. */
    public const EVIDENCE_VIEW = 'hr.leave.evidence.view.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::REQUEST_OWN => RosterPermissions::HUMAN_ROLES, self::APPROVE => ['director', 'hr_manager'], self::MANAGE => ['director', 'hr_manager'],
            self::EVIDENCE_VIEW => ['director', 'hr_manager']];
    }
}
