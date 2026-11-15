<?php

namespace Database\Factories;

use App\Domain\Clinical\Models\PatientProblemRecord;
use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PatientProblemRecord> */
class PatientProblemRecordFactory extends Factory
{
    protected $model = PatientProblemRecord::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'organisation_id' => fn (array $attributes) => $this->patient($attributes)->organisation_id,
            'condition_text' => 'Synthetic test condition',
            'condition_code' => null,
            'code_system' => null,
            'status' => PatientProblemRecord::STATUS_ACTIVE,
            'onset_date' => null,
            'resolved_date' => null,
            'recorded_by_user_id' => fn () => User::query()->value('id'),
            'updated_by_user_id' => fn (array $attributes) => $attributes['recorded_by_user_id'],
            'resolved_at' => null,
            'resolved_by_user_id' => null,
            'entered_in_error_at' => null,
            'entered_in_error_by_user_id' => null,
            'lock_version' => 1,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function patient(array $attributes): Patient
    {
        return Patient::query()->whereKey($attributes['patient_id'])->firstOrFail();
    }
}
