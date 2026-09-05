<?php

namespace Tests\Feature\Patient;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientNumberGenerator;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class PatientTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organisation $organisation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
    }

    protected function actor(string $role = 'director', ?Organisation $organisation = null): User
    {
        $user = User::factory()->create([
            'organisation_id' => ($organisation ?? $this->organisation)->id,
            'email' => fake()->unique()->safeEmail(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, mixed> */
    protected function patientAttributes(array $overrides = []): array
    {
        return [
            'full_name' => 'Synthetic Patient Alpha',
            'date_of_birth' => '1990-01-01',
            'sex' => 'female',
            'nationality_code' => 'MY',
            'mobile_phone' => '0123456789',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-'.Str::upper(Str::random(12))]],
            'email' => 'synthetic.patient@kpone.test',
            'address_line_1' => '1 Synthetic Street',
            'postcode' => '50000',
            'city' => 'Kuala Lumpur',
            'state' => 'Kuala Lumpur',
            'country_code' => 'MY',
            'duplicate_override' => true,
            ...$overrides,
        ];
    }

    protected function createPatient(User $actor, array $overrides = []): Patient
    {
        return app(PatientAdministrationService::class)->create($actor, $this->patientAttributes($overrides));
    }

    /** Synthetic historical record, not an application creation bypass. */
    protected function legacyPatient(User $actor): Patient
    {
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $actor->organisation_id, 'patient_number' => app(PatientNumberGenerator::class)->next($actor->organisation), 'full_name' => 'Synthetic Legacy Patient', 'search_name' => 'synthetic legacy patient', 'sex' => 'unknown', 'lock_version' => 1, 'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id])->save();

        return $patient;
    }
}
