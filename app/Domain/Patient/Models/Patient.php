<?php

namespace App\Domain\Patient\Models;

use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientProblemRecord;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $patient_number
 * @property string $full_name
 * @property string $search_name
 * @property Carbon|null $date_of_birth
 * @property string $sex
 * @property string|null $nationality_code
 * @property string|null $mobile_phone
 * @property string|null $email
 * @property string|null $address_line_1
 * @property string|null $address_line_2
 * @property string|null $postcode
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country_code
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Guarded(['*'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'patient_number';
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /** @return HasMany<PatientIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class);
    }

    /** @return HasOne<PatientAllergyProfile, $this> */
    public function allergyProfile(): HasOne
    {
        return $this->hasOne(PatientAllergyProfile::class);
    }

    /** @return HasMany<PatientProblemRecord, $this> */
    public function problemRecords(): HasMany
    {
        return $this->hasMany(PatientProblemRecord::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
