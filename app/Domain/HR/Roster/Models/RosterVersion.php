<?php

namespace App\Domain\HR\Roster\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $roster_week_id
 * @property int $version_number
 * @property string $status draft|submitted|approved|superseded|discarded
 * @property int|null $based_on_version_id
 * @property int $created_by_user_id
 * @property int|null $submitted_by_user_id
 * @property Carbon|null $submitted_at
 * @property int|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $return_reason
 * @property int|null $returned_by_user_id
 * @property Carbon|null $superseded_at
 * @property int $lock_version
 * @property Carbon $updated_at
 * @property-read RosterWeek $week
 */
#[Guarded(['*'])]
class RosterVersion extends Model
{
    protected $table = 'hr_roster_versions';

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'decided_at' => 'datetime', 'superseded_at' => 'datetime', 'lock_version' => 'integer', 'version_number' => 'integer',
            'created_by_user_id' => 'integer', 'submitted_by_user_id' => 'integer', 'decided_by_user_id' => 'integer', 'returned_by_user_id' => 'integer'];
    }

    /** @return BelongsTo<RosterWeek, $this> */
    public function week(): BelongsTo
    {
        return $this->belongsTo(RosterWeek::class, 'roster_week_id');
    }

    /** @return HasMany<RosterShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(RosterShift::class, 'roster_version_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'submitted'], true);
    }
}
