<?php

namespace App\Domain\Organisation\Models;

use App\Domain\Identity\Models\StaffBranchAssignment;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $code
 * @property string $name
 * @property string $timezone
 * @property bool $is_active
 */
#[Guarded(['organisation_id', 'is_active'])]
class Branch extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<StaffBranchAssignment, $this> */
    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffBranchAssignment::class);
    }
}
