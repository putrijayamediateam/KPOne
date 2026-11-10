<?php

namespace App\Domain\HR\Messages;

use App\Domain\HR\Roster\RosterPermissions;

/**
 * HR-14 staff messages (owner, 2026-11-10: "start"). Every human staff member chooses which channels KPOne may use
 * to tell them about their own leave, roster, payslip and claims; Director and HR Manager set each channel's price,
 * monthly cap and switch, and see what was sent (never the text). The TV account gets nothing. Neither is people or
 * access authority.
 */
final class MessagePermissions
{
    public const RECEIVE_OWN = 'hr.messages.receive.own';

    public const MANAGE = 'hr.messages.manage.organisation';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::RECEIVE_OWN => RosterPermissions::HUMAN_ROLES, self::MANAGE => ['director', 'hr_manager']];
    }
}
