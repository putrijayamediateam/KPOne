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

        $permission = Permission::query()->firstOrCreate(['name' => 'panel.claims.patient_identity.view.branch', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'panel_officer')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('Panel Claims patient identity permission migration skipped missing role.', ['role' => 'panel_officer']);
        } elseif (! $role->permissions()->whereKey($permission->id)->exists()) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
