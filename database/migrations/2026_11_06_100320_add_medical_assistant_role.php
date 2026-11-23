<?php

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * MA-1a (owner decision 23 Nov 2026, "MA: approve all recommended, start MA-1a"; MA-ROLE-DESIGN sections 7 and 9): the
 * `medical_assistant` role, created with exactly its catalogue permissions (everything the CA holds at migration time,
 * plus the treatment room and triage), and the three new treatment-room permissions for their existing holders (CA:
 * view; CA Supervisor: record, treatment-room call and view). Additive: `firstOrCreate`, give only what is missing, never
 * revoke, a missing existing role is logged and skipped, `down()` a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $catalogue = PermissionCatalogue::roles();
        $permissions = array_values(array_unique($catalogue[PermissionCatalogue::MEDICAL_ASSISTANT_ROLE]));
        foreach ([...$permissions, ...array_keys(TreatmentRoomPermissions::grants())] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::query()->firstOrCreate(['name' => PermissionCatalogue::MEDICAL_ASSISTANT_ROLE, 'guard_name' => 'web']);
        $missing = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));
        if ($missing !== []) {
            $role->givePermissionTo($missing);
        }

        foreach (TreatmentRoomPermissions::grants() as $permission => $holders) {
            foreach ($holders as $name) {
                if ($name === PermissionCatalogue::MEDICAL_ASSISTANT_ROLE) {
                    continue;
                }
                $existing = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
                if (! $existing) {
                    Log::warning('MA-1a migration skipped a missing role.', ['role' => $name, 'permission' => $permission]);

                    continue;
                }
                if (! $existing->hasPermissionTo($permission)) {
                    $existing->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
