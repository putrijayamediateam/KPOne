<?php

use App\Domain\Access\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const GRANTS = [
        'director' => ['queue_display.manage.organisation'],
        'ca_supervisor' => ['queue_display.manage.branch'],
        'resident_doctor' => ['queue.room.select.own'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'queue.room.select.own',
            'queue.display.branch',
            'queue_display.manage.organisation',
            'queue_display.manage.branch',
        ] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (self::GRANTS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Queue display permission migration: catalogue role not present in database, skipped.', [
                    'role' => $roleName,
                ]);

                continue;
            }

            $missing = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        // The display role is new, so it is created here with exactly its catalogue permissions.
        $displayPermissions = PermissionCatalogue::roles()[PermissionCatalogue::QUEUE_DISPLAY_ROLE];
        foreach ($displayPermissions as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $display = Role::query()->firstOrCreate([
            'name' => PermissionCatalogue::QUEUE_DISPLAY_ROLE,
            'guard_name' => 'web',
        ]);
        $missing = array_values(array_diff($displayPermissions, $display->permissions()->pluck('name')->all()));
        if ($missing !== []) {
            $display->givePermissionTo($missing);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
