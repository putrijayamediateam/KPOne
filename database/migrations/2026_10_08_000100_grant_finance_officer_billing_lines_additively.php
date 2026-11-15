<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private const ROLES = ['finance_officer', 'panel_officer', 'director'];

    private const PERMISSION = 'billing.lines.view.branch';

    /**
     * FIN-01 (owner decision, 2026-10-08): finance officers, panel officers and directors see
     * the invoice line items (charge name, quantity, unit price, total) on the billing page,
     * not only totals. Scoped to exactly these roles and this permission.
     *
     * Additive only, like 2026_09_27_000100_grant_ca_supervisor_pricing_permissions_additively:
     * a missing Permission is created, the role is given only what it lacks, a role that does
     * not exist is logged and skipped (never created), and nothing is ever revoked.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        foreach (self::ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();

            if (! $role) {
                Log::warning('FIN-01 migration: role not present in database, skipped (never auto-created).', [
                    'role' => $name,
                ]);
            } elseif (! $role->hasPermissionTo(self::PERMISSION)) {
                $role->givePermissionTo(self::PERMISSION);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Intentionally a no-op: reversing could remove a grant this migration did not create.
     */
    public function down(): void {}
};
