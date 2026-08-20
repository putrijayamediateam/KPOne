<?php

namespace App\Domain\Identity\Models;

use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $department_id
 * @property string|null $staff_number
 * @property string|null $job_title
 * @property-read User $user
 * @property-read Department|null $department
 * @property-read Collection<int, StaffBranchAssignment> $branchAssignments
 */
#[Guarded(['user_id', 'department_id'])]
class StaffProfile extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<StaffBranchAssignment, $this> */
    public function branchAssignments(): HasMany
    {
        return $this->hasMany(StaffBranchAssignment::class);
    }
}
