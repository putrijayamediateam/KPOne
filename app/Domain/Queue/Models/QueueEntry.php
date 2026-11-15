<?php

namespace App\Domain\Queue\Models;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Database\Factories\QueueEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $visit_id
 * @property Carbon $operational_date
 * @property int $queue_number
 * @property string $status
 * @property Carbon $queued_at
 * @property int $queued_by_user_id
 * @property Carbon|null $called_at
 * @property int|null $called_by_user_id
 * @property Carbon|null $removed_at
 * @property string|null $removal_reason
 * @property Carbon|null $returned_from_dispensary_at
 * @property int $updated_by_user_id
 * @property int $lock_version
 * @property-read Visit $visit
 * @property-read Branch $branch
 */
#[Guarded(['*'])]
class QueueEntry extends Model
{
    /** @use HasFactory<QueueEntryFactory> */
    use HasFactory;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_SERVING = 'serving';

    public const STATUS_REMOVED = 'removed';

    protected static function newFactory(): QueueEntryFactory
    {
        return QueueEntryFactory::new();
    }

    protected function casts(): array
    {
        return [
            'operational_date' => 'immutable_date',
            'queue_number' => 'integer',
            'queued_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'returned_from_dispensary_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function queuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'queued_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function calledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
