<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * TRI-1e (owner, 24 Nov 2026, "ya semua"): `visits.triage.view.own` for the Resident Doctor, the patient's own doctor's
 * read-only triage view. Additive: a missing role is logged and skipped; nothing is ever revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Literal names: this historical migration must not follow later code changes.
        $permission = Permission::firstOrCreate(['name' => 'visits.triage.view.own', 'guard_name' => 'web']);
        $role = Role::where('name', 'resident_doctor')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('Triage view permission role missing', ['role' => 'resident_doctor']);
        } elseif (! $role->permissions()->whereKey($permission->id)->exists()) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Additive grants are retained.
};
