<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-13 (owner decision 2026-11-11, "agree all"): inventory cost (supplier prices now; stock value, COGS and the
 * journal CSV later) is seen only by a Director or Finance Officer. Additive: creates the permission, grants only
 * what is missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'inventory.cost.view.organisation' => ['director', 'finance_officer'],
        ];
        foreach ($grants as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Inventory cost grant skipped missing role', ['role' => $holder, 'permission' => $name]);

                    continue;
                }
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
