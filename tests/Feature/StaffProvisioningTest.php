<?php

namespace Tests\Feature;

use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\AccessChangeActorContext;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffProvisioningService;
use App\Domain\Identity\Services\StaffRoleService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class StaffProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Branch $cheras;

    private Branch $puchong;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $this->puchong = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
        $this->department = Department::query()->where('organisation_id', $this->organisation->id)->firstOrFail();
    }

    public function test_authorised_staff_provisioning_creates_complete_google_only_account(): void
    {
        $actor = $this->createStaff('director');
        $payload = $this->payload('new.staff@kpone.test');
        $payload['assignments'][] = [
            'branch_id' => $this->puchong->id,
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->addDay()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
        ];

        $response = $this->actingAs($actor)->post(route('staff.store'), $payload);

        $staff = User::query()->where('email', 'new.staff@kpone.test')->firstOrFail();
        $response->assertRedirect(route('staff.show', $staff));
        $this->assertSame($actor->organisation_id, $staff->organisation_id);
        $this->assertNull($staff->password);
        $this->assertTrue($staff->is_active);
        $this->assertDatabaseHas('staff_profiles', [
            'user_id' => $staff->id,
            'department_id' => $this->department->id,
            'staff_number' => 'SYN-0001',
        ]);
        $this->assertTrue($staff->hasRole('ca'));
        $this->assertSame(2, $staff->staffProfile->branchAssignments()->count());
        $this->assertSame(1, $staff->staffProfile->branchAssignments()->effectiveAt()->where('is_primary', true)->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.created',
            'subject_id' => $staff->id,
            'actor_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'staff.branch_assignment.created',
            'actor_user_id' => $actor->id,
        ]);
    }

    public function test_password_provisioning_hashes_immediately_and_never_audits_or_returns_plaintext(): void
    {
        $actor = $this->createStaff('director');
        $password = 'Dummy-Strong9!Pass';
        $payload = $this->payload('password.staff@kpone.test');
        $payload['credential_strategy'] = 'password';
        $payload['password'] = $password;
        $payload['password_confirmation'] = $password;

        $response = $this->actingAs($actor)->post(route('staff.store'), $payload);

        $staff = User::query()->where('email', 'password.staff@kpone.test')->firstOrFail();
        $response->assertRedirect(route('staff.show', $staff));
        $this->assertNotSame($password, $staff->getRawOriginal('password'));
        $this->assertTrue(Hash::check($password, $staff->password));
        $this->assertFalse(AuditLog::query()->get()->contains(
            fn (AuditLog $log) => str_contains((string) json_encode($log->metadata), $password),
        ));
        $response->assertSessionMissing('password');
        $response->assertSessionMissing('password_confirmation');
    }

    public function test_unauthorised_and_branch_scoped_users_cannot_provision_staff(): void
    {
        $actor = $this->createStaff('ca', true);

        $this->actingAs($actor)
            ->post(route('staff.store'), $this->payload('forbidden.staff@kpone.test'))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'forbidden.staff@kpone.test']);
    }

    public function test_browser_payload_cannot_choose_organisation_or_sensitive_identity_state(): void
    {
        $actor = $this->createStaff('director');
        $payload = [
            ...$this->payload('protected.staff@kpone.test'),
            'organisation_id' => $this->organisation->id + 999,
            'google_subject' => 'untrusted-subject',
            'last_login_at' => now()->toDateTimeString(),
            'deactivated_at' => now()->toDateTimeString(),
        ];

        $this->actingAs($actor)->post(route('staff.store'), $payload)
            ->assertSessionHasErrors(['organisation_id', 'google_subject', 'last_login_at', 'deactivated_at']);

        $this->assertDatabaseMissing('users', ['email' => 'protected.staff@kpone.test']);
    }

    public function test_active_account_cannot_be_provisioned_with_only_a_future_primary_assignment(): void
    {
        $actor = $this->createStaff('director');
        $payload = $this->payload('future.primary@kpone.test');
        $payload['assignments'][0]['valid_from'] = now()->addDay()->toDateString();

        $this->actingAs($actor)->post(route('staff.store'), $payload)
            ->assertSessionHasErrors('assignments');

        $this->assertDatabaseMissing('users', ['email' => 'future.primary@kpone.test']);
    }

    public function test_technical_admin_cannot_assign_the_protected_director_role(): void
    {
        $actor = $this->createStaff('technical_admin');
        $payload = $this->payload('attempted.director@kpone.test');
        $payload['roles'] = ['director'];

        $this->actingAs($actor)->post(route('staff.store'), $payload)->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'attempted.director@kpone.test']);
    }

    public function test_failure_after_role_access_change_rolls_back_all_provisioning_state(): void
    {
        $actor = $this->createStaff('director');
        $baseline = $this->stateCounts();
        $failingRoles = new class(app(StaffAuthorityService::class), app(AccessChangeActorContext::class)) extends StaffRoleService
        {
            public function sync(User $subject, array $roles, User $actor): User
            {
                parent::sync($subject, $roles, $actor);

                throw new RuntimeException('Injected failure after role access changed.');
            }
        };
        $service = new StaffProvisioningService(
            app(BranchAssignmentService::class),
            $failingRoles,
            app(StaffAuthorityService::class),
            app(AuditRecorder::class),
        );

        try {
            $service->provision($actor, $this->payload('rollback.roles@kpone.test'));
            $this->fail('Injected provisioning failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after role access changed.', $exception->getMessage());
        }

        $this->assertNoProvisioningState('rollback.roles@kpone.test');
        $this->assertSame($baseline, $this->stateCounts());
    }

    public function test_failure_after_multiple_branch_changes_rolls_back_user_profile_roles_assignments_and_audits(): void
    {
        $actor = $this->createStaff('director');
        $baseline = $this->stateCounts();
        $payload = $this->payload('rollback.branches@kpone.test');
        $payload['assignments'][] = [
            'branch_id' => $this->puchong->id,
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
        ];
        $failingAssignments = new class(app(AuditRecorder::class), app(StaffAuthorityService::class)) extends BranchAssignmentService
        {
            private int $created = 0;

            public function create(
                StaffProfile $profile,
                Branch $branch,
                array $attributes,
                User $actor,
            ): StaffBranchAssignment {
                $assignment = parent::create($profile, $branch, $attributes, $actor);
                $this->created++;

                if ($this->created === 2) {
                    throw new RuntimeException('Injected failure after branch access changed.');
                }

                return $assignment;
            }
        };
        $service = new StaffProvisioningService(
            $failingAssignments,
            app(StaffRoleService::class),
            app(StaffAuthorityService::class),
            app(AuditRecorder::class),
        );

        try {
            $service->provision($actor, $payload);
            $this->fail('Injected provisioning failure did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure after branch access changed.', $exception->getMessage());
        }

        $this->assertNoProvisioningState('rollback.branches@kpone.test');
        $this->assertSame($baseline, $this->stateCounts());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'staff.created']);
    }

    public function test_public_registration_remains_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function payload(string $email): array
    {
        return [
            'name' => 'Synthetic Phase Zero Staff',
            'email' => $email,
            'credential_strategy' => 'google_only',
            'department_id' => $this->department->id,
            'staff_number' => 'SYN-0001',
            'job_title' => 'Synthetic Operations Role',
            'is_active' => true,
            'roles' => ['ca'],
            'assignments' => [[
                'branch_id' => $this->cheras->id,
                'assignment_type' => 'permanent',
                'is_primary' => true,
                'valid_from' => now()->toDateString(),
                'valid_until' => null,
            ]],
        ];
    }

    private function createStaff(string $role, bool $withBranch = false): User
    {
        $user = User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Synthetic '.Str::random(8),
            'email' => 'synthetic.'.Str::uuid().'@kpone.test',
        ]);
        $profile = StaffProfile::query()->forceCreate([
            'user_id' => $user->id,
            'department_id' => $this->department->id,
            'job_title' => 'Synthetic Test Role',
        ]);
        $user->assignRole($role);

        if ($withBranch) {
            StaffBranchAssignmentBootstrapper::create($profile, $this->cheras, [
                'assignment_type' => 'permanent',
                'is_primary' => true,
                'valid_from' => now()->toDateString(),
            ]);
        }

        return $user->refresh()->load('staffProfile');
    }

    private function assertNoProvisioningState(string $email): void
    {
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertSame(0, StaffProfile::query()->whereHas('user', fn ($query) => $query->where('email', $email))->count());
        $this->assertSame(0, StaffBranchAssignment::query()->whereHas('staffProfile.user', fn ($query) => $query->where('email', $email))->count());
        $this->assertSame(0, User::query()->where('email', $email)->whereHas('roles')->count());
        $this->assertFalse(AuditLog::query()->where('metadata', 'like', "%{$email}%")->exists());
    }

    /** @return array<string, int> */
    private function stateCounts(): array
    {
        return [
            'users' => User::query()->count(),
            'profiles' => StaffProfile::query()->count(),
            'roles' => DB::table('model_has_roles')->count(),
            'assignments' => StaffBranchAssignment::query()->count(),
            'audits' => AuditLog::query()->count(),
        ];
    }
}
