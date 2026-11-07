<?php

namespace App\Domain\HR\Payroll;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-7 payroll (HR-PLAN 14.5; TEAM.md decision 43). HR-7a grants the two setup permissions: preparers (HR Manager,
 * Director) keep pay groups, pay item types, rate settings, statutory tables and pay profiles and see salaries; the
 * Director confirms profiles and checks tables someone else entered. HR-7c (HR-PLAN section 17; B.E.R.N.I.S.S for the
 * owner, 2026-11-07): the Director alone exports from locked runs in v1 (the Finance Officer gets no payroll access),
 * and every human role sees their own payslips. The TV account gets nothing, and none is people or access authority.
 */
final class PayrollPermissions
{
    public const PREPARE = 'hr.payroll.prepare.organisation';

    public const APPROVE = 'hr.payroll.approve.organisation';

    public const EXPORT = 'hr.payroll.export.organisation';

    public const PAYSLIPS_OWN = 'hr.payslips.view.own';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::PREPARE => ['director', 'hr_manager'], self::APPROVE => ['director'], self::EXPORT => ['director'],
            self::PAYSLIPS_OWN => RosterPermissions::HUMAN_ROLES];
    }
}
