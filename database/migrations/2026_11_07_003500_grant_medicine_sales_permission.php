<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-44 (owner, P9: "the medicine sales page with profit: Director, Finance Officer"): only the Director and the
 * Finance Officer open the medicine sales page. Additive: grants only what is missing, skips a missing role and never
 * revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'inventory.medicine_sales.view.organisation', 'guard_name' => 'web']);
        foreach (['director', 'finance_officer'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Medicine sales grant skipped missing role', ['role' => $name, 'permission' => $permission->name]);
            } elseif (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
