<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Q-2: additive grants only. A missing role is logged and skipped; nothing is ever revoked. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Literal names: this historical migration must not follow later code changes.
        $grants = ['queue.treatment_progress.set.branch' => ['ca', 'ca_supervisor'], 'queue.treatment_progress.set.own' => ['resident_doctor']];
        foreach ($grants as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Treatment progress permission role missing', ['role' => $holder, 'permission' => $name]);

                    continue;
                }
                if (! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Additive grants are retained.
};
