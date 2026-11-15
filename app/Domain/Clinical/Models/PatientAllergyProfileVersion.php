<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $patient_allergy_profile_id
 * @property int $version
 * @property string $resulting_status
 * @property Carbon $changed_at
 * @property int $changed_by_user_id
 */
#[Guarded(['*'])]
class PatientAllergyProfileVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Patient Allergy Profile versions are append-only.'));
        static::deleting(fn () => throw new LogicException('Patient Allergy Profile versions are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'changed_at' => 'immutable_datetime',
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
