<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * SUG (owner, 25 Nov 2026, "Lulus"): the clinic doctor reviews the recommendation lists. The
 * Resident Doctor and the Director review; never the person who entered or last changed the item (service and PostgreSQL).
 * Additive only: never revokes, a missing role is skipped. Nothing is granted to ca or medical_assistant.
 */
return new class extends Migration
{
    private const GRANTS = [
        'clinical_suggestions.approve.organisation' => ['resident_doctor', 'director'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Suggestion review permission migration skipped missing role.', ['role' => $roleName]);

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
