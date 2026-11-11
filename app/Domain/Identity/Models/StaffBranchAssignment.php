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
     * The calendar date "today" at the given branch.
     *
     * Assignment periods are branch-local calendar dates, while the app clock
     * is UTC, so the UTC date is a day behind Kuala Lumpur from 00:00 to 08:00
     * local time. Always compare valid_from / valid_until with this date.
     */
    public static function todayAt(Branch $branch): string
    {
        return now()->setTimezone($branch->timezone)->toDateString();
    }

    /**
     * Branch-local "today" for a branch id from untrusted input; an unknown
     * id falls back to the app date (other validation refuses the id).
     *
     * @param  array<int, string>  $timezonesByBranchId
     */
    public static function todayForBranchId(mixed $branchId, array $timezonesByBranchId): string
    {
        $timezone = is_numeric($branchId) ? ($timezonesByBranchId[(int) $branchId] ?? null) : null;

        return $timezone === null ? now()->toDateString() : now()->setTimezone($timezone)->toDateString();
    }

    public function isEffectiveOn(string $date): bool
    {
        return $this->valid_from->toDateString() <= $date
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $date);
    }

    /** Whether the assignment is effective on its own branch's calendar day. */
    public function isEffectiveTodayAtBranch(): bool
    {
        return $this->isEffectiveOn(self::todayAt($this->branch));
    }

    /**
     * Assignments effective on the given calendar date.
     *
     * Pass the branch-local date (see todayAt()). The no-argument form uses the
     * UTC date and is kept only for older tests and seeders; application code
     * must always pass a date (guarded by StaffAssignmentBranchDayTest).
     *
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
