<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EncounterVitalObservation> */
class EncounterVitalObservationFactory extends Factory
{
    protected $model = EncounterVitalObservation::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn (array $attributes) => $this->encounter($attributes)->organisation_id,
            'branch_id' => fn (array $attributes) => $this->encounter($attributes)->branch_id,
            'observed_at' => now()->utc(),
            'systolic_bp' => 120,
            'diastolic_bp' => 80,
            'pulse_bpm' => 72,
            'temperature_celsius' => 36.8,
            'spo2_percent' => 98,
            'weight_kg' => 60,
            'height_cm' => 160,
            'recorded_by_user_id' => fn (array $attributes) => $this->encounter($attributes)->attending_clinician_user_id,
            'updated_by_user_id' => fn (array $attributes) => $attributes['recorded_by_user_id'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function encounter(array $attributes): ClinicalEncounter
    {
        return ClinicalEncounter::query()->whereKey($attributes['clinical_encounter_id'])->firstOrFail();
    }
}
