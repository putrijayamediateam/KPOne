<?php

namespace App\Domain\HR\Payroll;

/**
 * HR-7 payroll (HR-PLAN 14.5; TEAM.md decision 43). HR-7a grants the two setup permissions: preparers (HR Manager,
 * Director) keep pay groups, pay item types, rate settings, statutory tables and pay profiles and see salaries; the
 * Director confirms profiles and checks tables someone else entered. The payslip and export permissions come with
 * HR-7c. The TV account gets nothing, and none is people or access authority.
 */
final class PayrollPermissions
{
    public const PREPARE = 'hr.payroll.prepare.organisation';

    public const APPROVE = 'hr.payroll.approve.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::PREPARE => ['director', 'hr_manager'], self::APPROVE => ['director']];
    }
}
