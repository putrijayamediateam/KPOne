<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_plan_service_orders', function (Blueprint $table): void {
            $table->unique(['id', 'organisation_id', 'branch_id'], 'service_orders_checkout_owner_unique');
        });
        Schema::table('visits', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('completion_evidence')->nullable();
            $table->unique(['id', 'organisation_id', 'branch_id', 'patient_id'], 'visits_checkout_owner_unique');
        });
        Schema::create('consultation_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('clinical_encounter_id');
            $table->foreignId('treatment_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('dispensary_handoff_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('attending_doctor_user_id');
            $table->string('route', 20);
            $table->string('status', 20)->default('current');
            $table->unsignedBigInteger('current_visit_guard')->nullable()->unique();
            $table->unsignedBigInteger('encounter_version');
            $table->unsignedBigInteger('plan_version')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamp('checked_out_at');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->foreign(['visit_id', 'organisation_id', 'branch_id', 'patient_id'], 'checkout_visit_owner_fk')->references(['id', 'organisation_id', 'branch_id', 'patient_id'])->on('visits')->restrictOnDelete();
            $table->foreign(['clinical_encounter_id', 'organisation_id', 'branch_id'], 'checkout_encounter_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->foreign(['attending_doctor_user_id', 'organisation_id'], 'checkout_doctor_owner_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['treatment_plan_id', 'organisation_id', 'branch_id'], 'checkout_plan_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('treatment_plans')->restrictOnDelete();
            $table->foreign(['dispensary_handoff_id', 'organisation_id', 'branch_id'], 'checkout_handoff_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_handoffs')->restrictOnDelete();
            $table->unique(['id', 'organisation_id', 'branch_id'], 'checkout_tenant_unique');
        });
        Schema::create('service_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('consultation_checkout_id');
            $table->foreignId('treatment_plan_service_order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('source_plan_version');
            $table->string('source_fingerprint', 64);
            $table->string('disposition', 20);
            $table->decimal('quantity_performed', 12, 3);
            $table->timestamp('performed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamps();
            $table->foreign(['consultation_checkout_id', 'organisation_id', 'branch_id'], 'delivery_checkout_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('consultation_checkouts')->restrictOnDelete();
            $table->foreign(['treatment_plan_service_order_id', 'organisation_id', 'branch_id'], 'delivery_order_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('treatment_plan_service_orders')->restrictOnDelete();
            $table->foreign(['confirmed_by_user_id', 'organisation_id'], 'delivery_doctor_owner_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['consultation_checkout_id', 'treatment_plan_service_order_id'], 'delivery_order_unique');
            $table->unique(['id', 'organisation_id', 'branch_id'], 'delivery_tenant_unique');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visits DROP CONSTRAINT visits_status_check, DROP CONSTRAINT visits_cancellation_fields_check');
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_check CHECK (status IN ('registered','cancelled','completed'))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_terminal_fields_check CHECK ((status='registered' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL AND completed_at IS NULL AND completed_by_user_id IS NULL AND completion_evidence IS NULL) OR (status='cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND cancellation_reason IS NOT NULL AND completed_at IS NULL AND completed_by_user_id IS NULL AND completion_evidence IS NULL) OR (status='completed' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL AND completed_at IS NOT NULL AND completed_by_user_id IS NOT NULL AND completion_evidence IS NOT NULL))");
            DB::statement("ALTER TABLE consultation_checkouts ADD CONSTRAINT checkout_shape CHECK (lock_version>0 AND encounter_version>0 AND ((treatment_plan_id IS NULL AND plan_version IS NULL) OR (treatment_plan_id IS NOT NULL AND plan_version>0)) AND ((route='billing' AND dispensary_handoff_id IS NULL) OR (route='dispensary' AND dispensary_handoff_id IS NOT NULL)) AND ((status='current' AND current_visit_guard=visit_id AND current_visit_guard IS NOT NULL AND superseded_at IS NULL) OR (status='superseded' AND current_visit_guard IS NULL AND superseded_at IS NOT NULL)))");
            DB::statement("ALTER TABLE service_deliveries ADD CONSTRAINT service_delivery_shape CHECK (source_plan_version>0 AND lock_version>0 AND ((disposition='performed' AND quantity_performed>0 AND performed_at IS NOT NULL) OR (disposition='not_performed' AND quantity_performed=0 AND performed_at IS NULL)))");
        }
    }

    public function down(): void
    {
        if (DB::table('consultation_checkouts')->exists() || DB::table('visits')->where('status', 'completed')->exists()) {
            throw new RuntimeException('Retained checkout/completion evidence prevents rollback. Use a disposable test database for fresh recreation.');
        }
        Schema::dropIfExists('service_deliveries');
        Schema::dropIfExists('consultation_checkouts');
        Schema::table('treatment_plan_service_orders', fn (Blueprint $table) => $table->dropUnique('service_orders_checkout_owner_unique'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visits DROP CONSTRAINT visits_terminal_fields_check, DROP CONSTRAINT visits_status_check');
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_check CHECK (status IN ('registered','cancelled'))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_cancellation_fields_check CHECK ((status='registered' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL) OR (status='cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND cancellation_reason IS NOT NULL))");
        }
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropForeign(['completed_by_user_id']);
            $table->dropUnique('visits_checkout_owner_unique');
            $table->dropColumn(['completed_at', 'completed_by_user_id', 'completion_evidence']);
        });
    }
};
