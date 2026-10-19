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
        foreach ([
            'medical_certificates.issue.own' => ['resident_doctor'],
            'medical_certificates.view.own' => ['resident_doctor'],
            'medical_certificates.view.branch' => ['ca', 'ca_supervisor'],
            'medical_certificates.print.branch' => ['resident_doctor', 'ca', 'ca_supervisor'],
        ] as $name => $roles) {
            $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($roles as $name) {
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
