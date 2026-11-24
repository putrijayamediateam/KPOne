<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-30 (owner decisions P6 and P7, round 2): Director and Finance Officer set and see every sales target; a Resident
 * Doctor sees only their own. Additive: grants only what is missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    private const GRANTS = [
        'sales_targets.manage.organisation' => ['director', 'finance_officer'],
        'sales_targets.view.own' => ['resident_doctor'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Sales target grant skipped missing role', ['role' => $roleName, 'permission' => $name]);
                } elseif (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
