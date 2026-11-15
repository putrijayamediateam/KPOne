<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * STK-8 owner decision 2026-11-05: CA and CA Supervisor record treatment-room usage and waste; reversal
 * uses the existing variance approval permission. Additive: creates missing permissions, grants only what is missing,
 * skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grants = [
            'inventory.usage.record.branch' => ['ca', 'ca_supervisor'],
        ];
        foreach ($grants as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Treatment usage grant skipped missing role', ['role' => $holder, 'permission' => $name]);

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
