<?php

namespace App\Domain\Clinical\Dispensary\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * DSP-23: one Verify or Send back on a case waiting for the doctor's check. Append-only. The notes are encrypted and
 * never audited, reported or printed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $dispensary_case_id
 * @property int $dispensary_handoff_id
 * @property int $case_lock_version
 * @property int $checked_by_user_id
 * @property string $checked_as
 * @property string $outcome
 * @property string|null $reason
 * @property string|null $note_encrypted
 * @property string|null $override_reason
 * @property string|null $override_note_encrypted
 * @property CarbonImmutable $checked_at
 * @property string $retry_key
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class DispensaryDoctorCheck extends Model
{
    public const AS_DOCTOR = 'doctor';

    public const AS_SUPERVISOR = 'supervisor';

    public const OUTCOME_VERIFIED = 'verified';

    public const OUTCOME_SENT_BACK = 'sent_back';

    /** Send back reasons, in the order the picker shows them. */
    public const REASONS = ['wrong_medicine', 'wrong_quantity', 'wrong_batch', 'label_problem', 'other'];

    /** Why a CA Supervisor checks instead of the doctor (owner answer 13 = B). */
    public const OVERRIDE_REASONS = ['doctor_off_shift', 'doctor_emergency', 'other'];

    public const NOTE_MAX = 200;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary doctor checks are append-only.'));
        static::updating(fn (): never => throw new LogicException('Dispensary doctor checks are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'case_lock_version' => 'integer',
            'note_encrypted' => 'encrypted',
            'override_note_encrypted' => 'encrypted',
            'checked_at' => 'immutable_datetime',
        ];
    }
}
