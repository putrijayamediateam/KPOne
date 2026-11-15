<?php

namespace Tests\Feature\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * FIN-01 (owner decision, 2026-10-08): finance officers, panel officers and directors see invoice lines.
 */
class FinanceLinesMigrationTest extends ClinicalTestCase
{
    private const PERMISSION = 'billing.lines.view.branch';

    private const ROLES = ['finance_officer', 'panel_officer', 'director'];

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_08_000100_grant_finance_officer_billing_lines_additively.php');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return DB::table('roles')
            ->leftJoin('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->groupBy('roles.name')->orderBy('roles.name')
            ->selectRaw('roles.name as name, count(role_has_permissions.permission_id) as c')
            ->pluck('c', 'name')->map(fn ($c) => (int) $c)->all();
    }

    public function test_migration_grants_an_existing_database_only_the_three_roles_and_never_removes(): void
    {
        foreach (self::ROLES as $name) {
            Role::query()->where('name', $name)->firstOrFail()->revokePermissionTo(self::PERMISSION);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($this->actor('finance_officer')->can(self::PERMISSION));
        $before = $this->counts();

        $this->migration()->up();

        $after = $this->counts();
        foreach ($before as $name => $count) {
            $this->assertSame($count + (in_array($name, self::ROLES, true) ? 1 : 0), $after[$name], "{$name} changed unexpectedly");
        }
        $this->assertTrue($this->actor('finance_officer')->can(self::PERMISSION));
        $this->assertTrue($this->actor('director')->can(self::PERMISSION));
        $this->assertTrue($this->actor('panel_officer')->can(self::PERMISSION));
        $this->assertFalse($this->actor('technical_admin')->can(self::PERMISSION));
        $this->assertFalse($this->actor('marketing')->can(self::PERMISSION));

        $this->migration()->up();
        $this->migration()->down();
        $this->migration()->up();
        $this->assertSame($after, $this->counts());
    }

    public function test_migration_creates_a_missing_permission_but_never_a_missing_role(): void
    {
        DB::table('permissions')->where('name', self::PERMISSION)->delete();
        Role::query()->whereIn('name', self::ROLES)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Log::spy();

        $this->migration()->up();

        $this->assertSame(1, DB::table('permissions')->where('name', self::PERMISSION)->count());
        $this->assertTrue(Role::query()->whereIn('name', self::ROLES)->doesntExist());
        Log::shouldHaveReceived('warning')->times(3);
    }
}
