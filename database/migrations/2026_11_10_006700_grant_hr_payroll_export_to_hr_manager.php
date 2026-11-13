<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PDPA 14 (owner, 2026-11-11, "go with b", relayed by B.E.R.N.I.S.S): the HR Manager may also download the payroll bank
 * file and the accountant file. Additive only: a missing role is logged and skipped, and nothing is ever revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Literal names: this historical migration must not follow later code changes.
        $permission = Permission::firstOrCreate(['name' => 'hr.payroll.export.organisation', 'guard_name' => 'web']);
        $role = Role::where('name', 'hr_manager')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('HR payroll export permission role missing', ['role' => 'hr_manager', 'permission' => $permission->name]);
        } elseif (! $role->permissions()->whereKey($permission->id)->exists()) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Additive grants are retained.
};
