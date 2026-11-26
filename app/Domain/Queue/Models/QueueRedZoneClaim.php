<?php

namespace App\Domain\Queue\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Q-16b: an immutable record that a doctor took a red-zone Waiting patient. Ids and time only.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $queue_entry_id
 * @property int $visit_id
 * @property int $claimed_by_user_id
 * @property int|null $previous_doctor_user_id
 * @property Carbon $claimed_at
 */
#[Guarded(['*'])]
class QueueRedZoneClaim extends Model
{
    protected $table = 'queue_red_zone_claims';

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Red-zone claims are append-only.'));
        static::deleting(fn () => throw new LogicException('Red-zone claims are append-only.'));
    }
}
