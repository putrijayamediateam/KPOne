<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class Phase0FoundationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Branch $cheras;

    private Branch $puchong;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $this->puchong = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
    }

    public function test_unauthenticated_users_cannot_access_kpone(): void
    {
        foreach (['dashboard', 'staff.index', 'branches.index', 'access-control.index', 'audit-logs.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
    }

    public function test_inactive_staff_cannot_authenticate(): void
    {
        $user = User::factory()->inactive()->create([
            'organisation_id' => $this->organisation->id,
            'email' => 'inactive.staff@kpone.test',
        ]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login.failed']);
    }

    public function test_unauthorised_roles_cannot_access_restricted_routes(): void
    {
        $user = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($user)->get(route('access-control.index'))->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'authorization.denied',
        ]);
    }

    public function test_branch_scoped_users_cannot_access_an_unassigned_branch(): void
    {
        $user = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($user)->get(route('branches.show', $this->puchong))->assertForbidden();
    }

    public function test_organisation_scoped_users_can_access_permitted_branches(): void
    {
        $user = $this->createStaff('director');

        $this->actingAs($user)
            ->get(route('branches.show', $this->puchong))
            ->assertOk();
    }

    public function test_role_assignment_works(): void
    {
        $user = $this->createStaff();

        $user->assignRole('finance_officer');

        $this->assertTrue($user->fresh()->hasRole('finance_officer'));
        $this->assertTrue($user->fresh()->can('staff.view.organisation'));
    }

    public function test_branch_assignment_works_and_is_effective(): void
    {
        $user = $this->createStaff();

        $assignment = app(BranchAssignmentService::class)->create(
            $user->staffProfile,
            $this->cheras,
            [
                'assignment_type' => 'permanent',
                'is_primary' => true,
                'valid_from' => now()->subDay()->toDateString(),
                'valid_until' => null,
            ],
        );

        $this->assertTrue($assignment->is_primary);
        $this->assertTrue($user->staffProfile->branchAssignments()->effectiveAt()->where('branch_id', $this->cheras->id)->exists());
    }

    public function test_security_and_admin_changes_produce_audit_records(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff();
        $this->actingAs($actor);

        $subject->assignRole('finance_officer');
        app(StaffAdministrationService::class)->setActive($subject, false, $actor);
        app(BranchAssignmentService::class)->create(
            $subject->staffProfile,
            $this->cheras,
            ['assignment_type' => 'temporary', 'valid_from' => now()->toDateString()],
            $actor,
        );

        foreach (['access.role.attached', 'staff.deactivated', 'staff.branch_assignment.created'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event]);
        }
    }

    public function test_login_and_logout_are_audited_without_credentials(): void
    {
        $user = $this->createStaff('ca', [$this->cheras], 'login.staff@kpone.test');

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->post(route('logout'))->assertRedirect(route('home'));

        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login.succeeded', 'actor_user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout', 'actor_user_id' => $user->id]);
        $this->assertFalse(AuditLog::query()->whereNotNull('metadata')->get()->contains(
            fn (AuditLog $log) => str_contains(json_encode($log->metadata), 'password'),
        ));
    }

    public function test_google_unknown_users_are_never_auto_registered(): void
    {
        Config::set('services.google', [
            'client_id' => 'dummy-client',
            'client_secret' => 'dummy-secret',
            'redirect' => 'http://localhost/auth/google/callback',
        ]);
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'unknown-google-subject',
            'email' => 'unknown.google@kpone.test',
            'email_verified' => true,
        ]));

        $before = User::query()->count();
        $this->get(route('google.callback'))->assertRedirect(route('login'));

        $this->assertSame($before, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'unknown.google@kpone.test']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.google.denied']);
    }

    public function test_public_staff_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_technical_administrators_do_not_receive_clinical_permissions(): void
    {
        $user = $this->createStaff('technical_admin');

        $this->assertFalse($user->can('clinical.records.view.organisation'));
        $this->assertFalse($user->getAllPermissions()->contains(
            fn ($permission) => str_starts_with($permission->name, 'clinical.'),
        ));
    }

    /** @param list<Branch> $branches */
    private function createStaff(
        ?string $role = null,
        array $branches = [],
        ?string $email = null,
    ): User {
        $user = User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Dummy Staff '.Str::random(6),
            'email' => $email ?? 'dummy.'.Str::uuid().'@kpone.test',
        ]);
        $department = Department::query()->where('organisation_id', $this->organisation->id)->firstOrFail();
        $profile = StaffProfile::query()->forceCreate([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'job_title' => 'Dummy Position',
        ]);

        foreach ($branches as $index => $branch) {
            app(BranchAssignmentService::class)->create($profile, $branch, [
                'is_primary' => $index === 0,
                'assignment_type' => 'permanent',
                'valid_from' => now()->subDay()->toDateString(),
            ]);
        }

        if ($role) {
            $user->assignRole($role);
        }

        return $user->refresh()->load('staffProfile');
    }
}
