<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-31 (owner item 32 and P8; owner answer 1A, 24 Nov 2026): the Director and Finance overview, for the Director and
 * the Finance Officer only. Additive: grants only what is missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'insights.overview.view.organisation', 'guard_name' => 'web']);
        foreach (['director', 'finance_officer'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Director and Finance overview grant skipped missing role', ['role' => $roleName]);
            } elseif (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
