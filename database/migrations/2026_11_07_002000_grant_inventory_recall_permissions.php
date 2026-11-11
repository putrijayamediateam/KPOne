<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-25 (owner programme 2026-11-11): a Director starts a recall across branches and, for now, only a Director
 * lifts one (never the person who started it; who else may lift waits for the owner). Additive: grants only what is
 * missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory.recalls.manage.organisation' => ['director'], 'inventory.recalls.lift.organisation' => ['director']] as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Recall grant skipped missing role', ['role' => $holder, 'permission' => $name]);

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
