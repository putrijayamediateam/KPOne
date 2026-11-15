<?php

namespace App\Domain\HR\Leave\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR-2b: one photo or PDF attached to a leave request, stored encrypted on the private disk. The original file name
 * is never stored.
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $leave_request_id
 * @property int $uploaded_by_user_id
 * @property bool $on_behalf
 * @property string $kind jpeg|png|pdf
 * @property int $bytes
 * @property string $sha256
 * @property string $stored_path
 * @property string $status active|removed|purged
 * @property int|null $removed_by_user_id
 * @property Carbon|null $removed_at
 * @property string|null $removal_reason
 * @property int $lock_version
 * @property Carbon $created_at
 * @property-read LeaveRequest $request
 */
#[Guarded(['*'])]
class LeaveEvidenceFile extends Model
{
    protected $table = 'hr_leave_evidence_files';

    protected function casts(): array
    {
        return ['on_behalf' => 'boolean', 'bytes' => 'integer', 'removed_at' => 'datetime', 'lock_version' => 'integer',
            'leave_request_id' => 'integer', 'uploaded_by_user_id' => 'integer', 'removed_by_user_id' => 'integer'];
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function mime(): string
    {
        return match ($this->kind) {
            'jpeg' => 'image/jpeg', 'png' => 'image/png', default => 'application/pdf',
        };
    }
}
