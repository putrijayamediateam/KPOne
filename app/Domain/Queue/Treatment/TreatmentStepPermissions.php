<?php

namespace App\Domain\Queue\Treatment;

/** Q-2 (owner decision 2026-11-05): CA and CA Supervisor at their branch; the attending Resident Doctor on their own patient. */
final class TreatmentStepPermissions
{
    public const SET_BRANCH = 'queue.treatment_progress.set.branch';

    public const SET_OWN = 'queue.treatment_progress.set.own';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [self::SET_BRANCH => ['ca', 'ca_supervisor'], self::SET_OWN => ['resident_doctor']];
    }
}
