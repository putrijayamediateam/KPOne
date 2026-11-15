<?php

namespace App\Domain\Queue\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $doctor_user_id
 * @property int $branch_room_id
 * @property Carbon $operational_date
 * @property int $lock_version
 * @property-read BranchRoom $room
 */
#[Guarded(['*'])]
class DoctorRoomAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'operational_date' => 'immutable_date',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<BranchRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(BranchRoom::class, 'branch_room_id');
    }
}
