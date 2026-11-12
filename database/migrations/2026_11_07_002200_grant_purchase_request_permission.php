<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-18 (owner decision 2026-11-12, "agree purchase requests"): a CA or CA Supervisor asks for stock at their
 * branch with a purchase request. Additive: grants only what is
 * missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory.purchase_requests.create.branch' => ['ca', 'ca_supervisor']] as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Purchase request grant skipped missing role', ['role' => $holder, 'permission' => $name]);

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
