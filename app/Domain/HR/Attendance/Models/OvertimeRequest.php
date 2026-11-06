<?php

namespace App\Domain\HR\Attendance\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-3b: one overtime request on one closed clock session. Minutes and day type only, never money.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $user_id
 * @property int $requested_by_user_id
 * @property int $clock_session_id
 * @property int $branch_id
 * @property CarbonImmutable $local_date
 * @property int $minutes_requested
 * @property string $reason_code
 * @property string|null $note_encrypted
 * @property string $day_type
 * @property string $status
 * @property int|null $minutes_approved
 * @property int|null $overtime_setting_id
 * @property string|null $multiplier
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property int|null $reversed_by_user_id
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reverse_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property-read User $person
 * @property-read ClockSession $session
 */
#[Guarded(['*'])]
class OvertimeRequest extends Model
{
    protected $table = 'hr_overtime_requests';

    protected function casts(): array
    {
        return ['local_date' => 'immutable_date', 'decided_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime',
            'note_encrypted' => 'encrypted', 'user_id' => 'integer', 'requested_by_user_id' => 'integer', 'clock_session_id' => 'integer', 'branch_id' => 'integer',
            'minutes_requested' => 'integer', 'minutes_approved' => 'integer', 'overtime_setting_id' => 'integer', 'decided_by_user_id' => 'integer',
            'reversed_by_user_id' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<ClockSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ClockSession::class, 'clock_session_id');
    }
}
