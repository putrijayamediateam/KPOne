<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLE = 'director';

    /** @var list<string> */
    private const PERMISSIONS = [
        'prices.publish.organisation',
    ];

    /**
     * BIL-38 (owner decision, 2026-11-26): Director may publish prices.
     * Grant the existing operational permission only; staff-account authority is unchanged.
     *
     * Additive only, like 2026_09_22_000100_sync_permission_catalogue_additively: a missing
     * Permission is created, the role is given only what it lacks, a role that does not
     * exist is logged and skipped (never created), and nothing is ever revoked.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();

        if (! $role) {
            Log::warning('BIL-38 migration: role not present in database, skipped (never auto-created).', [
                'role' => self::ROLE,
            ]);
        } else {
            $missing = array_values(array_diff(self::PERMISSIONS, $role->permissions()->pluck('name')->all()));

            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Intentionally a no-op: reversing could remove a grant this migration did not create.
     */
    public function down(): void {}
};
