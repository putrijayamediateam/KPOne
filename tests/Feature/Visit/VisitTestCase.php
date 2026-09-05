<?php

namespace Tests\Feature\Visit;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

abstract class VisitTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organisation $organisation;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->branch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'CHERAS')->firstOrFail();
    }

    protected function actor(string $role = 'ca', ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $user = User::factory()->create([
            'organisation_id' => $branch->organisation_id,
            'email' => fake()->unique()->userName().'@kpone.test',
        ]);
        $user->assignRole($role);
        $profile = new StaffProfile;
        $profile->forceFill([
            'user_id' => $user->id,
            'department_id' => Department::query()->where('organisation_id', $branch->organisation_id)->value('id'),
        ])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, [
            'is_primary' => true,
            'assignment_type' => 'permanent',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => null,
        ]);

        return $user;
    }

    protected function selectBranch(User $actor, ?Branch $branch = null): void
    {
        $this->actingAs($actor);
        session([BranchAccessService::SESSION_KEY => ($branch ?? $this->branch)->id]);
    }

    protected function patient(?User $actor = null, array $overrides = []): Patient
    {
        $actor ??= $this->actor('director');

        return app(PatientAdministrationService::class)->create($actor, [
            'full_name' => 'Synthetic Visit Patient '.Str::random(8),
            'date_of_birth' => '1990-01-01',
            'sex' => 'unknown',
            'mobile_phone' => '+60123456789',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-'.Str::upper(Str::random(12))]],
            'duplicate_override' => true,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    protected function visitAttributes(Patient $patient, array $overrides = []): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'expected_branch_id' => $this->branch->id,
            'patient_number' => $patient->patient_number,
            'visit_type' => 'otc',
            'assigned_doctor_user_id' => null,
            'visit_reason' => null,
            'priority' => 'normal',
            'coverage_type' => 'self_pay',
            'panel_id' => null,
            'coverage_member_reference' => null,
            'confirm_repeat' => false,
            ...$overrides,
        ];
    }

    protected function register(User $actor, Patient $patient, array $overrides = []): Visit
    {
        $this->selectBranch($actor);

        return app(VisitRegistrationService::class)->register($actor, $this->visitAttributes($patient, $overrides));
    }
}
