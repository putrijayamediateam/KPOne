<?php

namespace Database\Factories;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Visit> */
class VisitFactory extends Factory
{
    protected $model = Visit::class;

    public function definition(): array
    {
        $patient = Patient::query()->firstOrFail();
        $user = User::query()->where('organisation_id', $patient->organisation_id)->firstOrFail();

        return [
            'organisation_id' => $patient->organisation_id,
            'branch_id' => Branch::query()->where('organisation_id', $patient->organisation_id)->value('id'),
            'patient_id' => $patient->id,
            'visit_number' => 'KPV-'.fake()->unique()->numerify('########'),
            'idempotency_key' => (string) Str::uuid(),
            'visit_type' => 'otc',
            'status' => Visit::STATUS_REGISTERED,
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
            'registered_at' => now(),
            'registered_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
            'lock_version' => 1,
        ];
    }
}
