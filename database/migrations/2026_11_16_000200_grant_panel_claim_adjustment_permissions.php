<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PCA-2 (design note v3.2, owner approved 2026-11-15): request and confirm a Panel claim adjustment. Two names so a second
 * person can be required later without a new permission; today the same person may hold and use both. Panel Officer,
 * Finance Officer and Director. Additive only: never revokes, a missing role is skipped.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'panel.claims.adjustments.request.branch',
        'panel.claims.adjustments.confirm.branch',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        foreach (['panel_officer', 'finance_officer', 'director'] as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Panel claim adjustment permission migration skipped missing role.', ['role' => $name]);

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
