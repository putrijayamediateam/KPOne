<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Visit\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicalEncounter> */
class ClinicalEncounterFactory extends Factory
{
    protected $model = ClinicalEncounter::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn (array $attributes) => $this->visit($attributes)->organisation_id,
            'branch_id' => fn (array $attributes) => $this->visit($attributes)->branch_id,
            'attending_clinician_user_id' => fn (array $attributes) => $this->visit($attributes)->assigned_doctor_user_id,
            'status' => ClinicalEncounter::STATUS_IN_PROGRESS,
            'clinical_note' => null,
            'started_at' => now()->utc(),
            'updated_by_user_id' => fn (array $attributes) => $attributes['attending_clinician_user_id'],
            'lock_version' => 1,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function visit(array $attributes): Visit
    {
        return Visit::query()->whereKey($attributes['visit_id'])->firstOrFail();
    }
}
