<?php

use App\Domain\Agents\AgentPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CN-1: `ai_connector.use.organisation` for the Director only (docs/agents/MCP-CONNECTOR-DESIGN.md section 5). Additive only:
 * creates the permission if missing, gives only the missing grant, logs and skips a missing role, never revokes.
 * It is not people or access authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $name = AgentPermissions::CONNECTOR_USE;
        $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        foreach (AgentPermissions::grants()[$name] as $holder) {
            $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Connector permission grant skipped missing role', ['role' => $holder, 'permission' => $name]);

                continue;
            }
            if (! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};
