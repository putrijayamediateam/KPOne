<?php

namespace App\Domain\HR\Applications;

/**
 * HR-13 job applications (owner, 2026-11-07: "start the HR-13 build"). Director and HR Manager keep the open positions,
 * read applications and move them through their steps, open CVs (each open recorded first) and set the retention.
 * The public form needs no permission and is off unless HR_APPLICATIONS_ENABLED is true. Not people or access authority.
 */
final class ApplicationPermissions
{
    public const MANAGE = 'hr.applications.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::MANAGE => ['director', 'hr_manager']];
    }
}
