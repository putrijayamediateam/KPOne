<?php

use App\Domain\Access\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Ships PermissionCatalogue changes into an existing database through
     * `php artisan migrate` alone (R1-02) — no manual `db:seed` step required
     * for a permission that was already added to the catalogue to actually
     * take effect. From this point forward this is the standard pattern for
     * a phase that adds a permission: see docs/releases/Q1-B2-D3.md.
     *
     * Strictly additive and idempotent:
     * - A missing Permission row is created (firstOrCreate, same guard as
     *   KPOneReferenceSeeder: 'web'). An existing one is left untouched.
     * - For a Role that already exists in this database, only the catalogue
     *   permissions it is missing are added (givePermissionTo on the diff).
     *   A permission that role already holds — including one granted outside
     *   the catalogue — is never touched, never removed.
     * - A catalogue role absent from this database is never created here;
     *   its name is logged as a structural warning and skipped. Creating
     *   roles remains KPOneReferenceSeeder's job (run once per new
     *   installation, or whenever a new role is introduced).
     * - Never uses syncPermissions, revokePermissionTo, detach or delete.
     *
     * Safe to run before KPOneReferenceSeeder has ever run, after it has run,
     * or interleaved with it in either order, and safe to run again later
     * once further permissions are added to the catalogue — each run only
     * ever adds what is still missing.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalogue::all() as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (PermissionCatalogue::roles() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('PermissionCatalogue migration: catalogue role not present in database, skipped (never auto-created).', [
                    'role' => $roleName,
                ]);

                continue;
            }

            $missing = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Intentionally a no-op. This migration only ever added permissions and
     * role grants — some of which may already have existed before it ran
     * (from KPOneReferenceSeeder or a prior run of this same migration).
     * Reversing it could delete a grant it did not itself create, so it
     * never attempts to.
     */
    public function down(): void {}
};
