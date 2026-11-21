<?php

namespace App\Domain\HR\Attendance\Models;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $user_id
 * @property int $branch_id
 * @property CarbonImmutable $local_date
 * @property string $timezone
 * @property CarbonImmutable $clock_in_at
 * @property CarbonImmutable|null $clock_out_at
 * @property string $status
 * @property int $lock_version
 * @property-read User $person
 * @property-read Branch $branch
 */
#[Guarded(['*'])]
class ClockSession extends Model
{
    protected $table = 'hr_clock_sessions';

    protected function casts(): array
    {
        return ['local_date' => 'immutable_date', 'clock_in_at' => 'immutable_datetime', 'clock_out_at' => 'immutable_datetime',
            'user_id' => 'integer', 'branch_id' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Undo clock-out: the attempt a new photo or location result of this kind belongs to. A clock-in is always 1; a
     * clock-out is 1 plus the session's undone clock-outs (PostgreSQL checks the same).
     */
    public static function attemptFor(int $sessionId, string $kind): int
    {
        return $kind === 'out' ? 1 + DB::table('hr_clock_events')->where('clock_session_id', $sessionId)->where('kind', 'out_undone')->count() : 1;
    }
}
