<?php

namespace App\Domain\Patient\Family;

use App\Domain\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * FAM-1: one patient's membership of a family. Never deleted: leaving stamps left_at and the row is then final.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $patient_family_id
 * @property int $patient_id
 * @property string $role
 * @property Carbon $joined_at
 * @property int $added_by_user_id
 * @property Carbon|null $left_at
 * @property int|null $removed_by_user_id
 * @property-read Patient $patient
 * @property-read PatientFamily $family
 */
#[Guarded(['*'])]
class PatientFamilyMember extends Model
{
    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'left_at' => 'datetime'];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<PatientFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(PatientFamily::class, 'patient_family_id');
    }
}
