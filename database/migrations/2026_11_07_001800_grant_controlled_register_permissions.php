<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-24 (owner decisions 2026-11-11, "agree all"): a CA Supervisor, Finance Officer or Director views the
 * controlled-drug register and checks it as a second person. Additive: grants only what is
 * missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory.controlled_register.view.branch' => ['ca_supervisor', 'finance_officer', 'director'], 'inventory.controlled_register.check.branch' => ['ca_supervisor', 'finance_officer', 'director']] as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Controlled register grant skipped missing role', ['role' => $holder, 'permission' => $name]);

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
