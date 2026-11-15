<?php

namespace App\Domain\HR\Letters;

/**
 * HR-10a letters (HR-PLAN 20.3; owner, 2026-11-10). Director and HR Manager manage letter templates and issue, replace,
 * void and mark letters given. Every human staff member opens their own issued letters with the HR-4a own-file
 * permission (hr.records.view.own). The TV account gets nothing. Not people or access authority.
 */
final class LetterPermissions
{
    public const ISSUE = 'hr.letters.issue.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::ISSUE => ['director', 'hr_manager']];
    }
}
