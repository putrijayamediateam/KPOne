<?php

namespace App\Domain\HR\Appraisals;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-10b appraisal (HR-PLAN 20.4; owner, 2026-11-10). Director and HR Manager enter the criteria, open cycles and rate
 * staff; a Director signs an appraisal off. Every human staff member reads their own sent appraisals and adds a comment.
 * The TV account gets nothing. Neither is people or access authority.
 */
final class AppraisalPermissions
{
    public const MANAGE = 'hr.appraisal.manage.organisation';

    public const VIEW_OWN = 'hr.appraisal.view.own';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::MANAGE => ['director', 'hr_manager'], self::VIEW_OWN => RosterPermissions::HUMAN_ROLES];
    }
}
