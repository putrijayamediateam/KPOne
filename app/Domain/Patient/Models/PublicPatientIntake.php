<?php

namespace App\Domain\Patient\Models;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Organisation\Models\PublicCheckInLink;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $encrypted_payload
 * @property CarbonImmutable $consented_at
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $payload_purge_at
 * @property CarbonImmutable|null $payload_purged_at
 * @property CarbonImmutable|null $review_started_at
 * @property CarbonImmutable|null $correction_required_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $rejected_at
 * @property-read Organisation $organisation
 * @property-read Branch $branch
 * @property-read PublicCheckInLink $link
 * @property-read PublicIntakeSession $intakeSession
 * @property-read User|null $reviewer
 * @property-read Patient|null $patient
 * @property-read Visit|null $visit
 * @property-read QueueEntry|null $queueEntry
 */
#[Guarded([])]
#[Hidden(['encrypted_payload', 'payload_fingerprint', 'acceptance_idempotency_key', 'acceptance_fingerprint'])]
class PublicPatientIntake extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_CORRECTION_REQUIRED = 'correction_required';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const REJECTION_CATEGORIES = [
        'duplicate_unresolved',
        'insufficient_information',
        'invalid_consent',
        'not_at_branch',
        'other_controlled',
    ];

    protected $table = 'public_patient_intakes';

    protected function casts(): array
    {
        return [
            'encrypted_payload' => 'encrypted:array',
            'consented_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'payload_purge_at' => 'immutable_datetime',
            'payload_purged_at' => 'immutable_datetime',
            'review_started_at' => 'immutable_datetime',
            'correction_required_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<PublicCheckInLink, $this> */
    public function link(): BelongsTo
    {
        return $this->belongsTo(PublicCheckInLink::class, 'public_checkin_link_id');
    }

    /** @return BelongsTo<PublicIntakeSession, $this> */
    public function intakeSession(): BelongsTo
    {
        return $this->belongsTo(PublicIntakeSession::class, 'public_intake_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewing_user_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<QueueEntry, $this> */
    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class);
    }
}
