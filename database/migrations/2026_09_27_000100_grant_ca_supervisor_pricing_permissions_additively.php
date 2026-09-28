<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLE = 'ca_supervisor';

    /** @var list<string> */
    private const PERMISSIONS = [
        'pricing.references.manage.organisation',
        'prices.publish.organisation',
    ];

    /**
     * PX-01 (owner decision, 2026-09-27): ca_supervisor manages price books, charge
     * definitions and published prices, as finance_officer does. Scoped to exactly this
     * role and these two permissions so no other role can change as a side effect.
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
            Log::warning('PX-01 migration: role not present in database, skipped (never auto-created).', [
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
