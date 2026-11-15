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
     * Ships the UI-1 addition to PermissionCatalogue (clinical_services.manage.organisation)
     * into an existing database through `php artisan migrate` alone, following the
     * same additive pattern as 2026_09_22_000100_sync_permission_catalogue_additively:
     *
     * - A missing Permission row is created (firstOrCreate, guard 'web'). An
     *   existing one is left untouched.
     * - For a Role that already exists in this database, only the catalogue
     *   permissions it is missing are added (givePermissionTo on the diff). A
     *   permission that role already holds is never touched, never removed.
     * - A catalogue role absent from this database is never created here; its
     *   name is logged as a structural warning and skipped.
     * - Never uses syncPermissions, revokePermissionTo, detach or delete.
     *
     * Safe to run whether KPOneReferenceSeeder or the earlier sync migration
     * has already run, and safe to run again later — each run only ever adds
     * what is still missing from the current PermissionCatalogue.
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
     * Intentionally a no-op, for the same reason as the migration this one
     * mirrors: it only ever adds permissions and role grants, some of which
     * may already have existed before it ran. Reversing it could delete a
     * grant it did not itself create.
     */
    public function down(): void {}
};
