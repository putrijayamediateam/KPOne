<?php

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * R1-02: the migration that ships PermissionCatalogue changes without a manual
 * `db:seed` step. VisitTestCase::setUp() already runs `migrate` (which includes
 * this migration) and then seeds KPOneReferenceSeeder for every test in the
 * suite, so the migration's ordinary "freshly migrated, then seeded" path is
 * exercised by the entire test suite already. These tests instead target the
 * scenarios that path alone does not cover: a database migrated without ever
 * being seeded, running the migration a second time, a role holding a grant
 * outside the catalogue, and a catalogue role absent from the database.
 */
class PermissionCatalogueMigrationTest extends ClinicalTestCase
{
    private const MIGRATION_PATH = 'database/migrations/2026_09_22_000100_sync_permission_catalogue_additively.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION_PATH);
    }

    public function test_migrate_alone_grants_a_missing_catalogue_permission_and_the_doctor_sees_on_hold(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);

        // Simulate a database that was migrated but never seeded for this
        // permission: delete it (cascades role_has_permissions/model_has_permissions).
        Permission::query()->where('name', 'consultations.hold.own')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($doctor->fresh()->can('consultations.hold.own'));

        $this->migration()->up();

        $this->assertTrue(
            Permission::query()->where('name', 'consultations.hold.own')->where('guard_name', 'web')->exists(),
        );
        $this->assertTrue(Role::query()->where('name', 'resident_doctor')->firstOrFail()
            ->hasPermissionTo('consultations.hold.own'));

        $this->selectBranch($doctor);
        $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor->fresh(), $visit);
        $this->assertTrue($detail['hold']['canHold']);
    }

    public function test_running_the_migration_after_the_seeder_creates_no_duplicate_permission_or_grant(): void
    {
        $permissionsBefore = Permission::query()->count();
        $grantsBefore = DB::table('role_has_permissions')->count();

        $this->migration()->up();

        $this->assertSame($permissionsBefore, Permission::query()->count());
        $this->assertSame($grantsBefore, DB::table('role_has_permissions')->count());
    }

    public function test_running_the_migration_twice_changes_nothing_on_the_second_run(): void
    {
        $this->migration()->up();
        $permissionsAfterFirst = Permission::query()->count();
        $grantsAfterFirst = DB::table('role_has_permissions')->count();

        $this->migration()->up();

        $this->assertSame($permissionsAfterFirst, Permission::query()->count());
        $this->assertSame($grantsAfterFirst, DB::table('role_has_permissions')->count());
    }

    public function test_a_permission_granted_outside_the_catalogue_is_never_removed(): void
    {
        Permission::query()->firstOrCreate(['name' => 'synthetic.outside.catalogue', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'resident_doctor')->firstOrFail();
        $role->givePermissionTo('synthetic.outside.catalogue');
        $this->assertNotContains('synthetic.outside.catalogue', PermissionCatalogue::all());

        $this->migration()->up();

        $this->assertTrue($role->fresh()->hasPermissionTo('synthetic.outside.catalogue'));
    }

    public function test_a_catalogue_role_absent_from_the_database_is_not_created_and_is_logged(): void
    {
        Role::query()->where('name', 'marketing')->delete();
        $this->assertTrue(Role::query()->where('name', 'marketing')->doesntExist());
        Log::spy();

        $this->migration()->up();

        $this->assertTrue(Role::query()->where('name', 'marketing')->doesntExist());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context) => $context['role'] === 'marketing')
            ->atLeast()->once();
    }
}
