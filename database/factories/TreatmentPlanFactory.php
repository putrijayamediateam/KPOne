<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\TreatmentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TreatmentPlan> */
class TreatmentPlanFactory extends Factory
{
    protected $model = TreatmentPlan::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn (array $a) => $this->encounter($a)->organisation_id,
            'branch_id' => fn (array $a) => $this->encounter($a)->branch_id,
            'status' => TreatmentPlan::STATUS_IN_PROGRESS,
            'created_by_user_id' => fn (array $a) => $this->encounter($a)->attending_clinician_user_id,
            'updated_by_user_id' => fn (array $a) => $this->encounter($a)->attending_clinician_user_id,
            'lock_version' => 1,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function encounter(array $attributes): ClinicalEncounter
    {
        return ClinicalEncounter::query()->whereKey($attributes['clinical_encounter_id'])->firstOrFail();
    }
}
