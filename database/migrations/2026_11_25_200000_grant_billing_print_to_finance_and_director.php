<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-36 (owner decision 24 Nov 2026; answers 1C and 2A): the Finance Officer and the Director print invoices and
 * receipts. The Finance Officer also gets the narrow billing.print.patient_identity.branch, so the issued paper may show
 * the patient's IC/passport and phone without opening the patient pages. Additive: grants only what is missing, skips a
 * missing role and never revokes.
 */
return new class extends Migration
{
    private const GRANTS = [
        'billing.print.branch' => ['finance_officer', 'director'],
        'billing.print.patient_identity.branch' => ['finance_officer'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('BIL-36 print grant skipped missing role', ['role' => $roleName, 'permission' => $name]);
                } elseif (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
