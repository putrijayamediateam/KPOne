<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientAllergyProfile> */
class PatientAllergyProfileFactory extends Factory
{
    protected $model = PatientAllergyProfile::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn (array $attributes) => $this->patient($attributes)->organisation_id,
            'status' => PatientAllergyProfile::STATUS_UNKNOWN,
            'reviewed_at' => null,
            'reviewed_by_user_id' => null,
            'updated_by_user_id' => fn () => User::query()->value('id'),
            'lock_version' => 1,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function patient(array $attributes): Patient
    {
        return Patient::query()->whereKey($attributes['patient_id'])->firstOrFail();
    }
}
