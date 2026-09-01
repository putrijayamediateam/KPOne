<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Database\Factories\ClinicalEncounterFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Collection;
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
 * @property int $visit_id
 * @property int $attending_clinician_user_id
 * @property string $status
 * @property string|null $clinical_note
 * @property Carbon $started_at
 * @property int $updated_by_user_id
 * @property int $lock_version
 * @property-read Visit $visit
 * @property-read User $attendingClinician
 * @property-read EncounterVitalObservation|null $vitalObservation
 * @property-read Collection<int, EncounterDiagnosis> $diagnoses
 */
#[Guarded(['*'])]
class ClinicalEncounter extends Model
{
    /** @use HasFactory<ClinicalEncounterFactory> */
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'in_progress';

    protected static function newFactory(): ClinicalEncounterFactory
    {
        return ClinicalEncounterFactory::new();
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
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

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attendingClinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attending_clinician_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasOne<EncounterVitalObservation, $this> */
    public function vitalObservation(): HasOne
    {
        return $this->hasOne(EncounterVitalObservation::class);
    }

    /** @return HasMany<EncounterDiagnosis, $this> */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(EncounterDiagnosis::class)->orderBy('position');
    }

    /** @return HasOne<ClinicalEncounterAllergyReview, $this> */
    public function allergyReview(): HasOne
    {
        return $this->hasOne(ClinicalEncounterAllergyReview::class);
    }
}
