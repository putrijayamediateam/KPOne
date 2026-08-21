<?php

namespace Database\Factories;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Patient> */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'organisation_id' => fn () => Organisation::query()->value('id'),
            'patient_number' => 'KP-'.fake()->unique()->numerify('########'),
            'full_name' => fake()->name(),
            'search_name' => fn (array $attributes) => Str::lower($attributes['full_name']),
            'date_of_birth' => fake()->dateTimeBetween('-90 years', '-1 year')->format('Y-m-d'),
            'sex' => fake()->randomElement(['female', 'male', 'unknown']),
            'country_code' => 'MY',
            'lock_version' => 1,
        ];
    }
}
