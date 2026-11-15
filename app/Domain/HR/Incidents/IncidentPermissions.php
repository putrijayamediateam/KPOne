<?php

namespace App\Domain\HR\Incidents;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-10d incident reports (HR-PLAN 20.6; owner, 2026-11-10). Every human staff member reports; the Director and the HR
 * Manager handle reports. The TV account gets nothing. Neither is people or access authority.
 */
final class IncidentPermissions
{
    public const REPORT = 'hr.incidents.report.own';

    public const MANAGE = 'hr.incidents.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::REPORT => RosterPermissions::HUMAN_ROLES, self::MANAGE => ['director', 'hr_manager']];
    }
}
