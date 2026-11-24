<?php

use App\Domain\Agents\AgentPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CHAT-1: `ai_chat.use.organisation` and `ai_chat.history.view.organisation` for the Director only (docs/agents/AI-CHATBOX-DESIGN.md section 7). Additive only:
 * creates the permission if missing, gives only the missing grant, logs and skips a missing role, never revokes.
 * It is not people or access authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([AgentPermissions::CHAT_USE, AgentPermissions::CHAT_HISTORY] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach (AgentPermissions::grants()[$name] as $holder) {
                $role = Role::query()->where('name', $holder)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Chat permission grant skipped missing role', ['role' => $holder, 'permission' => $name]);

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
