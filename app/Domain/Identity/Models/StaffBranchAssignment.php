<?php

namespace App\Domain\Identity\Models;

use App\Domain\Organisation\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $staff_profile_id
 * @property int $branch_id
 * @property bool $is_primary
 * @property string $assignment_type
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property-read StaffProfile $staffProfile
 * @property-read Branch $branch
 */
#[Guarded(['*'])]
class StaffBranchAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /** @return BelongsTo<StaffProfile, $this> */
    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<StaffBranchAssignment>  $query
     * @return Builder<StaffBranchAssignment>
     */
    public function scopeEffectiveAt(Builder $query, Carbon|string|null $date = null): Builder
    {
        $effectiveDate = $date instanceof Carbon
            ? $date->toDateString()
            : ($date ?? now()->toDateString());

        return $query
            ->whereDate('valid_from', '<=', $effectiveDate)
            ->where(fn ($builder) => $builder
                ->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', $effectiveDate));
    }
}
