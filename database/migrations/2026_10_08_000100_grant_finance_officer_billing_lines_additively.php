<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ROLE = 'finance_officer';

    private const PERMISSION = 'billing.lines.view.branch';

    /**
     * FIN-01 (owner decision, 2026-10-08): a finance officer sees the invoice line items
     * (charge name, quantity, unit price, total) on the billing page, not only totals.
     * Scoped to exactly this role and permission; the line projection stays closed for
     * Director and Panel Officer.
     *
     * Additive only, like 2026_09_27_000100_grant_ca_supervisor_pricing_permissions_additively:
     * a missing Permission is created, the role is given only what it lacks, a role that does
     * not exist is logged and skipped (never created), and nothing is ever revoked.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        $role = Role::query()->where('name', self::ROLE)->where('guard_name', 'web')->first();

        if (! $role) {
            Log::warning('FIN-01 migration: role not present in database, skipped (never auto-created).', [
                'role' => self::ROLE,
            ]);
        } elseif (! $role->hasPermissionTo(self::PERMISSION)) {
            $role->givePermissionTo(self::PERMISSION);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Intentionally a no-op: reversing could remove a grant this migration did not create.
     */
    public function down(): void {}
};
