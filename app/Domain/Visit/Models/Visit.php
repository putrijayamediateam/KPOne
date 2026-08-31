<?php

namespace App\Domain\Visit\Models;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Database\Factories\VisitFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property-read Organisation $organisation
 * @property-read Branch $branch
 * @property-read Patient $patient
 * @property-read User|null $assignedDoctor
 * @property-read Panel|null $panel
 */
#[Guarded(['*'])]
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

    protected function casts(): array
    {
        return [
            'registered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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
