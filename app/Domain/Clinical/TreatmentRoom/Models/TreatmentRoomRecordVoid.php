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
 * @property int $target_record_id
 * @property string $reason_code
 * @property CarbonImmutable $voided_at
 * @property int $voided_by_user_id
 * @property string $idempotency_key
 * @property string $payload_fingerprint
 */
class TreatmentRoomRecordVoid extends Model
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
        return ['voided_at' => 'immutable_datetime'];
    }
}
