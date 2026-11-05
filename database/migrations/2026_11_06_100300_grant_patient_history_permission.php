<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** REG-4 (owner decision 2026-11-05): patient change history for CA Supervisor and Director. Additive, never revokes. */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'patients.history.view.organisation', 'guard_name' => 'web']);
        foreach (['ca_supervisor', 'director'] as $holder) {
            $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Patient history grant skipped missing role', ['role' => $holder]);

                continue;
            }
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
