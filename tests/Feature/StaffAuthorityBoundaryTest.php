<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Identity\Services\StaffAdministrationService;
use App\Domain\Identity\Services\StaffProvisioningService;
use App\Domain\Identity\Services\StaffRoleService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * AC-01: authority over people and access is a declared set, not a substring of
 * permission names. These tests pin the set, the administrability of every role,
 * and the boundary that administering an account never lets an actor grant a
 * role it could not assign itself.
 */
class StaffAuthorityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const DECLARED = ['staff.manage.organisation', 'branches.manage.organisation', 'access.manage.organisation'];

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

    public function test_the_authority_set_is_pinned_and_no_other_permission_is_administrative(): void
    {
        $this->assertSame(self::DECLARED, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);

        foreach (PermissionCatalogue::all() as $permission) {
            $this->assertSame(
                in_array($permission, self::DECLARED, true),
                PermissionCatalogue::isAdministrativeAuthority($permission),
                $permission,
            );
        }

        foreach ([
            'medicines.manage.organisation', 'clinical_services.manage.organisation', 'inventory.references.manage.organisation',
            'inventory.suppliers.manage.organisation', 'inventory.reorder.manage.branch', 'pricing.references.manage.organisation',
            'payment_methods.manage.organisation', 'patients.identifiers.manage.organisation',
            'public_checkin_links.manage.organisation', 'public_checkin_links.manage.branch', 'prices.publish.organisation',
        ] as $operational) {
            if (in_array($operational, PermissionCatalogue::all(), true)) {
                $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($operational), $operational);
            }
        }
    }

    public function test_director_can_provision_and_then_administer_every_other_role(): void
    {
        $director = $this->staff('director');

        foreach (array_diff(array_keys(PermissionCatalogue::roles()), ['director']) as $role) {
            $subject = app(StaffProvisioningService::class)->provision($director, [
                'name' => 'Synthetic '.$role, 'email' => Str::lower($role).'.provisioned@kpone.test',
                'credential_strategy' => 'google_only', 'department_id' => $this->department->id,
                'staff_number' => null, 'job_title' => 'Synthetic', 'is_active' => true, 'roles' => [$role],
                'assignments' => [[
                    'branch_id' => $this->cheras->id, 'assignment_type' => 'permanent', 'is_primary' => true,
                    'valid_from' => now()->toDateString(), 'valid_until' => null,
                ]],
            ]);
            $this->assertTrue($subject->hasRole($role), $role);

            $this->administerAllFour($director, $subject, [$role], $role);
        }
    }

    public function test_technical_admin_can_administer_every_role_without_administrative_authority(): void
    {
        $technical = $this->staff('technical_admin');
        $emptySet = collect(PermissionCatalogue::roles())
            ->filter(fn (array $permissions) => collect($permissions)->doesntContain(fn (string $p) => PermissionCatalogue::isAdministrativeAuthority($p)))
            ->keys();

        $this->assertContains('ca_supervisor', $emptySet->all());
        $this->assertContains('finance_officer', $emptySet->all());

        foreach ($emptySet as $role) {
            $subject = $this->staff($role);
            $this->administerAllFour($technical, $subject, ['marketing'], $role);
            $this->assertTrue($subject->refresh()->hasRole('marketing'), $role);
        }
    }

    public function test_technical_admin_can_demote_but_never_promote_and_the_demotion_is_audited_with_the_actor(): void
    {
        $technical = $this->staff('technical_admin');
        $supervisor = $this->staff('ca_supervisor');

        app(StaffRoleService::class)->sync($supervisor, ['marketing'], $technical);

        $this->assertTrue($supervisor->refresh()->hasRole('marketing'));
        $this->assertFalse($supervisor->hasRole('ca_supervisor'));
        $detached = AuditLog::query()->where('event', 'access.role.detached')->where('subject_id', $supervisor->id)->sole();
        $this->assertSame($technical->id, $detached->actor_user_id);
        $this->assertContains('ca_supervisor', $detached->metadata['roles']);
        $attached = AuditLog::query()->where('event', 'access.role.attached')->where('subject_id', $supervisor->id)->where('actor_user_id', $technical->id)->sole();
        $this->assertSame($technical->id, $attached->actor_user_id);
        $this->assertContains('marketing', $attached->metadata['roles']);

        // Restoring the role is a promotion: canAssignRoles refuses it, and nothing changes.
        $auditCount = AuditLog::query()->count();
        try {
            app(StaffRoleService::class)->sync($supervisor, ['ca_supervisor'], $technical);
            $this->fail('technical_admin re-assigned ca_supervisor.');
        } catch (AuthorizationException) {
            $this->assertTrue($supervisor->refresh()->hasRole('marketing'));
            $this->assertFalse($supervisor->hasRole('ca_supervisor'));
            $this->assertSame($auditCount, AuditLog::query()->count());
        }
        $this->assertFalse(app(StaffAuthorityService::class)->canAssignRoles($technical, ['ca_supervisor']));

        // Only a director can restore it.
        $director = $this->staff('director');
        app(StaffRoleService::class)->sync($supervisor, ['ca_supervisor'], $director);
        $this->assertTrue($supervisor->refresh()->hasRole('ca_supervisor'));
    }

    public function test_technical_admin_can_demote_a_peer_technical_admin(): void
    {
        $technical = $this->staff('technical_admin');
        $peer = $this->staff('technical_admin');

        $this->assertTrue($technical->can('manageRoles', $peer));
        app(StaffRoleService::class)->sync($peer, ['marketing'], $technical);

        $this->assertTrue($peer->refresh()->hasRole('marketing'));
        $this->assertFalse($peer->hasRole('technical_admin'));
        $this->assertSame($technical->id, AuditLog::query()->where('event', 'access.role.detached')->where('subject_id', $peer->id)->sole()->actor_user_id);
    }

    public function test_the_director_is_protected_on_both_the_manage_and_the_demotion_path(): void
    {
        $director = $this->staff('director');

        foreach (['technical_admin', 'hr_manager'] as $actorRole) {
            $actor = $this->staff($actorRole);
            foreach (['update', 'manageAccess', 'manageRoles', 'manageStatus'] as $ability) {
                $this->assertFalse($actor->can($ability, $director), "{$actorRole} {$ability}");
            }
            $this->assertFalse(app(StaffAuthorityService::class)->canAssignRoles($actor, ['director']));

            try {
                app(StaffRoleService::class)->sync($director, ['marketing'], $actor);
                $this->fail("{$actorRole} demoted a director.");
            } catch (AuthorizationException) {
                $this->assertTrue($director->refresh()->hasRole('director'));
                $this->assertFalse($director->hasRole('marketing'));
            }
            try {
                app(StaffAdministrationService::class)->setActive($director, false, $actor);
                $this->fail("{$actorRole} deactivated a director.");
            } catch (AuthorizationException) {
                $this->assertTrue($director->refresh()->is_active);
            }
        }
    }

    public function test_peer_hr_manager_can_edit_and_deactivate_but_cannot_change_roles_or_branches(): void
    {
        $hr = $this->staff('hr_manager');
        $peer = $this->staff('hr_manager');

        $this->assertTrue($hr->can('update', $peer));
        $this->assertTrue($hr->can('manageStatus', $peer));
        $this->assertFalse($hr->can('manageAccess', $peer));
        $this->assertFalse($hr->can('manageRoles', $peer));

        app(StaffAdministrationService::class)->updateProfile($peer, $this->profileAttributes($peer, 'Edited by peer HR'), $hr);
        $this->assertSame('Edited by peer HR', $peer->refresh()->name);
        app(StaffAdministrationService::class)->setActive($peer, false, $hr);
        $this->assertFalse($peer->refresh()->is_active);

        $this->assertRolesAndBranchesRefused($hr, $peer, 'hr_manager peer');
    }

    public function test_hr_manager_can_edit_and_deactivate_a_ca_supervisor_but_not_change_its_roles_or_branches(): void
    {
        $hr = $this->staff('hr_manager');
        $supervisor = $this->staff('ca_supervisor');

        app(StaffAdministrationService::class)->updateProfile($supervisor, $this->profileAttributes($supervisor, 'Edited by HR'), $hr);
        app(StaffAdministrationService::class)->setActive($supervisor, false, $hr);
        $this->assertFalse($supervisor->refresh()->is_active);

        $this->assertRolesAndBranchesRefused($hr, $supervisor, 'hr_manager -> ca_supervisor');
    }

    public function test_operational_manage_permissions_alone_give_no_authority_over_anyone(): void
    {
        $operational = collect(PermissionCatalogue::all())
            ->filter(fn (string $p) => str_contains($p, '.manage.') && ! PermissionCatalogue::isAdministrativeAuthority($p))
            ->values()->all();
        $this->assertNotEmpty($operational);

        $actor = $this->staff('ca');
        $actor->givePermissionTo($operational);

        foreach (['director', 'technical_admin', 'hr_manager', 'ca', 'ca_supervisor', 'marketing'] as $targetRole) {
            $target = $this->staff($targetRole);
            foreach (['update', 'manageAccess', 'manageRoles', 'manageStatus'] as $ability) {
                $this->assertFalse($actor->refresh()->can($ability, $target), "{$ability} on {$targetRole}");
            }
        }
    }

    public function test_a_direct_staff_manage_grant_makes_an_account_administrable_only_by_actors_holding_it(): void
    {
        $subject = $this->staff('ca');
        $subject->givePermissionTo('staff.manage.organisation');
        $subject = $subject->refresh();

        foreach (['director', 'technical_admin', 'hr_manager'] as $holder) {
            $actor = $this->staff($holder);
            $this->assertTrue($actor->can('update', $subject), $holder);
            $this->assertTrue($actor->can('manageStatus', $subject), $holder);
        }

        // Holds access.manage.organisation but not staff.manage.organisation: cannot cover the subject's authority.
        $accessOnly = $this->staff('marketing');
        $accessOnly->givePermissionTo('access.manage.organisation');
        $accessOnly = $accessOnly->refresh();
        foreach (['update', 'manageAccess', 'manageRoles', 'manageStatus'] as $ability) {
            $this->assertFalse($accessOnly->can($ability, $subject), $ability);
        }
        $this->assertFalse($this->staff('ca_supervisor')->can('manageStatus', $subject));
    }

    public function test_self_management_is_refused_for_all_four_abilities(): void
    {
        foreach (['director', 'technical_admin', 'hr_manager'] as $role) {
            $self = $this->staff($role);
            foreach (['update', 'manageAccess', 'manageRoles', 'manageStatus'] as $ability) {
                $this->assertFalse($self->can($ability, $self), "{$role} {$ability}");
            }

            $this->expectFailure(fn () => app(StaffAdministrationService::class)->updateProfile($self, $this->profileAttributes($self, 'Self edit'), $self), "{$role} profile");
            $this->expectFailure(fn () => app(StaffAdministrationService::class)->setActive($self, false, $self), "{$role} status");
            if ($role !== 'hr_manager') {
                $this->expectFailure(fn () => app(StaffRoleService::class)->sync($self, ['marketing'], $self), "{$role} roles");
                $this->expectFailure(fn () => app(BranchAssignmentService::class)->create($self->staffProfile, $this->puchong, $this->temporaryAssignment(), $self), "{$role} branches");
            }
            $this->assertTrue($self->refresh()->hasRole($role));
        }
    }

    /**
     * @param  list<string>  $syncTo
     */
    private function administerAllFour(User $actor, User $subject, array $syncTo, string $label): void
    {
        $this->assertTrue($actor->can('update', $subject), $label);
        $this->assertTrue($actor->can('manageAccess', $subject), $label);
        $this->assertTrue($actor->can('manageRoles', $subject), $label);
        $this->assertTrue($actor->can('manageStatus', $subject), $label);

        app(BranchAssignmentService::class)->create($subject->staffProfile, $this->puchong, $this->temporaryAssignment(), $actor);
        $this->assertSame(2, $subject->staffProfile->branchAssignments()->count(), $label);

        app(StaffAdministrationService::class)->updateProfile($subject, $this->profileAttributes($subject, 'Administered '.$label), $actor);
        $this->assertSame('Administered '.$label, $subject->refresh()->name);

        app(StaffRoleService::class)->sync($subject, $syncTo, $actor);
        $this->assertEqualsCanonicalizing($syncTo, $subject->refresh()->getRoleNames()->all(), $label);

        app(StaffAdministrationService::class)->setActive($subject, false, $actor);
        $this->assertFalse($subject->refresh()->is_active, $label);
    }

    private function assertRolesAndBranchesRefused(User $actor, User $subject, string $label): void
    {
        $roles = $subject->getRoleNames()->all();
        $assignments = $subject->staffProfile->branchAssignments()->count();

        try {
            app(StaffRoleService::class)->sync($subject, ['marketing'], $actor);
            $this->fail("{$label} changed roles.");
        } catch (AuthorizationException) {
            $this->assertEqualsCanonicalizing($roles, $subject->refresh()->getRoleNames()->all(), $label);
        }
        try {
            app(BranchAssignmentService::class)->create($subject->staffProfile, $this->puchong, $this->temporaryAssignment(), $actor);
            $this->fail("{$label} changed branch assignments.");
        } catch (AuthorizationException) {
            $this->assertSame($assignments, $subject->staffProfile->branchAssignments()->count(), $label);
        }
    }

    private function expectFailure(callable $callback, string $label): void
    {
        try {
            $callback();
            $this->fail("Expected refusal: {$label}.");
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, mixed> */
    private function temporaryAssignment(): array
    {
        return [
            'assignment_type' => 'temporary', 'is_primary' => false,
            'valid_from' => now()->toDateString(), 'valid_until' => now()->addMonth()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function profileAttributes(User $subject, string $name): array
    {
        return [
            'name' => $name, 'email' => $subject->email, 'department_id' => $this->department->id,
            'staff_number' => null, 'job_title' => 'Synthetic',
        ];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create([
            'organisation_id' => $this->organisation->id,
            'name' => 'Synthetic '.Str::random(8),
            'email' => 'synthetic.'.Str::uuid().'@kpone.test',
        ]);
        $profile = StaffProfile::query()->forceCreate([
            'user_id' => $user->id, 'department_id' => $this->department->id, 'job_title' => 'Synthetic Test Role',
        ]);
        $user->assignRole($role);
        StaffBranchAssignmentBootstrapper::create($profile, $this->cheras, [
            'assignment_type' => 'permanent', 'is_primary' => true, 'valid_from' => now()->subDay()->toDateString(),
        ]);

        return $user->refresh()->load('staffProfile');
    }
}
