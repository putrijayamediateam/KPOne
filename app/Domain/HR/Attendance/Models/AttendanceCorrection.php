<?php

namespace App\Domain\HR\Attendance\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $user_id
 * @property int $branch_id
 * @property int|null $clock_session_id
 * @property int|null $created_session_id
 * @property string $kind
 * @property CarbonImmutable|null $proposed_in_at
 * @property CarbonImmutable|null $proposed_out_at
 * @property string $reason_code
 * @property string|null $note_encrypted
 * @property string $status
 * @property int $proposed_by_user_id
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property-read User $person
 * @property-read User $proposer
 * @property-read ClockSession|null $session
 */
#[Guarded(['*'])]
class AttendanceCorrection extends Model
{
    protected $table = 'hr_attendance_corrections';

    protected function casts(): array
    {
        return ['proposed_in_at' => 'immutable_datetime', 'proposed_out_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'note_encrypted' => 'encrypted', 'user_id' => 'integer', 'branch_id' => 'integer',
            'clock_session_id' => 'integer', 'proposed_by_user_id' => 'integer', 'decided_by_user_id' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    /** @return BelongsTo<ClockSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ClockSession::class, 'clock_session_id');
    }
}
