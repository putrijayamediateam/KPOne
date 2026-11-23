<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-32 (owner decision 2026-11-22, "approve the Branch Manager cash closing view permission"): the Branch Manager
 * opens the Daily and Monthly cash closing pages read only. Additive: grants only what is missing, skips a missing role
 * and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'payments.cash_closing.view.branch', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'branch_manager')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('Cash closing view grant skipped missing role', ['role' => 'branch_manager']);
        } elseif (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
