<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-52 (owner, 21 Nov 2026: "A for the bottle check"): only a CA Supervisor or the Director does the blind check of a
 * pack conversion. Additive: grants only what is missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'inventory.pack_conversions.verify.organisation', 'guard_name' => 'web']);
        foreach (['ca_supervisor', 'director'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Pack conversion verify grant skipped missing role', ['role' => $name, 'permission' => $permission->name]);
            } elseif (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
