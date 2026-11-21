<?php

use App\Domain\Access\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ROLE-1 (owner decisions 20-21 Nov 2026): three new roles, created with exactly their catalogue permissions, and the audit log
 * (read only) for the Finance Officer and the Panel Officer. Additive: `firstOrCreate`, give only what is missing, never revoke,
 * a missing existing role is logged and skipped, `down()` a no-op. No new permission is created (every one already exists).
 */
return new class extends Migration
{
    private const NEW_ROLES = [
        PermissionCatalogue::BRANCH_MANAGER_ROLE,
        PermissionCatalogue::ACCOUNT_ASSISTANT_ROLE,
        PermissionCatalogue::STORE_ADMIN_ROLE,
    ];

    /** Existing roles that gain the audit log (read only). */
    private const AUDIT_HOLDERS = ['finance_officer', 'panel_officer'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $catalogue = PermissionCatalogue::roles();
        foreach (self::NEW_ROLES as $name) {
            $permissions = array_values(array_unique($catalogue[$name]));
            foreach ($permissions as $permission) {
                Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            }

            $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $missing = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        $audit = Permission::query()->firstOrCreate(['name' => 'audit.view.organisation', 'guard_name' => 'web']);
        foreach (self::AUDIT_HOLDERS as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('ROLE-1 migration skipped a missing role.', ['role' => $name]);

                continue;
            }
            if (! $role->permissions()->whereKey($audit->id)->exists()) {
                $role->givePermissionTo($audit);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
