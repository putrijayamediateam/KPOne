<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** HR-16a: the Director turns the location check on or off for a branch (HR-PLAN 22.3). Additive only; nothing is revoked. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Literal names: this historical migration must not follow later code changes.
        $permission = Permission::firstOrCreate(['name' => 'hr.attendance.location.manage.organisation', 'guard_name' => 'web']);
        $role = Role::where('name', 'director')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('HR location check permission role missing', ['role' => 'director']);
        } elseif (! $role->permissions()->whereKey($permission->id)->exists()) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Additive grants are retained.
};
