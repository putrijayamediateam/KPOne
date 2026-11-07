<?php

namespace App\Domain\HR\Records;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-4a staff files (owner, 2026-11-06: "approve the HR-4 note with the defaults"). Director and HR Manager open and
 * manage staff files and see them masked, with an audited Show; every human staff member sees their own file and
 * changes only their address, personal phone and emergency contact, asking for a correction for the rest. The TV
 * account gets nothing. None of these is people or access authority: HR never changes an account.
 */
final class RecordPermissions
{
    public const VIEW_ORGANISATION = 'hr.records.view.organisation';

    public const MANAGE = 'hr.records.manage.organisation';

    public const VIEW_OWN = 'hr.records.view.own';

    /** HR-4b: open anyone's documents, each view audited (staff open their own with VIEW_OWN). */
    public const DOCUMENTS_VIEW = 'hr.documents.view.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::VIEW_ORGANISATION => ['director', 'hr_manager'], self::MANAGE => ['director', 'hr_manager'], self::VIEW_OWN => RosterPermissions::HUMAN_ROLES,
            self::DOCUMENTS_VIEW => ['director', 'hr_manager']];
    }
}
