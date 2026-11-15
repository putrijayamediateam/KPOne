<?php

namespace App\Domain\HR\Credentials;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-5a credential register (owner, 2026-11-06: "approve the HR-5 note with the defaults"). Every human staff member
 * sees their own credentials and sends their own renewals; Director and HR Manager keep the register, the credential
 * types, enter records for others and accept or reject them. The TV account gets nothing. Neither is people or access
 * authority, and neither touches DOC-02a's clinician credentials.
 */
final class CredentialPermissions
{
    public const VIEW_OWN = 'hr.credentials.view.own';

    public const MANAGE = 'hr.credentials.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::VIEW_OWN => RosterPermissions::HUMAN_ROLES, self::MANAGE => ['director', 'hr_manager']];
    }
}
