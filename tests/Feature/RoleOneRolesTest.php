<?php

namespace Tests\Feature;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Visit\VisitTestCase;

/**
 * ROLE-1 (owner decisions 20-21 Nov 2026): Branch Manager, Account Assistant and Store Admin, and the audit log (read only)
 * for the Finance Officer, the Account Assistant and the Panel Officer. Synthetic data only.
 */
class RoleOneRolesTest extends VisitTestCase
{
    private const NEW = ['branch_manager', 'account_assistant', 'store_admin'];

    /** @return array<string, list<string>> */
    private function tables(): array
    {
        return [
            'branch_manager' => [
                'billing.summary.branch',
                'billing.view.branch',
                'branch_context.switch.branch',
                'branches.view.branch',
                'dashboard.view.own',
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
                'inventory.view.branch',
                'outstanding.view.branch',
                'payments.cash_closing.approve.branch',
                // BIL-32 (owner approved 2026-11-22): the cash closing pages, read only.
                'payments.cash_closing.view.branch',
                'profile.view.own',
                'queue.view.branch',
                'reports.daily_summary.export.branch',
                'reports.daily_summary.view.branch',
                'reports.stock_movement.export.branch',
                'reports.stock_movement.view.branch',
                'staff.view.branch',
                'staff.view.own',
                'visits.history.view.branch',
                'visits.view.branch',
            ],
            'account_assistant' => [
                'audit.view.organisation',
                'branch_context.switch.organisation',
                'branches.view.organisation',
                'dashboard.view.own',
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
                'inventory.cost.view.organisation',
                'inventory.supplier_invoices.record.branch',
                'inventory.view.branch',
                'profile.view.own',
                'reports.daily_summary.view.branch',
                'reports.panel_summary.view.branch',
                'reports.stock_movement.view.branch',
                'staff.view.organisation',
                'staff.view.own',
            ],
            'store_admin' => [
                'branch_context.switch.organisation',
                'branches.view.organisation',
                'dashboard.view.own',
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
                'inventory.cost.view.organisation',
                'inventory.purchase_orders.create.organisation',
                'inventory.purchase_requests.create.branch',
                'inventory.receiving.branch',
                'inventory.stock_requests.approve.branch',
                'inventory.stock_reviews.manage.branch',
                'inventory.stocktake.branch',
                'inventory.transfer.organisation',
                'inventory.transfers.dispatch.branch',
                'inventory.view.branch',
                'inventory.warehouse.organisation',
                'profile.view.own',
                'staff.view.branch',
                'staff.view.own',
            ],
        ];
    }

    /** @return list<string> */
    private function held(string $role): array
    {
        $p = array_values(array_unique(PermissionCatalogue::roles()[$role]));
        sort($p);

        return $p;
    }

    public function test_each_new_role_holds_exactly_its_approved_table_and_no_authority_over_people_or_access(): void
    {
        foreach ($this->tables() as $role => $expected) {
            $this->assertSame($expected, $this->held($role), $role);
            foreach ($expected as $permission) {
                $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($permission), "{$role} holds {$permission}");
            }
        }
    }

    public function test_the_money_rules_the_owner_set_are_held_apart(): void
    {
        $manager = $this->held('branch_manager');
        $this->assertContains('payments.cash_closing.approve.branch', $manager);
        foreach (['payments.reconcile.branch', 'payments.refunds.approve.branch', 'payments.refunds.record.branch', 'prices.publish.organisation'] as $permission) {
            $this->assertNotContains($permission, $manager);
        }
        foreach (['account_assistant', 'store_admin'] as $role) {
            foreach (['payments.cash_closing.approve.branch', 'payments.refunds.approve.branch', 'inventory.variances.approve.branch', 'invoices.void.branch'] as $permission) {
                $this->assertNotContains($permission, $this->held($role), "{$role} must not approve");
            }
        }
        $this->assertContains('inventory.supplier_invoices.record.branch', $this->held('account_assistant'));
        $this->assertNotContains('inventory.supplier_invoices.approve.organisation', $this->held('account_assistant'));
        foreach (['visits.view.branch', 'patients.view.organisation', 'dispensary.view.branch'] as $patientAccess) {
            $this->assertNotContains($patientAccess, $this->held('store_admin'));
            $this->assertNotContains($patientAccess, $this->held('account_assistant'));
        }
    }

    public function test_only_the_agreed_roles_may_provision_each_new_role(): void
    {
        $authority = app(StaffAuthorityService::class);
        $director = $this->actor('director');
        foreach (self::NEW as $role) {
            $this->assertTrue($authority->canAssignRoles($director, [$role]), "director may assign {$role}");
            foreach (['ca', 'resident_doctor', 'panel_officer', 'marketing', 'technical_admin', 'administrator'] as $assigner) {
                $this->assertFalse($authority->canAssignRoles($this->actor($assigner), [$role]), "{$assigner} must not assign {$role}");
            }
        }
    }

    public function test_the_new_roles_hold_no_authority_to_administer_anyone(): void
    {
        $authority = app(StaffAuthorityService::class);
        $ca = $this->actor('ca');
        foreach (self::NEW as $role) {
            $actor = $this->actor($role);
            foreach (['staff.manage.organisation', 'access.manage.organisation', 'branches.manage.organisation'] as $permission) {
                $this->assertFalse($actor->can($permission), "{$role} must not hold {$permission}");
            }
            $this->assertFalse($authority->canAssignRoles($actor, ['ca']), "{$role} must not assign roles");
            $this->assertTrue($authority->canManage($this->actor('director'), $actor));
        }
    }

    public function test_refusals_come_from_the_server_not_the_menu(): void
    {
        $store = $this->actor('store_admin');
        $this->selectBranch($store);
        $this->get('/patients')->assertForbidden();
        $this->get('/registration')->assertForbidden();

        $assistant = $this->actor('account_assistant');
        $this->selectBranch($assistant);
        $this->get('/patients')->assertForbidden();
        $this->get('/cash-closings')->assertForbidden();
        $this->get('/access-control')->assertForbidden();

        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $this->assertFalse($manager->can('invoices.void.branch'));
        $this->get('/pay-later-collections')->assertOk();
        $this->get('/access-control')->assertForbidden();
    }

    public function test_the_audit_log_is_read_only_for_finance_the_account_assistant_and_the_panel_officer(): void
    {
        foreach (['finance_officer', 'account_assistant', 'panel_officer', 'director'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->assertContains('audit.view.organisation', $this->held($role));
            $this->get('/audit-logs')->assertOk();
            $this->assertContains('/audit-logs', array_column($this->hubRows(), 'href'), "{$role} sees the Audit logs row");
        }
        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'branch_manager', 'store_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get('/audit-logs')->assertForbidden();
            $this->assertNotContains('/audit-logs', array_column($this->hubRows(), 'href'), "{$role} must not see the Audit logs row");
        }
    }

    /** @return list<array<string, mixed>> */
    private function hubRows(): array
    {
        $page = $this->get('/clinic-management')->assertOk()->viewData('page');
        $rows = [];
        foreach ($page['props']['groups'] ?? [] as $group) {
            foreach ($group['links'] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function test_one_person_can_hold_several_roles_and_gets_the_union_of_their_rights(): void
    {
        $person = $this->actor('hr_manager');
        $person->assignRole('store_admin');
        $person = $person->fresh();
        foreach (['hr_manager', 'store_admin'] as $role) {
            $this->assertTrue($person->hasRole($role));
            foreach ($this->held($role) as $permission) {
                $this->assertTrue($person->can($permission), "{$permission} from {$role}");
            }
        }
        $this->selectBranch($person);
        $this->get('/hr/roster')->assertOk();
        $this->get('/inventory')->assertOk();
        $this->get('/patients')->assertForbidden();
    }

    public function test_the_migration_is_additive_idempotent_and_never_removes(): void
    {
        $migration = require database_path('migrations/2026_11_21_000100_add_branch_manager_account_assistant_and_store_admin_roles.php');
        Role::query()->whereIn('name', self::NEW)->get()->each(fn ($role) => $role->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('finance_officer')->revokePermissionTo('audit.view.organisation');
        Role::findByName('panel_officer')->revokePermissionTo('audit.view.organisation');

        $migration->up();
        $migration->up();
        $migration->down();
        foreach ($this->tables() as $role => $expected) {
            $this->assertEqualsCanonicalizing($expected, Role::findByName($role)->permissions()->pluck('name')->all(), $role);
        }
        $this->assertTrue(Role::findByName('finance_officer')->hasPermissionTo('audit.view.organisation'));
        $this->assertTrue(Role::findByName('panel_officer')->hasPermissionTo('audit.view.organisation'));

        // Never removes: an extra grant on a new role and the existing roles' other rights survive another run.
        $extra = Permission::query()->firstOrCreate(['name' => 'reports.sales.export.organisation', 'guard_name' => 'web']);
        Role::findByName('store_admin')->givePermissionTo($extra);
        $before = Role::findByName('finance_officer')->permissions()->count();
        $migration->up();
        $this->assertTrue(Role::findByName('store_admin')->hasPermissionTo($extra));
        $this->assertSame($before, Role::findByName('finance_officer')->permissions()->count());
    }

    public function test_the_migration_logs_and_skips_a_missing_existing_role(): void
    {
        $migration = require database_path('migrations/2026_11_21_000100_add_branch_manager_account_assistant_and_store_admin_roles.php');
        Role::findByName('panel_officer')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::spy();
        $migration->up();
        Log::shouldHaveReceived('warning')->once();
        $this->assertNull(Role::query()->where('name', 'panel_officer')->first());
    }
}
