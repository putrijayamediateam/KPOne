<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('payment_method_id');
            $table->date('business_date');
            $table->unsignedInteger('revision');
            $table->unsignedInteger('terminal_sales_count');
            $table->unsignedBigInteger('terminal_sales_sen');
            $table->unsignedInteger('terminal_refunds_count')->default(0);
            $table->unsignedBigInteger('terminal_refunds_sen')->default(0);
            $table->unsignedInteger('terminal_voids_count')->default(0);
            $table->unsignedBigInteger('terminal_voids_sen')->default(0);
            $table->unsignedInteger('kpone_payment_count');
            $table->unsignedBigInteger('kpone_payment_total_sen');
            $table->integer('payment_count_variance');
            $table->bigInteger('payment_total_variance_sen');
            $table->string('terminal_batch_reference', 100)->nullable();
            $table->string('variance_reason', 1000)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->uuid('idempotency_key');
            $table->string('payload_hash', 64);
            $table->unsignedBigInteger('reconciled_by_user_id');
            $table->timestamp('reconciled_at');
            $table->timestamps();
            $table->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['payment_method_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('payment_methods')->restrictOnDelete();
            $table->foreign(['reconciled_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'branch_id', 'payment_method_id', 'business_date', 'revision'], 'payment_reconciliations_revision_unique');
            $table->unique(['organisation_id', 'idempotency_key'], 'payment_reconciliations_idempotency_unique');
            $table->index(['organisation_id', 'branch_id', 'business_date'], 'payment_reconciliations_branch_date_index');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::query()->firstOrCreate(['name' => 'payments.reconcile.branch', 'guard_name' => 'web']);

        foreach (['director', 'finance_officer', 'ca', 'ca_supervisor'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if (! $role) {
                Log::warning('Payment reconciliation permission migration: catalogue role not present in database, skipped.', [
                    'role' => $roleName,
                ]);

                continue;
            }

            if (! $role->permissions()->whereKey($permission->id)->exists()) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
        // Permission grants are retained, following the additive permission migration convention.
    }
};
