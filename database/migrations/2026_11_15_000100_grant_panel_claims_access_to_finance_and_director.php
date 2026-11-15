<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PCA-1 (owner decisions 2026-11-13): the Finance Officer and the Director get full Panel Claims access, the same as the
 * Panel Officer: manage claims, see the patient's phone and IC on the claim page (each view audited), and use the
 * Diagnosis and Treatment filters. Additive only: never revokes, a missing role is skipped.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'panel.claims.manage.branch',
        'panel.claims.patient_identity.view.branch',
        'panel.claims.clinical_filters.branch',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        foreach (['finance_officer', 'director'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Panel Claims access migration skipped missing role.', ['role' => $name]);

                continue;
            }
            foreach ($permissions as $permission) {
                if (! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
