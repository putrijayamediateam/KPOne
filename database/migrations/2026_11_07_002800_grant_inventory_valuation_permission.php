<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-28 (approved by B.E.R.N.I.S.S for the owner, 2026-11-15): only a Director opens the stock value, cost of goods
 * sold, margin and losses report. Additive: grants only what is missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'inventory.valuation.view.organisation', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'director')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('Valuation grant skipped missing role', ['role' => 'director', 'permission' => $permission->name]);
        } elseif (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
