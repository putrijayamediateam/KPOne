<?php

namespace Tests\Feature\Patient;

use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
