<?php

use App\Domain\Visit\Assignment\AutoAssignPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * AUTO-1 (owner, 24 Nov 2026): one row per branch holding the Director's switch for automatic doctor assignment. A branch
 * with no row is off. The permission is granted additively to the Director only (never revoked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_auto_assign_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('updated_by_user_id');
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'branch_auto_assign_branch_owner_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['updated_by_user_id', 'organisation_id'], 'branch_auto_assign_updater_owner_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'branch_id'], 'branch_auto_assign_branch_unique');
        });

        foreach (AutoAssignPermissions::grants() as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($roles as $roleName) {
                $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                if (! $role) {
                    Log::warning('Auto-assign grant skipped missing role', ['role' => $roleName, 'permission' => $name]);
                } elseif (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_auto_assign_settings');
    }
};
