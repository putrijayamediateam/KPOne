<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-19 (owner decision 2026-11-11, "agree all"): a Finance Officer may approve a purchase order as well as the
 * Director, never one they raised or submitted. Additive: grants only what is missing, skips a missing role and
 * never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'inventory.purchase_orders.approve.organisation', 'guard_name' => 'web']);
        foreach (['director', 'finance_officer'] as $holder) {
            $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Purchase order approval grant skipped missing role', ['role' => $holder]);

                continue;
            }
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
