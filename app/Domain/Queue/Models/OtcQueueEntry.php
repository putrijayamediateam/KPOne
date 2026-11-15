<?php

namespace App\Domain\Queue\Models;

use App\Domain\Visit\Models\Visit;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An OTC patient's place in the dispensary waiting list (B series). It is a separate record from the
 * consultation queue; it ends when the CA starts dispensing (or the visit is cancelled).
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $visit_id
 * @property Carbon $operational_date
 * @property int $queue_number
 * @property string $status
 * @property Carbon $queued_at
 * @property int $queued_by_user_id
 * @property Carbon|null $removed_at
 * @property string|null $removal_reason
 * @property int $updated_by_user_id
 * @property int $lock_version
 */
#[Guarded(['*'])]
class OtcQueueEntry extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_REMOVED = 'removed';

    public const REASON_DISPENSING = 'dispensing';

    public const REASON_CANCELLED = 'cancelled';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('OTC queue entries are retained records.'));
        static::updating(function (self $entry): void {
            foreach (['organisation_id', 'branch_id', 'visit_id', 'operational_date', 'queue_number', 'queued_at', 'queued_by_user_id'] as $field) {
                if ($entry->isDirty($field)) {
                    throw new LogicException('OTC queue entry identity and number are immutable.');
                }
            }
            if ($entry->getOriginal('status') === self::STATUS_REMOVED) {
                throw new LogicException('A removed OTC queue entry is immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'operational_date' => 'immutable_date', 'queue_number' => 'integer', 'queued_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime', 'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
