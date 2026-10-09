<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var list<string> */
    private const ROLES = ['director', 'resident_doctor', 'ca', 'ca_supervisor'];

    private const PERMISSION = 'visits.history.view.branch';

    /**
     * VH-01 (owner decision, 2026-10-08): every role that can see Registration and Consultation
     * can open the read-only history of a completed visit at its own branch. Scoped to exactly
     * these roles and this permission; technical admin and the other roles gain nothing.
     *
     * Additive only, like 2026_10_08_000100_grant_finance_officer_billing_lines_additively:
     * a missing Permission is created, a role is given only what it lacks, a role that does not
     * exist is logged and skipped (never created), and nothing is ever revoked.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        foreach (self::ROLES as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();

            if (! $role) {
                Log::warning('VH-01 migration: role not present in database, skipped (never auto-created).', [
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
