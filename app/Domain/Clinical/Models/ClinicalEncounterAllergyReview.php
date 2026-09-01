<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Branch;
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
 * @property int $branch_id
 * @property int $clinical_encounter_id
 * @property int $patient_allergy_profile_id
 * @property int $allergy_profile_lock_version_reviewed
 * @property int $reviewed_by_user_id
 * @property Carbon $reviewed_at
 */
#[Guarded(['*'])]
class ClinicalEncounterAllergyReview extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Encounter Allergy Reviews cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'allergy_profile_lock_version_reviewed' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
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

    /** @return BelongsTo<ClinicalEncounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class, 'clinical_encounter_id');
    }

    /** @return BelongsTo<PatientAllergyProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(PatientAllergyProfile::class, 'patient_allergy_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
