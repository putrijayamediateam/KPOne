<?php

namespace App\Domain\HR\Claims;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-6 staff claims (owner, 2026-11-06: "approve the HR-6 note with the defaults"). Every human staff member sends and
 * withdraws their own claims; Director and HR Manager approve or reject and keep the settings; Finance Officer and
 * Director record that a claim was paid outside KPOne. The TV account gets nothing. None is people or access authority,
 * and none is a Panel or billing permission.
 */
final class ClaimPermissions
{
    public const SUBMIT_OWN = 'hr.claims.submit.own';

    public const APPROVE = 'hr.claims.approve.organisation';

    public const PAY = 'hr.claims.pay.organisation';

    public const MANAGE = 'hr.claims.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::SUBMIT_OWN => RosterPermissions::HUMAN_ROLES, self::APPROVE => ['director', 'hr_manager'],
            self::PAY => ['finance_officer', 'director'], self::MANAGE => ['director', 'hr_manager']];
    }
}
