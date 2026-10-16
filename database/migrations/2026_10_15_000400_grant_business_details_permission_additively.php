<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate(['name' => 'business_details.manage.organisation', 'guard_name' => 'web']);
        foreach (['director', 'administrator'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Business details migration skipped a missing role.', ['role' => $name]);

                continue;
            }
            if (! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
