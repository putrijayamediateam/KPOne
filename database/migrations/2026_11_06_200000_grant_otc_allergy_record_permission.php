<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DSP-5 (owner decision 2026-11-05): CA and CA Supervisor may add (never edit or remove) an allergy record for the
 * patient of an OTC Dispensary case. Additive: creates the permission and grants only what is missing; a missing
 * role is skipped; nothing is revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate(['name' => 'allergies.record_otc.branch', 'guard_name' => 'web']);
        foreach (['ca', 'ca_supervisor'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if ($role && ! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
