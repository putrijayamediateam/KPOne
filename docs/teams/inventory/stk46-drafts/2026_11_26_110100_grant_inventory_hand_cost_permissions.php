<?php

// Archived reviewed artifact: owner approved exact role grants and no-op down on 26 Nov 2026.
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const GRANTS = [
        'inventory.cost.enter.organisation' => ['finance_officer', 'store_admin'],
        'inventory.cost.check.organisation' => ['finance_officer', 'store_admin', 'director'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                $role?->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally preserve the owner-approved additive permission grants on rollback.
        // Any future revocation requires a separately reviewed forward migration.
    }
};
