<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * DSP-23 (owner answers 21 Nov 2026: P3, 12 = A, 13 = B). Additive: creates each permission and grants only what is
 * missing; a missing role is skipped; nothing is revoked.
 *
 * - dispensary.doctor_check.own: the attending Resident Doctor verifies or sends back the prepared medicine (P3).
 * - dispensary.doctor_check.override.branch: a CA Supervisor checks instead when the doctor is unavailable (13 = B).
 * - dispensary.settings.manage.branch: the Director and the branch's CA Supervisor turn the switch on or off (12 = A).
 */
return new class extends Migration
{
    private const GRANTS = [
        'dispensary.doctor_check.own' => ['resident_doctor'],
        'dispensary.doctor_check.override.branch' => ['ca_supervisor'],
        'dispensary.settings.manage.branch' => ['director', 'ca_supervisor'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if ($role && ! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
