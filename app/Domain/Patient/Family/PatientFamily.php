<?php

namespace App\Domain\Patient\Family;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FAM-1: a family of linked patients. No head and no payer; members are listed in patient_family_members.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lock_version
 * @property int $created_by_user_id
 */
#[Guarded(['*'])]
class PatientFamily extends Model
{
    /** @return HasMany<PatientFamilyMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PatientFamilyMember::class);
    }
}
