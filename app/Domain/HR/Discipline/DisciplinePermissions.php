<?php

namespace App\Domain\HR\Discipline;

/**
 * HR-10c discipline (HR-PLAN 20.5; owner, 2026-11-10). Only the Director and the HR Manager hold it, and a case is never
 * shown anywhere else: not on the staff file, the dashboard, the person's own records or any export. It is not people or
 * access authority.
 */
final class DisciplinePermissions
{
    public const MANAGE = 'hr.discipline.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::MANAGE => ['director', 'hr_manager']];
    }
}
