<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const ROLE_GRANTS = [
        'ca' => ['coverage.approve.branch'],
        'director' => ['billing.approval_limits.manage.organisation'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_values(array_unique(array_merge(...array_values(self::ROLE_GRANTS)))) as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (self::ROLE_GRANTS as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Billing approval policy migration skipped missing role.', ['role' => $roleName]);

                continue;
            }

            $missing = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));
            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (DB::getDriverName() === 'pgsql') {
            foreach (['coverage_allocations', 'patient_receivables'] as $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_shape");
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_shape CHECK (amount_sen BETWEEN 1 AND 999999999999 AND lock_version>0 AND expected_invoice_version>0 AND status IN ('proposed','approved','superseded') AND ((status='superseded' AND current_invoice_guard IS NULL) OR (status IN ('proposed','approved') AND current_invoice_guard=invoice_id AND current_invoice_guard IS NOT NULL)) AND (status<>'approved' OR (approved_by_user_id IS NOT NULL AND approved_at IS NOT NULL)))");
            }
        }
    }

    /**
     * Intentionally a no-op: reversing could remove grants or reject rows that were valid
     * under the updated approval policy.
     */
    public function down(): void {}
};
