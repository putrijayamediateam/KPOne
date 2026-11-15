<?php

namespace Tests\Feature\Access;

use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * UI-1A: the migration that ships the new clinical_services.manage.organisation
 * permission without a manual db:seed step, following the same additive
 * pattern as 2026_09_22_000100_sync_permission_catalogue_additively (see
 * PermissionCatalogueMigrationTest). VisitTestCase::setUp() already runs
 * `migrate` (which includes this migration) and seeds KPOneReferenceSeeder for
 * every test in the suite, so the ordinary "freshly migrated, then seeded"
 * path is exercised throughout. These tests target what that path alone does
 * not cover: a database migrated without the permission present, running the
 * migration again after the seeder, and up -> down -> up idempotency.
 */
class ClinicalServicesPermissionMigrationTest extends ClinicalTestCase
{
    private const MIGRATION_PATH = 'database/migrations/2026_09_25_000100_sync_clinical_services_permission_additively.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION_PATH);
    }

    public function test_fresh_migrate_and_seed_grants_the_permission_to_director_and_ca_supervisor_only(): void
    {
        $this->assertTrue(
            Permission::query()->where('name', ClinicalServiceCatalogueAdministrationService::PERMISSION)->where('guard_name', 'web')->exists(),
        );
        foreach (['director', 'ca_supervisor'] as $roleName) {
            $this->assertTrue(
                Role::query()->where('name', $roleName)->firstOrFail()->hasPermissionTo(ClinicalServiceCatalogueAdministrationService::PERMISSION),
                $roleName,
            );
        }
        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'business_development', 'marketing', 'hr_manager', 'technical_admin'] as $roleName) {
            $this->assertFalse(
                Role::query()->where('name', $roleName)->firstOrFail()->hasPermissionTo(ClinicalServiceCatalogueAdministrationService::PERMISSION),
                $roleName,
            );
        }
    }

    public function test_migrate_alone_grants_a_missing_permission_to_the_authorised_roles(): void
    {
        // Simulate a database migrated before this permission existed: delete it
        // (cascades role_has_permissions/model_has_permissions).
        Permission::query()->where('name', ClinicalServiceCatalogueAdministrationService::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $director = $this->actor('director');
        $this->assertFalse($director->fresh()->can(ClinicalServiceCatalogueAdministrationService::PERMISSION));

        $this->migration()->up();

        $this->assertTrue(
            Permission::query()->where('name', ClinicalServiceCatalogueAdministrationService::PERMISSION)->where('guard_name', 'web')->exists(),
        );
        $this->assertTrue(Role::query()->where('name', 'director')->firstOrFail()->hasPermissionTo(ClinicalServiceCatalogueAdministrationService::PERMISSION));
        $this->assertTrue(Role::query()->where('name', 'ca_supervisor')->firstOrFail()->hasPermissionTo(ClinicalServiceCatalogueAdministrationService::PERMISSION));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($director->fresh()->can(ClinicalServiceCatalogueAdministrationService::PERMISSION));
    }

    public function test_running_the_migration_after_the_seeder_creates_no_duplicate_permission_or_grant(): void
    {
        $permissionsBefore = Permission::query()->count();
        $grantsBefore = DB::table('role_has_permissions')->count();

        $this->migration()->up();

        $this->assertSame($permissionsBefore, Permission::query()->count());
        $this->assertSame($grantsBefore, DB::table('role_has_permissions')->count());
    }

    public function test_up_then_down_then_up_again_is_idempotent_and_down_is_a_noop(): void
    {
        $this->migration()->up();
        $permissionsAfterFirstUp = Permission::query()->count();
        $grantsAfterFirstUp = DB::table('role_has_permissions')->count();

        $this->migration()->down();

        $this->assertSame($permissionsAfterFirstUp, Permission::query()->count());
        $this->assertSame($grantsAfterFirstUp, DB::table('role_has_permissions')->count());
        $this->assertTrue(
            Role::query()->where('name', 'director')->firstOrFail()->hasPermissionTo(ClinicalServiceCatalogueAdministrationService::PERMISSION),
        );

        $this->migration()->up();

        $this->assertSame($permissionsAfterFirstUp, Permission::query()->count());
        $this->assertSame($grantsAfterFirstUp, DB::table('role_has_permissions')->count());
        $this->assertTrue(
            Role::query()->where('name', 'ca_supervisor')->firstOrFail()->hasPermissionTo(ClinicalServiceCatalogueAdministrationService::PERMISSION),
        );
    }
}
