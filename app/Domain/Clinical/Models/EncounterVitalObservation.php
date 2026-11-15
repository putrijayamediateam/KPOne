<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Factories\EncounterVitalObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $clinical_encounter_id
 * @property Carbon|null $observed_at
 */
#[Guarded(['*'])]
class EncounterVitalObservation extends Model
{
    /** @use HasFactory<EncounterVitalObservationFactory> */
    use HasFactory;

    protected static function newFactory(): EncounterVitalObservationFactory
    {
        return EncounterVitalObservationFactory::new();
    }

    protected function casts(): array
    {
        return [
            'observed_at' => 'immutable_datetime',
            'systolic_bp' => 'integer',
            'diastolic_bp' => 'integer',
            'pulse_bpm' => 'integer',
            'temperature_celsius' => 'decimal:2',
            'spo2_percent' => 'decimal:2',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClinicalEncounter, $this> */
    public function clinicalEncounter(): BelongsTo
    {
        return $this->belongsTo(ClinicalEncounter::class);
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
}
