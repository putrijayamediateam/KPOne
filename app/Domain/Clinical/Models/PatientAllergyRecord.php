<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Factories\PatientAllergyRecordFactory;
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
 * @property int $patient_allergy_profile_id
 * @property string $allergen_text
 * @property string|null $category
 * @property string|null $reaction_text
 * @property string|null $severity
 * @property string $status
 * @property Carbon $recorded_at
 * @property int $recorded_by_user_id
 * @property int $updated_by_user_id
 * @property Carbon|null $entered_in_error_at
 * @property int|null $entered_in_error_by_user_id
 * @property-read PatientAllergyProfile $profile
 */
#[Guarded(['*'])]
class PatientAllergyRecord extends Model
{
    /** @use HasFactory<PatientAllergyRecordFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENTERED_IN_ERROR = 'entered_in_error';

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Patient Allergy Records cannot be deleted.'));
    }

    protected static function newFactory(): PatientAllergyRecordFactory
    {
        return PatientAllergyRecordFactory::new();
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'entered_in_error_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return BelongsTo<PatientAllergyProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(PatientAllergyProfile::class, 'patient_allergy_profile_id');
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
    public function enteredInErrorBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_in_error_by_user_id');
    }
}
