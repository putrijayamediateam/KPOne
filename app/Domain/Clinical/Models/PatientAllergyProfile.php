<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Database\Factories\PatientAllergyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $patient_id
 * @property string $status
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by_user_id
 * @property int $updated_by_user_id
 * @property int $lock_version
 */
#[Guarded(['*'])]
class PatientAllergyProfile extends Model
{
    /** @use HasFactory<PatientAllergyProfileFactory> */
    use HasFactory;

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_NO_KNOWN_ALLERGIES = 'no_known_allergies';

    public const STATUS_HAS_ALLERGIES = 'has_allergies';

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Patient Allergy Profiles cannot be deleted.'));
    }

    protected static function newFactory(): PatientAllergyProfileFactory
    {
        return PatientAllergyProfileFactory::new();
    }

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_datetime',
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
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasMany<PatientAllergyRecord, $this> */
    public function allergyRecords(): HasMany
    {
        return $this->hasMany(PatientAllergyRecord::class);
    }

    /** @return HasMany<PatientAllergyProfileVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PatientAllergyProfileVersion::class);
    }
}
