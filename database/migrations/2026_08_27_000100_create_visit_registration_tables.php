<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(['id', 'organisation_id'], 'branches_id_organisation_unique');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['id', 'organisation_id'], 'users_id_organisation_unique');
        });

        Schema::create('panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organisation_id', 'code']);
            $table->unique(['id', 'organisation_id'], 'panels_id_organisation_unique');
            $table->index(['organisation_id', 'is_active', 'name']);
        });

        Schema::create('visit_number_counters', function (Blueprint $table) {
            $table->foreignId('organisation_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('visit_number', 20);
            $table->uuid('idempotency_key');
            $table->string('visit_type', 32);
            $table->string('status', 32);
            $table->string('priority', 32);
            $table->text('visit_reason')->nullable();
            $table->unsignedBigInteger('assigned_doctor_user_id')->nullable();
            $table->string('coverage_type', 32);
            $table->unsignedBigInteger('panel_id')->nullable();
            $table->string('coverage_panel_name_snapshot')->nullable();
            $table->string('coverage_member_reference', 100)->nullable();
            $table->timestamp('registered_at');
            $table->unsignedBigInteger('registered_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(['branch_id', 'organisation_id'], 'visits_branch_organisation_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['patient_id', 'organisation_id'], 'visits_patient_organisation_fk')
                ->references(['id', 'organisation_id'])->on('patients')->restrictOnDelete();
            $table->foreign(['panel_id', 'organisation_id'], 'visits_panel_organisation_fk')
                ->references(['id', 'organisation_id'])->on('panels')->restrictOnDelete();
            $table->foreign(['assigned_doctor_user_id', 'organisation_id'], 'visits_doctor_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['registered_by_user_id', 'organisation_id'], 'visits_registered_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'visits_updated_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['cancelled_by_user_id', 'organisation_id'], 'visits_cancelled_by_organisation_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();

            $table->unique(['organisation_id', 'visit_number']);
            $table->unique(['organisation_id', 'idempotency_key']);
            $table->index(['branch_id', 'registered_at', 'status']);
            $table->index(['patient_id', 'branch_id', 'registered_at', 'status'], 'visits_repeat_warning_index');
            $table->index(['branch_id', 'priority', 'registered_at']);
            $table->index(['assigned_doctor_user_id', 'registered_at']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visit_number_counters ADD CONSTRAINT visit_number_counters_next_value_positive CHECK (next_value > 0)');
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_type_check CHECK (visit_type IN ('consultation', 'otc'))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_check CHECK (status IN ('registered', 'cancelled'))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_priority_check CHECK (priority IN ('normal', 'urgent'))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_coverage_check CHECK (coverage_type IN ('self_pay', 'panel'))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_consultation_fields_check CHECK (visit_type <> 'consultation' OR (assigned_doctor_user_id IS NOT NULL AND visit_reason IS NOT NULL AND btrim(visit_reason) <> ''))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_coverage_fields_check CHECK ((coverage_type = 'self_pay' AND panel_id IS NULL AND coverage_panel_name_snapshot IS NULL AND coverage_member_reference IS NULL) OR (coverage_type = 'panel' AND panel_id IS NOT NULL AND coverage_panel_name_snapshot IS NOT NULL))");
            DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_cancellation_fields_check CHECK ((status = 'registered' AND cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancellation_reason IS NULL) OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_user_id IS NOT NULL AND cancellation_reason IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
        Schema::dropIfExists('visit_number_counters');
        Schema::dropIfExists('panels');
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique('users_id_organisation_unique'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropUnique('branches_id_organisation_unique'));
    }
};
