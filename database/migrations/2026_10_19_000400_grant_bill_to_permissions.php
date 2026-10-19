<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view' => ['ca', 'ca_supervisor', 'finance_officer', 'director', 'panel_officer'], 'manage' => ['ca', 'ca_supervisor', 'finance_officer', 'director']] as $action => $names) {
            $permission = Permission::query()->firstOrCreate(['name' => "billing.bill_to.{$action}.branch", 'guard_name' => 'web']);
            foreach ($names as $name) {
                $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
                if ($role && ! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
