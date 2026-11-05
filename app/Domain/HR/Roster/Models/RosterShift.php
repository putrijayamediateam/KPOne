<?php

namespace App\Domain\HR\Roster\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $roster_version_id
 * @property int $staff_user_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property int $break_minutes
 * @property string $session_type
 * @property int|null $branch_room_id
 * @property int $created_by_user_id
 * @property int $lock_version
 * @property-read RosterVersion $version
 * @property-read User $staff
 */
#[Guarded(['*'])]
class RosterShift extends Model
{
    protected $table = 'hr_roster_shifts';

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'break_minutes' => 'integer', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<RosterVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(RosterVersion::class, 'roster_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}
