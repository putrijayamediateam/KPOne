<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Item 4 (owner decision P4 of 20-21 Nov 2026): the Director's read-only view of a consultation. Additive: creates the
 * permission and grants only what is missing; a missing role is skipped; nothing is revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate(['name' => 'consultations.view_readonly.organisation', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'director')->where('guard_name', 'web')->first();
        if ($role && ! $role->permissions()->whereKey($permission->id)->exists()) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
