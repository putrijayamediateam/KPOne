<?php

namespace App\Domain\HR\Leave\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $user_id
 * @property int $leave_type_id
 * @property int $branch_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $start_part full|am|pm
 * @property string $end_part full|am
 * @property Carbon $slot_start
 * @property Carbon $slot_end
 * @property int $year
 * @property int $halves
 * @property string $status pending|approved|rejected|cancelled
 * @property string|null $reason_encrypted
 * @property string|null $evidence_reference_encrypted
 * @property bool $late
 * @property bool $evidence_seen
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 * @property int|null $cancelled_by_user_id
 * @property int $lock_version
 * @property-read LeaveType $type
 * @property-read User $person
 */
#[Guarded(['*'])]
class LeaveRequest extends Model
{
    protected $table = 'hr_leave_requests';

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'slot_start' => 'datetime', 'slot_end' => 'datetime', 'decided_at' => 'datetime',
            'reason_encrypted' => 'encrypted', 'evidence_reference_encrypted' => 'encrypted', 'late' => 'boolean', 'evidence_seen' => 'boolean',
            'user_id' => 'integer', 'decided_by_user_id' => 'integer', 'cancelled_by_user_id' => 'integer', 'halves' => 'integer', 'year' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
