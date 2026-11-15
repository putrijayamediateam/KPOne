<?php

namespace App\Domain\HR\Memos;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-8 announcements and memos (owner, via B.E.R.N.I.S.S, 2026-11-10). Director and HR Manager publish, replace-by-new and
 * withdraw memos and see who has not read them; every human staff member reads and acknowledges the memos they are
 * expected to read. The TV account gets nothing. Neither is people or access authority.
 */
final class MemoPermissions
{
    public const READ_OWN = 'hr.memos.read.own';

    public const PUBLISH = 'hr.memos.publish.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::READ_OWN => RosterPermissions::HUMAN_ROLES, self::PUBLISH => ['director', 'hr_manager']];
    }
}
