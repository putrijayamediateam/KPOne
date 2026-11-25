<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-38 (owner decision 24 Nov 2026, "Benarkan Director"): the Director publishes prices. Additive: grants only what
 * is missing, skips a missing role and never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'prices.publish.organisation', 'guard_name' => 'web']);
        $role = Role::query()->where('name', 'director')->where('guard_name', 'web')->first();
        if (! $role) {
            Log::warning('BIL-38 price publish grant skipped missing role', ['role' => 'director']);
        } elseif (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
