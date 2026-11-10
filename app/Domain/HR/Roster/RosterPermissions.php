<?php

namespace App\Domain\HR\Roster;

use App\Domain\Access\PermissionCatalogue;

/**
 * HR-1 roster planner (B.E.R.N.I.S.S for the owner, 2026-11-05). Director and HR Manager plan; the Director approves,
 * never the person who sent the week; every human staff member sees their own approved shifts. The TV account gets
 * nothing. None of these is people or access authority.
 */
final class RosterPermissions
{
    public const MANAGE = 'hr.roster.manage.organisation';

    public const APPROVE = 'hr.roster.approve.organisation';

    public const VIEW_OWN = 'hr.roster.view.own';

    /** Every human role in the catalogue; the shared waiting-room TV account is deliberately absent. */
    public const HUMAN_ROLES = ['director', 'resident_doctor', 'ca', 'ca_supervisor', 'panel_officer', 'finance_officer',
        'administrator', 'business_development', 'marketing', 'hr_manager', 'technical_admin'];

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        // HR-15a: a locum with a sign-in sees their own approved shifts too, and nothing else from HR.
        return [self::MANAGE => ['director', 'hr_manager'], self::APPROVE => ['director'], self::VIEW_OWN => [...self::HUMAN_ROLES, PermissionCatalogue::LOCUM_ROLE]];
    }
}
