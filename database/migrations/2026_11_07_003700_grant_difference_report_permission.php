<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-45 (owner, P10, 25 Nov 2026: "Lulus nota + kebenaran"): report a difference on a stock line. CA, CA Supervisor,
 * Store Admin, Branch Manager and Medical Assistant (the CA → Medical Assistant rule). Additive: grants only what is
 * missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'inventory.differences.report.branch', 'guard_name' => 'web']);
        foreach (['ca', 'ca_supervisor', 'store_admin', 'branch_manager', 'medical_assistant'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Difference report grant skipped missing role', ['role' => $name, 'permission' => $permission->name]);
            } elseif (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
