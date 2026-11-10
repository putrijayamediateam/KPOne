<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** HR-7c: additive grants only. A missing role is logged and skipped; nothing is ever revoked. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Literal names: this historical migration must not follow later code changes.
        $grants = [
            // Director only for exports in v1 (B.E.R.N.I.S.S for the owner, 2026-11-07); the Finance Officer gets no payroll access.
            'hr.payroll.export.organisation' => ['director'],
            'hr.payslips.view.own' => ['director', 'resident_doctor', 'ca', 'ca_supervisor', 'panel_officer', 'finance_officer',
                'business_development', 'marketing', 'hr_manager', 'technical_admin', 'administrator'],
        ];
        foreach ($grants as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('HR payslip and export permission role missing', ['role' => $holder, 'permission' => $name]);

                    continue;
                }
                if (! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {} // Additive grants are retained.
};
