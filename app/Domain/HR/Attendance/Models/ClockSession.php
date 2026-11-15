<?php

namespace App\Domain\HR\Attendance\Models;

use App\Domain\Organisation\Models\Branch;
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
}
