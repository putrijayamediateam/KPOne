<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** ROLE-1 follow-up (owner, 22 Nov 2026): the Branch Manager may view and download the aggregate branch reports. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::where('name', 'branch_manager')->where('guard_name', 'web')->first();
        if ($role === null) {
            Log::warning('Role branch_manager is missing; the aggregate report grants were skipped.');

            return;
        }
        foreach (['reports.sales.export.organisation', 'reports.payments.export.organisation', 'reports.panel_summary.view.branch', 'reports.panel_summary.export.branch'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if (! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
