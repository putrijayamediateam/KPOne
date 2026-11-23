<?php

namespace App\Domain\Clinical\TreatmentRoom;

use App\Domain\Access\PermissionCatalogue;

/**
 * MA-1a (owner decision 23 Nov 2026, "MA: approve all recommended, start MA-1a"; MA-ROLE-DESIGN sections 3.3 and 9):
 * the Medical Assistant and the CA Supervisor record treatment-room entries and call a patient to a treatment room;
 * CA, CA Supervisor and Medical Assistant read the records. The CA never records. `treatment_room.record.branch` is a
 * clinical write, granted by this explicit owner role decision (AGENTS.md). No screen or service uses these yet (MA-1b).
 */
final class TreatmentRoomPermissions
{
    public const RECORD_BRANCH = 'treatment_room.record.branch';

    public const VIEW_BRANCH = 'treatment_room.view.branch';

    /** The treatment-room announcement on the TV only; never Call In, Call next or Call Again (MA-1b wires it). */
    public const TREATMENT_CALL_BRANCH = 'queue.treatment_call.branch';

    /** @return array<string, list<string>> */
    public static function grants(): array
    {
        return [
            self::RECORD_BRANCH => [PermissionCatalogue::MEDICAL_ASSISTANT_ROLE, 'ca_supervisor'],
            self::TREATMENT_CALL_BRANCH => [PermissionCatalogue::MEDICAL_ASSISTANT_ROLE, 'ca_supervisor'],
            self::VIEW_BRANCH => ['ca', 'ca_supervisor', PermissionCatalogue::MEDICAL_ASSISTANT_ROLE],
        ];
    }
}
