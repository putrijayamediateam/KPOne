<?php

namespace App\Domain\Clinical\Dispensary\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * DSP-7: one recorded return of dispensed medicine on one line of a completed case. Append-only; recording moves no
 * stock. The optional "Other" note is encrypted and never audited, reported or printed.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $dispensary_case_id
 * @property int $dispensary_item_id
 * @property string $quantity Exact decimal:3, in the line's unit.
 * @property string $reason
 * @property string|null $note_encrypted
 * @property string $condition
 * @property int $recorded_by_user_id
 * @property CarbonImmutable $recorded_at
 * @property string $retry_key
 * @property string $payload_hash
 */
#[Guarded(['*'])]
class DispensaryReturn extends Model
{
    public const REASONS = ['not_used', 'wrong_medicine_or_strength', 'patient_reaction', 'dispensing_error', 'other'];

    public const CONDITIONS = ['sealed_in_date', 'opened_or_damaged', 'cannot_tell'];

    public const NOTE_MAX = 200;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Dispensary returns are append-only.'));
        static::updating(fn (): never => throw new LogicException('Dispensary returns are append-only.'));
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'note_encrypted' => 'encrypted', 'recorded_at' => 'immutable_datetime'];
    }
}
