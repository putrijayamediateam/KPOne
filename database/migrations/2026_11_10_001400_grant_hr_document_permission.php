<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** HR-4b: additive grant only. A missing role is logged and skipped; nothing is ever revoked. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Literal names: this historical migration must not follow later code changes.
        $permission = Permission::firstOrCreate(['name' => 'hr.documents.view.organisation', 'guard_name' => 'web']);
        foreach (['director', 'hr_manager'] as $holder) {
            $role = Role::where('name', $holder)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('HR documents permission role missing', ['role' => $holder]);

                continue;
            }
            if (! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Additive grants are retained.
};
