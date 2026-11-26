<?php

namespace App\Domain\Clinical\TreatmentRoom\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $visit_id
 * @property int $queue_entry_id
 * @property int|null $treatment_step_id
 * @property string $procedure_code
 * @property string $outcome_code
 * @property string|null $reason_code
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $finished_at
 * @property CarbonImmutable $recorded_at
 * @property int $recorded_by_user_id
 * @property string $idempotency_key
 * @property string $payload_fingerprint
 * @property int|null $supersedes_record_id
 * @property string|null $correction_code
 */
class TreatmentRoomRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Treatment-room evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Treatment-room evidence is append-only.'));
    }

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }
}
