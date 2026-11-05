<?php

use App\Domain\Agents\AgentPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * AG-1: the agent platform permissions (docs/agents/CONTRACT.md section 4). Additive only: creates a
 * missing permission, gives only missing grants, logs and skips a missing role, never revokes.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (AgentPermissions::grants() as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Agent permission grant skipped missing role', ['role' => $holder, 'permission' => $name]);

                    continue;
                }
                if (! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
