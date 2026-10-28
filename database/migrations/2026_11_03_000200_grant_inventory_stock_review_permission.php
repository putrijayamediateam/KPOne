<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'inventory.stock_reviews.manage.branch', 'guard_name' => 'web']);
        foreach (['ca_supervisor', 'finance_officer', 'director'] as $holder) {
            $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Stock review grant skipped missing role', ['role' => $holder]);

                continue;
            }
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
