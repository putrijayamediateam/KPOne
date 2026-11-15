<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** REG-3 (owner decision G, 2026-11-05): additive, never revokes. */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'patients.merge.propose.organisation' => ['ca', 'ca_supervisor', 'director'],
            'patients.merge.approve.organisation' => ['ca_supervisor', 'director'],
        ];
        foreach ($grants as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Patient merge grant skipped missing role', ['role' => $holder]);

                    continue;
                }
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
