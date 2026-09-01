<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Database\Factories\PatientProblemRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $organisation_id
 * @property int $patient_id
 * @property string $condition_text
 * @property string|null $condition_code
 * @property string|null $code_system
 * @property string $status
 * @property Carbon|null $onset_date
 * @property Carbon|null $resolved_date
 * @property int $recorded_by_user_id
 * @property int $updated_by_user_id
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $entered_in_error_at
 * @property int|null $entered_in_error_by_user_id
 * @property int $lock_version
 */
#[Guarded(['*'])]
class PatientProblemRecord extends Model
{
    /** @use HasFactory<PatientProblemRecordFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ENTERED_IN_ERROR = 'entered_in_error';

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Patient Problem Records cannot be deleted.'));
    }

    protected static function newFactory(): PatientProblemRecordFactory
    {
        return PatientProblemRecordFactory::new();
    }

    protected function casts(): array
    {
        return [
            'onset_date' => 'immutable_date',
            'resolved_date' => 'immutable_date',
            'resolved_at' => 'immutable_datetime',
            'entered_in_error_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function enteredInErrorBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_in_error_by_user_id');
    }
}
