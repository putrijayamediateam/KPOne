<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-37 (owner decision 24 Nov 2026): the Resident Doctor sees the bill of their own patient's visit, view only.
 * Additive: grants only what is missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'billing.view_readonly.branch', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'resident_doctor')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('BIL-37 doctor billing view grant skipped missing role', ['role' => 'resident_doctor']);
        } elseif (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
