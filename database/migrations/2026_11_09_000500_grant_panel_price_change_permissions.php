<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Panel price changes by proposal (owner decision 27; P1 and P2 approved 21 Nov 2026). The Panel Officer proposes; the
 * Finance Officer and the Director approve (never their own proposal: enforced in the service and PostgreSQL).
 * Additive only: never revokes, a missing role is skipped. No change to prices.publish.organisation.
 */
return new class extends Migration
{
    private const GRANTS = [
        'panel.prices.propose.organisation' => ['panel_officer'],
        'panel.prices.approve.organisation' => ['finance_officer', 'director'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Panel price change permission migration skipped missing role.', ['role' => $roleName]);

                    continue;
                }
                if (! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
