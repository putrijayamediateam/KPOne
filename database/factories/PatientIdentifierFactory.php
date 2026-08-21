<?php

namespace Database\Factories;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientIdentifier> */
class PatientIdentifierFactory extends Factory
{
    protected $model = PatientIdentifier::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn () => Patient::factory()->create()->organisation_id,
            'patient_id' => function (array $attributes) {
                return Patient::factory()->create(['organisation_id' => $attributes['organisation_id']])->id;
            },
            'identifier_type' => 'passport',
            'issuing_country_code' => 'MY',
            'normalized_value' => fake()->unique()->bothify('P########'),
        ];
    }
}
