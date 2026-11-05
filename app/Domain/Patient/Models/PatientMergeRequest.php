<?php

namespace App\Domain\Patient\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REG-3 two-person merge or unmerge request. Facts are immutable once written; only the decision fields change,
 * once, from proposed (enforced by PostgreSQL).
 *
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property string $kind
 * @property int $kept_patient_id
 * @property int $merged_patient_id
 * @property string $reason_code
 * @property string $status
 * @property int $proposed_by_user_id
 * @property CarbonImmutable $proposed_at
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property int $kept_lock_version
 * @property int $merged_lock_version
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property int $lock_version
 */
#[Guarded(['*'])]
class PatientMergeRequest extends Model
{
    public const KIND_MERGE = 'merge';

    public const KIND_UNMERGE = 'unmerge';

    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /** Fixed reasons, no free text (owner decision E). */
    public const MERGE_REASONS = [
        'same_person_registered_twice' => 'Same person, registered twice',
        'wrong_identifier_typed' => 'Wrong IC or passport typed',
        'other_explained_in_person' => 'Other (explain to the approver in person)',
    ];

    public const UNMERGE_REASONS = [
        'merged_in_error' => 'Merged in error (different people)',
        'other_explained_in_person' => 'Other (explain to the approver in person)',
    ];

    protected function casts(): array
    {
        return [
            'kept_patient_id' => 'integer',
            'merged_patient_id' => 'integer',
            'proposed_by_user_id' => 'integer',
            'decided_by_user_id' => 'integer',
            'proposed_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'kept_lock_version' => 'integer',
            'merged_lock_version' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Patient, $this> */
    public function keptPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'kept_patient_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function mergedPatient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'merged_patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
