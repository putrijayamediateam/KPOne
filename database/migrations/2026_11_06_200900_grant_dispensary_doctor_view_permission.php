<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DSP-26 (owner decision 24 Nov 2026, "pilih (a) lihat sahaja"): the Resident Doctor sees the Dispensary pages at their
 * active branch, view only. Additive: creates the permission and grants only what is missing; a missing role is logged
 * and skipped; nothing is revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate(['name' => 'dispensary.view_readonly.branch', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'resident_doctor')->where('guard_name', 'web')->first();
        if ($role === null) {
            Log::warning('DSP-26: role resident_doctor is missing; dispensary.view_readonly.branch was not granted.');
        } elseif (! $role->permissions()->whereKey($permission->id)->exists()) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
