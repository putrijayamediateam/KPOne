<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffAdministrationService;
use App\Domain\Identity\Services\StaffDirectoryService;
use App\Domain\Identity\Services\StaffRoleService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class StaffAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Organisation $organisation;

    private Branch $cheras;

    private Branch $puchong;

    private Branch $sungaiBesi;

    private Department $clinical;

    private Department $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(KPOneReferenceSeeder::class);
        $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->firstOrFail();
        $this->cheras = Branch::query()->where('code', 'CHERAS')->firstOrFail();
        $this->puchong = Branch::query()->where('code', 'PUCHONG')->firstOrFail();
        $this->sungaiBesi = Branch::query()->where('code', 'SUNGAI_BESI')->firstOrFail();
        $this->clinical = Department::query()->where('name', 'Clinical')->firstOrFail();
        $this->finance = Department::query()->where('name', 'Finance')->firstOrFail();
    }

    public function test_authorised_profile_and_department_changes_are_explicit_and_audited(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($actor)->patch(route('staff.update', $subject), [
            'name' => 'Updated Synthetic Staff',
            'email' => 'updated.synthetic@kpone.test',
            'department_id' => $this->finance->id,
            'staff_number' => 'SYN-UPDATED',
            'job_title' => 'Updated Synthetic Role',
        ])->assertRedirect(route('staff.show', $subject));

        $subject->refresh()->load('staffProfile');
        $this->assertSame('Updated Synthetic Staff', $subject->name);
        $this->assertSame('updated.synthetic@kpone.test', $subject->email);
        $this->assertSame($this->finance->id, $subject->staffProfile->department_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'staff.identity.updated', 'subject_id' => $subject->id, 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'staff.department.changed', 'subject_id' => $subject->id, 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'staff.profile.updated', 'subject_id' => $subject->id, 'actor_user_id' => $actor->id]);
    }

    public function test_profile_request_rejects_protected_identity_and_access_attributes(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras]);
        $before = $subject->only(['organisation_id', 'google_subject', 'is_active']);
        $assignment = $subject->staffProfile->branchAssignments()->firstOrFail();

        $this->actingAs($actor)->patch(route('staff.update', $subject), [
            'name' => 'Attempted Change',
            'email' => 'attempted.change@kpone.test',
            'department_id' => $this->finance->id,
            'organisation_id' => $this->organisation->id + 999,
            'google_subject' => 'attacker-subject',
            'password' => 'untrusted-password',
            'is_active' => false,
            'roles' => ['director'],
            'branch_id' => $this->puchong->id,
        ])->assertSessionHasErrors(['organisation_id', 'google_subject', 'password', 'is_active', 'roles', 'branch_id']);

        $subject->refresh();
        $this->assertSame($before, $subject->only(['organisation_id', 'google_subject', 'is_active']));
        $this->assertSame($this->cheras->id, $assignment->fresh()->branch_id);
        $this->assertTrue($subject->hasRole('ca'));
    }

    public function test_role_changes_preserve_multiple_roles_and_audit_attach_and_detach(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($actor)->put(route('staff.roles.update', $subject), [
            'roles' => ['finance_officer', 'marketing'],
        ])->assertRedirect(route('staff.show', $subject));

        $this->assertTrue($subject->fresh()->hasAllRoles(['finance_officer', 'marketing']));
        $this->assertFalse($subject->fresh()->hasRole('ca'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'access.role.attached', 'subject_id' => $subject->id, 'actor_user_id' => $actor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'access.role.detached', 'subject_id' => $subject->id, 'actor_user_id' => $actor->id]);
    }

    public function test_direct_role_service_audits_the_explicit_actor_without_an_authenticated_session(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras]);
        $this->assertGuest();
        $before = collect(['access.role.attached', 'access.role.detached'])
            ->mapWithKeys(fn (string $event): array => [$event => AuditLog::query()
                ->where('event', $event)
                ->where('subject_type', User::class)
                ->where('subject_id', $subject->id)
                ->count()]);

        app(StaffRoleService::class)->sync($subject, ['finance_officer'], $actor);

        foreach (['access.role.attached', 'access.role.detached'] as $event) {
            $logs = AuditLog::query()
                ->where('event', $event)
                ->where('subject_type', User::class)
                ->where('subject_id', $subject->id)
                ->orderBy('id')
                ->get();

            $this->assertCount($before[$event] + 1, $logs, "{$event} should add exactly one audit row.");
            $this->assertSame($actor->id, $logs->last()?->actor_user_id);
            $this->assertSame(
                [$event === 'access.role.attached' ? 'finance_officer' : 'ca'],
                $logs->last()?->roleNames(),
            );
        }

        $recentAudit = collect(app(StaffDirectoryService::class)->detail($actor, $subject)['recentAudit']);
        $this->assertTrue($recentAudit->contains(
            fn (array $entry): bool => $entry['event'] === 'access.role.attached'
                && $entry['roleNames'] === ['finance_officer'],
        ));

        $this->actingAs($actor)->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AuditLogs/Index')
                ->where('logs.data', fn ($logs): bool => collect($logs)->every(
                    fn (array $log): bool => ! array_key_exists('metadata', $log),
                ) && collect($logs)->contains(
                    fn (array $log): bool => $log['event'] === 'access.role.attached'
                        && $log['roleNames'] === ['finance_officer'],
                )));
    }

    public function test_administrative_staff_update_cannot_be_used_for_self_service(): void
    {
        $actor = $this->createStaff('director', [$this->cheras]);

        $this->actingAs($actor)->patch(route('staff.update', $actor), [
            'name' => 'Administrative Self Edit',
            'email' => $actor->email,
            'department_id' => $this->finance->id,
        ])->assertForbidden();

        $this->assertNotSame('Administrative Self Edit', $actor->fresh()->name);

        $this->patch(route('profile.update'), [
            'name' => 'Self Service Edit',
            'email' => $actor->email,
        ])->assertRedirect(route('profile.edit'));

        $this->assertSame('Self Service Edit', $actor->fresh()->name);
    }

    public function test_runtime_branch_and_status_mutations_require_an_explicit_non_nullable_actor(): void
    {
        $subject = $this->createStaff('ca', [$this->cheras]);
        $branchService = app(BranchAssignmentService::class);
        $statusService = app(StaffAdministrationService::class);
        $assignmentsBefore = $subject->staffProfile->branchAssignments()->count();

        $branchActor = new \ReflectionParameter([$branchService, 'create'], 'actor');
        $statusActor = new \ReflectionParameter([$statusService, 'setActive'], 'actor');
        $this->assertFalse($branchActor->allowsNull());
        $this->assertFalse($branchActor->isOptional());
        $this->assertFalse($statusActor->allowsNull());
        $this->assertFalse($statusActor->isOptional());

        try {
            (new \ReflectionMethod($branchService, 'create'))->invokeArgs($branchService, [
                $subject->staffProfile,
                $this->puchong,
                [
                    'assignment_type' => 'permanent',
                    'is_primary' => false,
                    'valid_from' => now()->toDateString(),
                ],
            ]);
            $this->fail('Branch mutation unexpectedly accepted an omitted actor.');
        } catch (\ArgumentCountError) {
            $this->addToAssertionCount(1);
        }

        try {
            (new \ReflectionMethod($statusService, 'setActive'))->invokeArgs($statusService, [$subject, false]);
            $this->fail('Status mutation unexpectedly accepted an omitted actor.');
        } catch (\ArgumentCountError) {
            $this->addToAssertionCount(1);
        }

        $unauthorisedActor = $this->createStaff('ca', [$this->cheras]);

        try {
            $branchService->create($subject->staffProfile, $this->puchong, [
                'assignment_type' => 'permanent',
                'is_primary' => false,
                'valid_from' => now()->toDateString(),
            ], $unauthorisedActor);
            $this->fail('Branch mutation unexpectedly accepted an unauthorised actor.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $statusService->setActive($subject, false, $unauthorisedActor);
            $this->fail('Status mutation unexpectedly accepted an unauthorised actor.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($assignmentsBefore, $subject->staffProfile->branchAssignments()->count());
        $this->assertTrue($subject->fresh()->is_active);
    }

    public function test_unauthorised_role_mutation_is_forbidden(): void
    {
        $actor = $this->createStaff('ca', [$this->cheras]);
        $subject = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($actor)->put(route('staff.roles.update', $subject), [
            'roles' => ['finance_officer'],
        ])->assertForbidden();

        $this->assertTrue($subject->fresh()->hasRole('ca'));
    }

    public function test_branch_scoped_user_cannot_manage_staff_outside_their_branch(): void
    {
        $actor = $this->createStaff('ca', [$this->cheras]);
        $subject = $this->createStaff('ca', [$this->puchong]);

        $this->actingAs($actor)->patch(route('staff.update', $subject), [
            'name' => 'Unauthorised Change',
            'email' => $subject->email,
            'department_id' => $this->finance->id,
        ])->assertForbidden();

        $this->assertNotSame('Unauthorised Change', $subject->fresh()->name);
    }

    public function test_technical_admin_cannot_mutate_director_roles_access_or_status(): void
    {
        $actor = $this->createStaff('technical_admin');
        $director = $this->createStaff('director', [$this->cheras]);

        $this->actingAs($actor)->put(route('staff.roles.update', $director), ['roles' => ['ca']])->assertForbidden();
        $this->actingAs($actor)->patch(route('staff.status.update', $director), ['is_active' => false])->assertForbidden();
        $this->actingAs($actor)->post(route('staff.branch-assignments.store', $director), [
            'branch_id' => $this->puchong->id,
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
        ])->assertForbidden();

        $this->assertTrue($director->fresh()->is_active);
        $this->assertTrue($director->fresh()->hasRole('director'));
        $this->assertSame(1, $director->staffProfile->branchAssignments()->count());
    }

    public function test_director_can_manage_technical_admin_without_granting_implicit_clinical_access(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('technical_admin', [$this->cheras]);

        $this->actingAs($actor)->patch(route('staff.status.update', $subject), ['is_active' => false])
            ->assertRedirect(route('staff.show', $subject));

        $this->assertFalse($subject->fresh()->is_active);
        $this->assertFalse($subject->fresh()->getAllPermissions()->contains(
            fn ($permission) => str_starts_with($permission->name, 'clinical.'),
        ));
    }

    public function test_role_assignment_checks_actual_role_permissions_for_future_clinical_capabilities(): void
    {
        $actor = $this->createStaff('technical_admin');
        $subject = $this->createStaff('ca', [$this->cheras]);
        $clinicalPermission = Permission::findOrCreate('clinical.records.view.organisation', 'web');
        Role::findByName('marketing')->givePermissionTo($clinicalPermission);

        $this->actingAs($actor)->put(route('staff.roles.update', $subject), ['roles' => ['marketing']])
            ->assertForbidden();

        $this->assertTrue($subject->fresh()->hasRole('ca'));
        $this->assertFalse($subject->fresh()->can('clinical.records.view.organisation'));
    }

    public function test_branch_assignment_lifecycle_uses_domain_service_and_audits_each_change(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras]);

        $this->actingAs($actor)->post(route('staff.branch-assignments.store', $subject), [
            'branch_id' => $this->puchong->id,
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
        ])->assertRedirect(route('staff.show', $subject));

        $assignment = $subject->staffProfile->branchAssignments()->where('branch_id', $this->puchong->id)->firstOrFail();
        $this->actingAs($actor)->patch(route('staff.branch-assignments.update', [$subject, $assignment]), [
            'assignment_type' => 'permanent',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => null,
        ])->assertRedirect(route('staff.show', $subject));
        $this->actingAs($actor)->post(route('staff.branch-assignments.primary', [$subject, $assignment]))
            ->assertRedirect(route('staff.show', $subject));

        $previous = $subject->staffProfile->branchAssignments()->where('branch_id', $this->cheras->id)->firstOrFail();
        $this->actingAs($actor)->patch(route('staff.branch-assignments.end', [$subject, $previous]), [
            'valid_until' => now()->toDateString(),
        ])->assertRedirect(route('staff.show', $subject));

        $this->assertSame(1, $subject->staffProfile->branchAssignments()->effectiveAt()->where('is_primary', true)->count());
        foreach ([
            'staff.branch_assignment.created',
            'staff.branch_assignment.updated',
            'staff.branch_assignment.primary.demoted',
            'staff.branch_assignment.primary.promoted',
            'staff.branch_assignment.ended',
        ] as $event) {
            $this->assertDatabaseHas('audit_logs', ['event' => $event, 'actor_user_id' => $actor->id]);
        }
    }

    public function test_active_primary_assignment_cannot_be_ended_before_primary_is_changed(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras]);
        $primary = $subject->staffProfile->branchAssignments()->firstOrFail();

        $this->actingAs($actor)->patch(route('staff.branch-assignments.end', [$subject, $primary]), [
            'valid_until' => now()->toDateString(),
        ])->assertSessionHasErrors('assignment');

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertNull($primary->fresh()->valid_until);
    }

    public function test_temporary_assignment_requires_end_date_and_cannot_be_an_expiring_active_primary(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras]);

        try {
            app(BranchAssignmentService::class)->create($subject->staffProfile, $this->puchong, [
                'assignment_type' => 'temporary',
                'is_primary' => false,
                'valid_from' => now()->toDateString(),
            ], $actor);
            $this->fail('A temporary assignment without an end date was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('valid_until', $exception->errors());
        }

        $temporary = app(BranchAssignmentService::class)->create($subject->staffProfile, $this->puchong, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
        ], $actor);

        try {
            app(BranchAssignmentService::class)->changePrimary($subject->staffProfile, $temporary, $actor);
            $this->fail('An expiring assignment became primary for an active account.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignment', $exception->errors());
        }

        $this->assertFalse($temporary->fresh()->is_primary);
        $this->assertTrue($subject->staffProfile->branchAssignments()->where('branch_id', $this->cheras->id)->firstOrFail()->is_primary);
    }

    public function test_future_and_expired_assignments_do_not_grant_branch_access(): void
    {
        $subject = $this->createStaff('ca', [$this->cheras]);
        $expired = StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->puchong, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subWeek()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ]);
        $future = StaffBranchAssignmentBootstrapper::create($subject->staffProfile, $this->sungaiBesi, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->addDay()->toDateString(),
            'valid_until' => now()->addWeek()->toDateString(),
        ]);

        $access = app(BranchAccessService::class);
        $this->assertFalse($access->hasEffectiveAssignment($subject, $expired->branch));
        $this->assertFalse($access->hasEffectiveAssignment($subject, $future->branch));
    }

    public function test_deactivation_blocks_login_and_existing_session_and_reactivation_is_audited(): void
    {
        $actor = $this->createStaff('director');
        $subject = $this->createStaff('ca', [$this->cheras], 'lifecycle.staff@kpone.test');

        $this->actingAs($subject)->get(route('dashboard'))->assertOk();
        app(StaffAdministrationService::class)->setActive($subject, false, $actor);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $subject->email, 'password' => 'password']);
        $this->assertGuest();

        $this->actingAs($actor)->patch(route('staff.status.update', $subject), ['is_active' => true])
            ->assertRedirect(route('staff.show', $subject));
        $this->assertTrue($subject->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'staff.deactivated', 'subject_id' => $subject->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'staff.activated', 'subject_id' => $subject->id]);
    }

    public function test_self_deactivation_is_forbidden_and_reactivation_requires_current_primary_branch(): void
    {
        $actor = $this->createStaff('director', [$this->cheras]);

        $this->actingAs($actor)->patch(route('staff.status.update', $actor), ['is_active' => false])->assertForbidden();
        $this->assertTrue($actor->fresh()->is_active);

        $inactive = $this->createStaff('ca', [], null, false);
        $this->actingAs($actor)->patch(route('staff.status.update', $inactive), ['is_active' => true])
            ->assertSessionHasErrors('is_active');
        $this->assertFalse($inactive->fresh()->is_active);
    }

    /** @param list<Branch> $branches */
    private function createStaff(
        string $role,
        array $branches = [],
        ?string $email = null,
        bool $active = true,
    ): User {
        $user = User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Synthetic '.Str::random(8),
            'email' => $email ?? 'synthetic.'.Str::uuid().'@kpone.test',
            'is_active' => $active,
            'deactivated_at' => $active ? null : now(),
        ]);
        $profile = StaffProfile::query()->forceCreate([
            'user_id' => $user->id,
            'department_id' => $this->clinical->id,
            'staff_number' => 'SYN-'.Str::upper(Str::random(8)),
            'job_title' => 'Synthetic Test Role',
        ]);

        foreach ($branches as $index => $branch) {
            StaffBranchAssignmentBootstrapper::create($profile, $branch, [
                'assignment_type' => 'permanent',
                'is_primary' => $index === 0,
                'valid_from' => now()->toDateString(),
            ]);
        }

        $user->assignRole($role);

        return $user->refresh()->load('staffProfile');
    }
}
