<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PNL-6 (owner decision 2026-11-05): only the Finance Officer and the Director may reverse a wrongly recorded
 * Panel payment. Additive only: never revokes, a missing role is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::query()->firstOrCreate(['name' => 'panel.claim_payments.reverse.branch', 'guard_name' => 'web']);
        foreach (['finance_officer', 'director'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Panel payment reversal permission migration skipped missing role.', ['role' => $name]);

                continue;
            }
            if (! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
