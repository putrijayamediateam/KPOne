<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EncounterDiagnosis> */
class EncounterDiagnosisFactory extends Factory
{
    protected $model = EncounterDiagnosis::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn (array $attributes) => $this->encounter($attributes)->organisation_id,
            'branch_id' => fn (array $attributes) => $this->encounter($attributes)->branch_id,
            'diagnosis_text' => 'Synthetic diagnosis',
            'diagnosis_code' => null,
            'code_system' => null,
            'is_primary' => false,
            'position' => 1,
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
