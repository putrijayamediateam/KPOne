<?php

namespace App\Domain\Queue\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An immutable record that a queue number was called to a room, as shown on the branch TV.
 * It carries no patient identity: only the number, the room and the time.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $queue_entry_id
 * @property string $service
 * @property bool $is_recall
 * @property int|null $branch_room_id
 * @property string|null $room_name
 * @property int $queue_number
 * @property Carbon $operational_date
 * @property int $called_by_user_id
 * @property Carbon $called_at
 */
#[Guarded(['*'])]
class QueueCall extends Model
{
    public const SERVICE_CONSULTATION = 'consultation';

    public const SERVICE_DISPENSARY = 'dispensary';

    public const SERVICE_TREATMENT = 'treatment';

    /** How soon the same patient may be called again on the TV. */
    public const RECALL_COOLDOWN_SECONDS = 20;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Queue calls are immutable.'));
        static::deleting(fn () => throw new LogicException('Queue calls are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'is_recall' => 'boolean',
            'queue_number' => 'integer',
            'operational_date' => 'immutable_date',
            'called_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
