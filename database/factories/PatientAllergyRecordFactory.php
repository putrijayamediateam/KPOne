<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PatientAllergyRecord> */
class PatientAllergyRecordFactory extends Factory
{
    protected $model = PatientAllergyRecord::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'organisation_id' => fn (array $attributes) => $this->profile($attributes)->organisation_id,
            'allergen_text' => 'Synthetic test allergen',
            'category' => 'other',
            'reaction_text' => null,
            'severity' => null,
            'status' => PatientAllergyRecord::STATUS_ACTIVE,
            'recorded_at' => now()->utc(),
            'recorded_by_user_id' => fn (array $attributes) => $this->profile($attributes)->updated_by_user_id,
            'updated_by_user_id' => fn (array $attributes) => $attributes['recorded_by_user_id'],
            'entered_in_error_at' => null,
            'entered_in_error_by_user_id' => null,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function profile(array $attributes): PatientAllergyProfile
    {
        return PatientAllergyProfile::query()->whereKey($attributes['patient_allergy_profile_id'])->firstOrFail();
    }
}
