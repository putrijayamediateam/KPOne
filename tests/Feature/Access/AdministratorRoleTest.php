<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Access\StaffAuthorityService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Visit\VisitTestCase;

/**
 * ADM-01: the clinic manager's Administrator role. It manages settings and catalogues and sees no
 * patient, clinical, billing-approval, Panel Claims or people/access data.
 */
class AdministratorRoleTest extends VisitTestCase
{
    private const SETTINGS = 'catalogue.settings.manage.organisation';

    public function test_the_role_holds_the_agreed_manage_permissions_and_none_of_the_excluded_authority(): void
    {
        $held = PermissionCatalogue::roles()['administrator'];

        foreach ([
            'medicines.manage.organisation', 'clinical_services.manage.organisation', self::SETTINGS,
            'pricing.references.manage.organisation', 'prices.publish.organisation', 'panels.manage.organisation',
            'payment_methods.manage.organisation', 'inventory.view.branch', 'inventory.references.manage.organisation',
            'inventory.suppliers.manage.organisation', 'inventory.reorder.manage.branch',
            'public_checkin_links.manage.organisation', 'queue_display.manage.organisation',
            'staff.view.organisation', 'branches.view.organisation', 'insights.view.organisation',
        ] as $permission) {
            $this->assertContains($permission, $held, "administrator should hold {$permission}");
        }

        foreach ($held as $permission) {
            foreach (['patients.', 'visits.', 'queue.view', 'queue.call', 'queue.enter', 'encounters.', 'allergies.', 'treatment_plans.', 'dispensary.', 'billing.', 'payments.', 'coverage.', 'outstanding.', 'invoices.', 'panel.', 'access.', 'audit.', 'system_events.', 'public_intakes.'] as $prefix) {
                $this->assertStringStartsNotWith($prefix, $permission, "administrator must not hold {$permission}");
            }
            $this->assertNotContains($permission, PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
        }
    }

    public function test_exactly_director_administrator_finance_and_panel_officers_manage_catalogue_settings(): void
    {
        $holders = collect(PermissionCatalogue::roles())
            ->filter(fn (array $permissions) => in_array(self::SETTINGS, $permissions, true))->keys()->sort()->values()->all();
        $this->assertSame(['administrator', 'director', 'finance_officer', 'panel_officer'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(self::SETTINGS));
    }

    public function test_only_the_director_can_provision_the_role_and_it_holds_no_people_authority(): void
    {
        $authority = app(StaffAuthorityService::class);

        $this->assertTrue($authority->canAssignRoles($this->actor('director'), ['administrator']));
        foreach (['technical_admin', 'hr_manager', 'ca_supervisor', 'finance_officer'] as $role) {
            $this->assertFalse($authority->canAssignRoles($this->actor($role), ['administrator']), "{$role} must not provision an administrator");
        }

        $administrator = $this->actor('administrator');
        $this->assertTrue($authority->canManage($this->actor('director'), $administrator));
        $this->assertTrue($authority->canManage($this->actor('technical_admin'), $administrator));
        $this->assertTrue($authority->canManage($this->actor('hr_manager'), $administrator));
        // canManage only compares administrative authority; the role also lacks the permissions that open staff administration at all.
        $this->assertFalse($administrator->can('staff.manage.organisation'));
        $this->assertFalse($administrator->can('access.manage.organisation'));
        $this->assertFalse($authority->canManage($administrator, $this->actor('technical_admin')));
    }

    public function test_the_administrator_reaches_the_settings_screens_and_is_refused_patient_billing_and_people_screens(): void
    {
        $administrator = $this->actor('administrator');
        $this->selectBranch($administrator);

        // The staff directory is view-only for the role: it can look but not create or change accounts.
        foreach (['medicines.index', 'clinical-services.index', 'pricing.index', 'payment-methods.index', 'panel-settings.index', 'inventory.index', 'staff.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['registration.index', 'panel-claims.index', 'panel-invoices.index', 'audit-logs.index', 'staff.create'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
    }

    public function test_the_migration_creates_the_role_and_grants_the_settings_permission_additively(): void
    {
        $role = Role::findByName('administrator');
        $role->delete();
        foreach (['director', 'finance_officer', 'panel_officer'] as $name) {
            Role::findByName($name)->revokePermissionTo(self::SETTINGS);
        }
        Permission::query()->where('name', self::SETTINGS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require base_path('database/migrations/2026_10_14_000300_add_administrator_role_and_catalogue_settings_permission.php');
        $migration->up();
        $migration->up();

        $created = Role::findByName('administrator');
        $this->assertEqualsCanonicalizing(PermissionCatalogue::roles()['administrator'], $created->permissions->pluck('name')->all());
        foreach (['director', 'finance_officer', 'panel_officer'] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo(self::SETTINGS));
        }
        $this->assertFalse(Role::findByName('ca')->hasPermissionTo(self::SETTINGS));
        $this->assertFalse(Role::findByName('technical_admin')->hasPermissionTo(self::SETTINGS));
    }
}
