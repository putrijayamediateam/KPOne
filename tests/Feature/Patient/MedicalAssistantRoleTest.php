<?php

namespace Tests\Feature\Patient;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use App\Domain\Access\WorkspaceLandingService;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPermissions;
use App\Domain\Clinical\Triage\TriagePermissions;
use App\Domain\DepartmentSpace\PharmacySpace;
use App\Domain\HR\OpenShifts\Services\OpenShiftService;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
use App\Domain\Identity\Services\StaffProvisioningService;
use App\Domain\Identity\Services\StaffRoleService;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * MA-1a (owner decision 23 Nov 2026, "MA: approve all recommended, start MA-1a"; MA-ROLE-DESIGN sections 3, 7 and 9):
 * the Medical Assistant role, its three treatment-room permissions, the catalogue rule, the role-name sites and who may
 * provision it. No screen in this slice. Synthetic data only.
 */
class MedicalAssistantRoleTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    private const MA = 'medical_assistant';

    private const MIGRATION = 'migrations/2026_11_06_100320_add_medical_assistant_role.php';

    /** The approved list: the CA's 72 permissions on 23 Nov 2026 plus the two the CA does not hold. */
    private const EXPECTED = [
        'allergies.record_otc.branch',
        'attendance_documents.print.branch',
        'attendance_documents.view.branch',
        'billing.adjustments.add.branch',
        'billing.bill_to.manage.branch',
        'billing.bill_to.view.branch',
        'billing.build.branch',
        'billing.finalize.branch',
        'billing.notes.add.branch',
        'billing.notes.view.branch',
        'billing.print.branch',
        'billing.view.branch',
        'branch_context.switch.branch',
        'branches.view.branch',
        'compliance.fridge.record.branch',
        'coverage.approve.branch',
        'coverage.propose.branch',
        'dashboard.view.own',
        'dispensary.complete.branch',
        'dispensary.labels.print.branch',
        'dispensary.otc.create.branch',
        'dispensary.return_to_doctor.branch',
        'dispensary.start.branch',
        'dispensary.update.branch',
        'dispensary.view.branch',
        'hr.appraisal.view.own',
        'hr.attendance.clock.own',
        'hr.claims.submit.own',
        'hr.credentials.view.own',
        'hr.incidents.report.own',
        'hr.leave.request.own',
        'hr.memos.read.own',
        'hr.messages.receive.own',
        'hr.payslips.view.own',
        'hr.records.view.own',
        'hr.roster.view.own',
        'hr.sops.acknowledge.own',
        'insights.view.organisation',
        'inventory.purchase_requests.create.branch',
        'inventory.stock_requests.create.branch',
        'inventory.transfer.branch',
        'inventory.transfers.receive.branch',
        'inventory.usage.record.branch',
        'inventory.view.branch',
        'medical_certificates.print.branch',
        'medical_certificates.view.branch',
        'outstanding.request.branch',
        'outstanding.view.branch',
        'patients.create.organisation',
        'patients.merge.propose.organisation',
        'patients.search.organisation',
        'patients.update.organisation',
        'patients.view.organisation',
        'payments.add.branch',
        'payments.reconcile.branch',
        'profile.view.own',
        'public_intakes.review.branch',
        'queue.call_hold.clear.branch',
        'queue.call_hold.set.branch',
        'queue.enter.branch',
        'queue.treatment_call.branch',
        'queue.treatment_progress.set.branch',
        'queue.view.branch',
        'staff.view.branch',
        'staff.view.own',
        'treatment_room.record.branch',
        'treatment_room.view.branch',
        'visits.cancel.branch',
        'visits.complete.branch',
        'visits.create.branch',
        'visits.history.view.branch',
        'visits.triage.record.branch',
        'visits.update.branch',
        'visits.view.branch',
    ];

    /** @return list<string> */
    private function held(string $role): array
    {
        $permissions = array_values(array_unique(PermissionCatalogue::roles()[$role]));
        sort($permissions);

        return $permissions;
    }

    /** @return list<string> */
    private function holders(string $permission): array
    {
        return collect(PermissionCatalogue::roles())
            ->filter(fn (array $permissions): bool => in_array($permission, $permissions, true))
            ->keys()->sort()->values()->all();
    }

    public function test_the_medical_assistant_holds_exactly_the_approved_list_and_no_authority_over_people_or_access(): void
    {
        $this->assertSame(self::EXPECTED, $this->held(self::MA));
        foreach (self::EXPECTED as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission), $permission);
            $this->assertContains($permission, PermissionCatalogue::all(), $permission);
        }
        $this->assertSame(PermissionCatalogue::MEDICAL_ASSISTANT_ROLE, self::MA);
        $this->assertSame($this->held(self::MA), Role::findByName(self::MA)->permissions()->pluck('name')->sort()->values()->all());
    }

    public function test_the_medical_assistant_holds_every_permission_the_ca_holds_so_the_two_cannot_drift(): void
    {
        $this->assertSame([], array_values(array_diff($this->held('ca'), $this->held(self::MA))), 'A permission granted to the CA must reach the Medical Assistant too.');
        $this->assertSame(
            [TreatmentRoomPermissions::TREATMENT_CALL_BRANCH, TreatmentRoomPermissions::RECORD_BRANCH],
            array_values(array_diff($this->held(self::MA), $this->held('ca'))),
        );
    }

    public function test_the_medical_assistant_never_gets_clinical_ordering_doctor_call_or_people_authority(): void
    {
        $held = $this->held(self::MA);
        foreach ($held as $permission) {
            foreach (['treatment_plans.', 'encounters.', 'problems.', 'consultations.', 'referrals.', 'staff.manage', 'access.', 'audit.', 'pricing.', 'prices.', 'catalogue.settings'] as $prefix) {
                $this->assertStringStartsNotWith($prefix, $permission);
            }
        }
        foreach ([
            'allergies.update.own', 'services.confirm.own', 'queue.call.own', 'queue.call.branch', 'queue.call_hold.override.own',
            'medical_certificates.issue.own', 'attendance_documents.issue.own', 'branches.manage.organisation',
            'clinician_credentials.manage.organisation', 'medicines.manage.organisation',
        ] as $never) {
            $this->assertNotContains($never, $held, $never);
        }
    }

    public function test_the_treatment_room_and_triage_permissions_have_exactly_the_approved_holders(): void
    {
        $this->assertSame(['ca_supervisor', self::MA], $this->holders(TreatmentRoomPermissions::RECORD_BRANCH));
        $this->assertSame(['ca_supervisor', self::MA], $this->holders(TreatmentRoomPermissions::TREATMENT_CALL_BRANCH));
        $this->assertSame(['ca', 'ca_supervisor', self::MA], $this->holders(TreatmentRoomPermissions::VIEW_BRANCH));
        $this->assertSame(['ca', 'ca_supervisor', self::MA], $this->holders(TriagePermissions::RECORD_BRANCH));
        foreach (array_keys(TreatmentRoomPermissions::grants()) as $permission) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission), $permission);
            $this->assertTrue(Permission::query()->where('name', $permission)->exists(), $permission);
        }
        // The Director keeps no clinical-content read of the record (owner decision 7).
        $this->assertNotContains(TreatmentRoomPermissions::VIEW_BRANCH, $this->held('director'));
    }

    public function test_only_a_director_can_provision_a_medical_assistant(): void
    {
        $authority = app(StaffAuthorityService::class);
        $this->assertTrue($authority->canAssignRoles($this->actor('director'), [self::MA]));
        foreach (array_diff(array_keys(PermissionCatalogue::roles()), ['director', 'ca_supervisor', self::MA]) as $role) {
            $this->assertFalse($authority->canAssignRoles($this->actor($role), [self::MA]), "{$role} must not assign the Medical Assistant role");
        }

        $subject = app(StaffProvisioningService::class)->provision($this->actor('director'), $this->provisioning('ma.director'));
        $this->assertTrue($subject->hasRole(self::MA));
        $this->assertSame(1, $subject->roles()->count());
    }

    /**
     * MA-ROLE-DESIGN section 7: with owner decision 3, the CA Supervisor holds every Medical Assistant permission, so
     * canAssignRoles alone would say yes. It still provisions and changes no one, because it holds neither
     * staff.manage.organisation nor access.manage.organisation; the Medical Assistant cannot raise itself either.
     */
    public function test_a_ca_supervisor_or_a_medical_assistant_still_cannot_provision_or_grant_the_role(): void
    {
        $authority = app(StaffAuthorityService::class);
        $supervisor = $this->actor('ca_supervisor');
        $this->assertTrue($authority->canAssignRoles($supervisor, [self::MA]), 'The CA Supervisor holds every MA permission (owner decision 3).');
        $this->assertFalse($supervisor->can('staff.manage.organisation'));
        $this->assertFalse($supervisor->can('access.manage.organisation'));

        $users = User::query()->count();
        $this->expectRefusal(fn () => app(StaffProvisioningService::class)->provision($supervisor, $this->provisioning('ma.supervisor')));
        $this->assertSame($users, User::query()->count());

        $ca = $this->actor('ca');
        $this->expectRefusal(fn () => app(StaffRoleService::class)->sync($ca, [self::MA], $supervisor));
        $this->assertFalse($ca->refresh()->hasRole(self::MA));

        $assistant = $this->actor(self::MA);
        $this->expectRefusal(fn () => app(StaffRoleService::class)->sync($assistant, [self::MA, 'ca_supervisor'], $assistant));
        $this->assertSame([self::MA], $assistant->refresh()->getRoleNames()->all());
    }

    public function test_hr_and_technical_admin_may_administer_an_existing_medical_assistant_but_never_assign_the_role(): void
    {
        $authority = app(StaffAuthorityService::class);
        $assistant = $this->actor(self::MA);
        foreach (['director', 'technical_admin', 'hr_manager'] as $role) {
            $this->assertTrue($authority->canManage($this->actor($role), $assistant), "{$role} administers an MA account");
        }
        foreach (['technical_admin', 'hr_manager'] as $role) {
            $this->assertFalse($authority->canAssignRoles($this->actor($role), [self::MA]), "{$role} must not assign or restore the role");
        }
        foreach (PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS as $permission) {
            $this->assertFalse($assistant->can($permission), "The MA administers no one ({$permission}).");
        }
    }

    public function test_the_medical_assistant_lands_on_registration_and_works_the_whole_otc_dispensary_flow(): void
    {
        $assistant = $this->actor(self::MA);
        $this->assertSame('registration.index', app(WorkspaceLandingService::class)->routeName($assistant));

        $f = $this->fixture();
        $f['ca'] = $assistant;
        $f['visit'] = $this->register($assistant, $this->patient());
        $case = $this->open($f);
        $this->assertSame($assistant->id, $case->current_handler_user_id);
        app(DispensaryService::class)->addItem($assistant, $case, $this->addPayload($f, $case, '3.000'));
        $this->confirmAllergy($f, $case);
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $this->complete($f, $case)->status);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'dispense')->count());

        $this->selectBranch($assistant);
        $this->get('/registration')->assertOk();
        $this->get('/dispensary/'.$case->public_id)->assertOk();
        $this->assertNotNull(app(PharmacySpace::class)->toDispense($assistant), 'The Pharmacy space shows the MA the cases to dispense.');
    }

    public function test_the_medical_assistant_is_a_human_role_for_kpone_hr_with_its_own_roster_line(): void
    {
        $this->assertContains(self::MA, RosterPermissions::HUMAN_ROLES);
        $this->assertSame('Medical Assistant', RosterRules::ROLE_LABELS[self::MA]);
        $this->assertSame(['Medical Assistant', 'Medical Assistants'], RosterRules::STAFFING_ROLES[self::MA]);
        $this->assertSame(['CA', 'CAs'], RosterRules::STAFFING_ROLES['ca'], 'The MA never counts towards the CA minimum (owner decision 8).');
        $this->assertSame([], array_values(array_diff(array_keys(RosterRules::ROLE_LABELS), RosterPermissions::HUMAN_ROLES)));
    }

    public function test_the_migration_is_additive_idempotent_and_never_removes(): void
    {
        $migration = require database_path(self::MIGRATION);
        Role::query()->where('name', self::MA)->delete();
        Permission::query()->whereIn('name', array_keys(TreatmentRoomPermissions::grants()))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration->up();
        $migration->up();
        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertEqualsCanonicalizing(self::EXPECTED, Role::findByName(self::MA)->permissions()->pluck('name')->all());
        foreach (TreatmentRoomPermissions::grants() as $permission => $holders) {
            foreach ($holders as $role) {
                $this->assertTrue(Role::findByName($role)->hasPermissionTo($permission), "{$role} holds {$permission}");
            }
        }
        $this->assertFalse(Role::findByName('ca')->hasPermissionTo(TreatmentRoomPermissions::RECORD_BRANCH));

        $extra = Permission::query()->firstOrCreate(['name' => 'reports.sales.export.organisation', 'guard_name' => 'web']);
        Role::findByName(self::MA)->givePermissionTo($extra);
        $before = Role::findByName('ca_supervisor')->permissions()->count();
        $migration->up();
        $this->assertTrue(Role::findByName(self::MA)->hasPermissionTo($extra), 'The migration never revokes.');
        $this->assertSame($before, Role::findByName('ca_supervisor')->permissions()->count());
    }

    public function test_the_migration_logs_and_skips_a_missing_existing_role(): void
    {
        $migration = require database_path(self::MIGRATION);
        Role::findByName('ca')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::spy();

        $migration->up();

        Log::shouldHaveReceived('warning')->once();
        $this->assertNull(Role::query()->where('name', 'ca')->first());
        $this->assertTrue(Role::findByName(self::MA)->hasPermissionTo(TreatmentRoomPermissions::RECORD_BRANCH));
    }

    public function test_the_hr_role_checks_accept_the_medical_assistant_and_the_three_role_one_roles(): void
    {
        $definition = fn (string $name): string => (string) DB::scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$name]);
        foreach (['hr_memo_roles_role', 'hr_sop_audiences_role', 'hr_credential_type_roles_role'] as $constraint) {
            foreach ([...RosterPermissions::HUMAN_ROLES] as $role) {
                $this->assertStringContainsString("'{$role}'", $definition($constraint), "{$constraint} accepts {$role}");
            }
            $this->assertStringNotContainsString("'queue_display'", $definition($constraint));
            $this->assertStringNotContainsString("'locum'", $definition($constraint));
        }
        $open = $definition('hr_open_shifts_shape');
        $this->assertStringContainsString("'medical_assistant'", $open);
        $this->assertStringContainsString("'on_call'", $open, 'HR-20 on-call stays allowed.');
        $this->assertSame(array_keys(OpenShiftService::ROLES), array_values(array_filter(['resident_doctor', 'ca', 'medical_assistant', 'other'], fn (string $r): bool => str_contains($open, "'{$r}'"))));

        $migration = require database_path('migrations/2026_11_23_100100_allow_medical_assistant_in_hr_role_checks.php');
        $migration->down();
        $this->assertStringNotContainsString("'medical_assistant'", $definition('hr_memo_roles_role'));
        $this->assertStringNotContainsString("'medical_assistant'", $definition('hr_open_shifts_shape'));
        $migration->up();
        $this->assertStringContainsString("'store_admin'", $definition('hr_sop_audiences_role'));
        $this->assertStringContainsString("'medical_assistant'", $definition('hr_open_shifts_shape'));
    }

    public function test_an_open_shift_needing_a_medical_assistant_is_saved(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', $this->branch->timezone));
        $hr = $this->actor('hr_manager');
        $this->selectBranch($hr);

        app(OpenShiftService::class)->post($hr, [
            'branch_id' => $this->branch->id, 'date' => '2026-11-17', 'start_time' => '08:00', 'end_time' => '14:00', 'break_minutes' => '30',
            'session_type' => 'morning', 'role_needed' => self::MA, 'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame(1, DB::table('hr_open_shifts')->where('role_needed', self::MA)->count());
    }

    /**
     * Drift rule (MA-ROLE-DESIGN section 7): the catalogue copies the CA's list to the MA, but an existing database only
     * gets a new grant through a migration. A grant migration added after MA-1a that names 'ca' must name the MA too.
     */
    public function test_a_later_grant_migration_that_names_the_ca_also_names_the_medical_assistant(): void
    {
        $offenders = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            if (strcmp(basename($path), '2026_11_23_100100_allow_medical_assistant_in_hr_role_checks.php') <= 0) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (str_contains($source, 'givePermissionTo') && str_contains($source, "'ca'") && ! str_contains($source, "'medical_assistant'") && ! str_contains($source, 'MEDICAL_ASSISTANT_ROLE')) {
                $offenders[] = basename($path);
            }
        }
        $this->assertSame([], $offenders, 'Grant the permission to medical_assistant as well (MA-1a drift rule).');
    }

    /** @return array<string, mixed> */
    private function provisioning(string $local): array
    {
        return [
            'name' => 'Synthetic Medical Assistant', 'email' => $local.'.'.Str::lower(Str::random(6)).'@kpone.test',
            'credential_strategy' => 'google_only', 'department_id' => Department::query()->where('organisation_id', $this->branch->organisation_id)->value('id'),
            'staff_number' => null, 'job_title' => 'Medical Assistant', 'is_active' => true, 'roles' => [self::MA],
            'assignments' => [[
                'branch_id' => $this->branch->id, 'assignment_type' => 'permanent', 'is_primary' => true,
                'valid_from' => now()->toDateString(), 'valid_until' => null,
            ]],
        ];
    }

    private function expectRefusal(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the action to be refused.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
