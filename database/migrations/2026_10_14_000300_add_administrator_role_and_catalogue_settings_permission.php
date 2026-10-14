<?php

use App\Domain\Access\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const SETTINGS_PERMISSION = 'catalogue.settings.manage.organisation';

    /** Roles that receive the new settings permission on an existing database. */
    private const SETTINGS_HOLDERS = ['director', 'finance_officer', 'panel_officer'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $settings = Permission::query()->firstOrCreate(['name' => self::SETTINGS_PERMISSION, 'guard_name' => 'web']);
        foreach (self::SETTINGS_HOLDERS as $name) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Administrator role migration skipped a missing role.', ['role' => $name]);

                continue;
            }
            if (! $role->permissions()->whereKey($settings->id)->exists()) {
                $role->givePermissionTo($settings);
            }
        }

        // The Administrator role is new, so it is created here with exactly its catalogue permissions.
        $permissions = PermissionCatalogue::roles()[PermissionCatalogue::ADMINISTRATOR_ROLE];
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $administrator = Role::query()->firstOrCreate(['name' => PermissionCatalogue::ADMINISTRATOR_ROLE, 'guard_name' => 'web']);
        $missing = array_values(array_diff($permissions, $administrator->permissions()->pluck('name')->all()));
        if ($missing !== []) {
            $administrator->givePermissionTo($missing);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
