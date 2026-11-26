<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-50 and STK-51 (owner, 22 Nov 2026, decision 47(a), confirmed 25 Nov): only the Director approves purchase orders,
 * and only a Finance Officer accepts or rejects a supplier invoice mismatch. Revokes exactly these two grants; nothing
 * is deleted, and decisions already recorded keep their decider. A missing role or permission is skipped.
 * down() gives the two grants back.
 */
return new class extends Migration
{
    private const REVOKE = [
        'inventory.purchase_orders.approve.organisation' => 'finance_officer',
        'inventory.supplier_invoices.approve.branch' => 'director',
    ];

    public function up(): void
    {
        foreach (self::REVOKE as $name => $holder) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first();
            $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
            if (! $permission || ! $role) {
                Log::warning('Approver narrowing skipped a missing role or permission', ['permission' => $name, 'role' => $holder]);

                continue;
            }
            if ($role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::REVOKE as $name => $holder) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
