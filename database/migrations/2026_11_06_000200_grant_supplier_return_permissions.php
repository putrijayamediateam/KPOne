<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-7 owner decision 2026-11-05: a CA Supervisor requests a supplier return; a Finance Officer or Director
 * approves it, never the requester. Additive: creates missing permissions, grants only what is missing,
 * skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'inventory.supplier_returns.request.branch' => ['ca_supervisor'],
            'inventory.supplier_returns.approve.branch' => ['finance_officer', 'director'],
        ];
        foreach ($grants as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Supplier return grant skipped missing role', ['role' => $holder, 'permission' => $name]);

                    continue;
                }
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
