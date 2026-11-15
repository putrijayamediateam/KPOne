<?php

namespace App\Domain\HR\Leave\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An opening balance or an adjustment waiting for, or decided by, a second manager.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $user_id
 * @property int $leave_type_id
 * @property int $year
 * @property int $halves
 * @property string|null $reason_code
 * @property string|null $note_encrypted
 * @property string $status pending|confirmed|rejected
 * @property int $entered_by_user_id
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property int $lock_version
 * @property-read LeaveType $type
 * @property-read User $person
 */
#[Guarded(['*'])]
abstract class LeaveBalanceEntry extends Model
{
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

    protected function casts(): array
    {
        return ['note_encrypted' => 'encrypted', 'decided_at' => 'datetime', 'halves' => 'integer', 'year' => 'integer', 'lock_version' => 'integer',
            'user_id' => 'integer', 'entered_by_user_id' => 'integer', 'decided_by_user_id' => 'integer'];
    }
}
