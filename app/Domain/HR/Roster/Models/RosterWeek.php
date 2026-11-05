<?php

namespace App\Domain\HR\Roster\Models;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property Carbon $week_start
 * @property int $lock_version
 * @property-read Branch $branch
 */
#[Guarded(['*'])]
class RosterWeek extends Model
{
    protected $table = 'hr_roster_weeks';

    protected function casts(): array
    {
        return ['week_start' => 'date', 'lock_version' => 'integer'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<RosterVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RosterVersion::class, 'roster_week_id');
    }
}
