<?php

namespace App\Domain\Clinical\Triage;

use App\Domain\Access\PermissionCatalogue;

/**
 * TRI-1 (owner decisions 19 Nov 2026, TRI-1-DESIGN section 9): CA, CA Supervisor (and, from MA-1a, the Medical
 * Assistant) record triage at their branch;
 * the Director and the HR Manager enter and approve triage guideline versions (never patient readings).
 */
final class TriagePermissions
{
    public const RECORD_BRANCH = 'visits.triage.record.branch';

    public const GUIDELINE_MANAGE_ORGANISATION = 'triage.guideline.manage.organisation';

    /** TRI-1e (owner, 24 Nov 2026, "ya semua"): the patient's own doctor reads the visit's triage record, view only. */
    public const VIEW_OWN = 'visits.triage.view.own';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [
            // MA-1a (owner decision 23 Nov 2026, MA-ROLE-DESIGN question 2): the Medical Assistant records triage too.
            self::RECORD_BRANCH => ['ca', 'ca_supervisor', PermissionCatalogue::MEDICAL_ASSISTANT_ROLE],
            self::GUIDELINE_MANAGE_ORGANISATION => ['director', 'hr_manager'],
            self::VIEW_OWN => ['resident_doctor'],
        ];
    }
}
