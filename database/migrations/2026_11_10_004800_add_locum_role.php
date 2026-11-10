<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * HR-15a (HR-PLAN 21.3; B.E.R.N.I.S.S for the owner, 2026-11-10): the narrow `locum` role for a locum's own sign-in.
 * Their own account settings, a choice among their own assigned branches, and their own approved shifts only. The role is new, so it is created here with exactly
 * its permissions; no existing role changes.
 */
return new class extends Migration
{
    /** Literal names: this historical migration must not follow later code changes. */
    private const PERMISSIONS = ['profile.view.own', 'branch_context.switch.branch', 'hr.roster.view.own'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $role = Role::query()->firstOrCreate(['name' => 'locum', 'guard_name' => 'web']);
        $missing = array_values(array_diff(self::PERMISSIONS, $role->permissions()->pluck('name')->all()));
        if ($missing !== []) {
            $role->givePermissionTo($missing);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Roles and grants are retained, as with every additive grant.
};
