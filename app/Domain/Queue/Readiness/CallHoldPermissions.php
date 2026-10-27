<?php

namespace App\Domain\Queue\Readiness;

final class CallHoldPermissions
{
    public const SET = 'queue.call_hold.set.branch';

    public const CLEAR = 'queue.call_hold.clear.branch';

    public const OVERRIDE = 'queue.call_hold.override.own';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::SET => ['ca', 'ca_supervisor'], self::CLEAR => ['ca', 'ca_supervisor'], self::OVERRIDE => ['resident_doctor']];
    }
}
