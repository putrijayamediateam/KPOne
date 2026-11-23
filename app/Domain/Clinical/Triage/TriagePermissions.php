<?php

namespace App\Domain\Clinical\Triage;

/**
 * TRI-1 (owner decisions 19 Nov 2026, TRI-1-DESIGN section 9): CA and CA Supervisor record triage at their branch;
 * the Director and the HR Manager enter and approve triage guideline versions (never patient readings).
 */
final class TriagePermissions
{
    public const RECORD_BRANCH = 'visits.triage.record.branch';

    public const GUIDELINE_MANAGE_ORGANISATION = 'triage.guideline.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [
            self::RECORD_BRANCH => ['ca', 'ca_supervisor'],
            self::GUIDELINE_MANAGE_ORGANISATION => ['director', 'hr_manager'],
        ];
    }
}
