<?php

namespace App\Domain\Visit\Models;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $branch_id
 * @property int $patient_id
 * @property string $visit_number
 * @property string $idempotency_key
 * @property string $visit_type
 * @property string $status
 * @property string $priority
 * @property string|null $visit_reason
 * @property string|null $intake_purpose
 * @property array<string,mixed>|null $encrypted_presenting_information
 * @property int|null $assigned_doctor_user_id
 * @property string $coverage_type
 * @property int|null $panel_id
 * @property string|null $coverage_panel_name_snapshot
 * @property string|null $coverage_member_reference
 * @property Carbon $registered_at
 * @property int $registered_by_user_id
 * @property int $updated_by_user_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property int $lock_version
 * @property CarbonImmutable|null $completed_at
 * @property array<string,mixed>|null $completion_evidence
 * @property-read Organisation $organisation
 * @property-read Branch $branch
 * @property-read Patient $patient
 * @property-read User|null $assignedDoctor
 * @property-read Panel|null $panel
 * @property-read QueueEntry|null $queueEntry
 * @property-read ClinicalEncounter|null $clinicalEncounter
 */
#[Guarded(['*'])]
#[Hidden(['encrypted_presenting_information'])]
class Visit extends Model
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory;

    protected static function newFactory(): VisitFactory
    {
        return VisitFactory::new();
    }

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'registered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'completion_evidence' => 'array',
            'encrypted_presenting_information' => 'encrypted:array',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'visit_number';
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

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_doctor_user_id');
    }

    /** @return BelongsTo<Panel, $this> */
    public function panel(): BelongsTo
    {
        return $this->belongsTo(Panel::class);
    }

    /** @return HasOne<QueueEntry, $this> */
    public function queueEntry(): HasOne
    {
        return $this->hasOne(QueueEntry::class);
    }

    /** @return HasOne<ClinicalEncounter, $this> */
    public function clinicalEncounter(): HasOne
    {
        return $this->hasOne(ClinicalEncounter::class);
    }

    /** @return HasMany<VisitReasonAssignment, $this> */
    public function reasonAssignments(): HasMany
    {
        return $this->hasMany(VisitReasonAssignment::class)->orderBy('position');
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
