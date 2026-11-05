<?php

namespace Tests;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Features;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\IsolatedTestStorage;

abstract class TestCase extends BaseTestCase
{
    /**
     * CI-SEED-ONCE: RefreshDatabase seeds the reference data once, when it builds the schema.
     * Each test then runs inside a transaction that is rolled back, so it starts from the same
     * committed reference data that the per-test seeding used to recreate (about 0.8 s each).
     */
    protected bool $seed = true;

    protected string $seeder = KPOneReferenceSeeder::class;

    public function createApplication()
    {
        IsolatedTestStorage::beforeBootstrap();
        $app = parent::createApplication();
        IsolatedTestStorage::configure($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->restoreEmptyCommittedSchemaForCommittingTests();
        $this->withoutVite();
    }

    /**
     * Tests without RefreshDatabase (the PostgreSQL contention tests) commit their own fixtures
     * and were written against a committed schema with no reference data. When the seeded schema
     * built by RefreshDatabase is in place, rebuild it unseeded for them; the next RefreshDatabase
     * test rebuilds the seeded schema.
     */
    private function restoreEmptyCommittedSchemaForCommittingTests(): void
    {
        $traits = class_uses_recursive(static::class);
        if (isset($traits[RefreshDatabase::class]) || isset($traits[DatabaseTransactions::class])) {
            return;
        }
        if (! RefreshDatabaseState::$migrated && ! $this->committedRoleGrantsRemain()) {
            return;
        }

        if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
            throw new RuntimeException('Could not restore the unseeded test schema.');
        }
        RefreshDatabaseState::$migrated = false;
    }

    /**
     * CI-PAR-1: committing tests that seed the reference data themselves leave every seeded role and its grants
     * committed without RefreshDatabase noticing. A later committing test would then find, for example, the seeded
     * resident_doctor role still granting a permission it revokes directly, so its outcome would depend on test
     * order. All catalogue roles present with grants means that state is present; a role or grant a committing test
     * creates for itself (and its next test may rely on) does not trigger a rebuild.
     */
    private function committedRoleGrantsRemain(): bool
    {
        if (! str_starts_with(static::class, 'Tests\\Feature\\')) {
            return false;
        }
        if (! Schema::hasTable('role_has_permissions') || ! Schema::hasTable('roles')) {
            return false;
        }

        $roles = array_keys(PermissionCatalogue::roles());
        $present = Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->count();

        return $present === count($roles) && DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('roles.guard_name', 'web')
            ->whereIn('roles.name', $roles)
            ->exists();
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            foreach (config('filesystems.disks', []) as $disk) {
                IsolatedTestStorage::assertContained($disk['root'] ?? '');
            }
        }
        parent::tearDown();
    }

    /** @param string|list<string> $permissions */
    protected function grantPermissions(User $user, string|array $permissions): User
    {
        foreach ((array) $permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * Seeds the reference data only when the committed schema does not already hold all of it,
     * for example after a test rebuilt the schema without seeding. Seeding is idempotent.
     */
    protected function ensureReferenceData(): void
    {
        if (! $this->referenceDataIsComplete()) {
            $this->seed(KPOneReferenceSeeder::class);
        }
    }

    private function referenceDataIsComplete(): bool
    {
        $organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->first();
        if ($organisation === null) {
            return false;
        }

        $permissions = PermissionCatalogue::all();
        $roles = PermissionCatalogue::roles();
        $grants = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('roles.guard_name', 'web')
            ->whereIn('roles.name', array_keys($roles))
            ->count();

        return Branch::query()->where('organisation_id', $organisation->id)
            ->whereIn('code', ['CHERAS', 'SUNGAI_BESI', 'PUCHONG'])->count() === 3
            && Department::query()->where('organisation_id', $organisation->id)->count() >= 9
            && Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions)->count() === count($permissions)
            && Role::query()->where('guard_name', 'web')->whereIn('name', array_keys($roles))->count() === count($roles)
            && $grants === array_sum(array_map(fn (array $granted): int => count(array_unique($granted)), $roles));
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
