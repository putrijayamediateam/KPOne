<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('organisation_id');
            $table->string('code', 64);
            $table->string('display_name', 500);
            $table->string('strength_text', 100)->nullable();
            $table->string('dosage_form', 100)->nullable();
            $table->string('order_unit', 100);
            $table->string('authorisation_class', 32)->default('doctor_order_required');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'medicine_catalogue_created_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'medicine_catalogue_updated_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('public_id');
            $table->unique(['organisation_id', 'code']);
            $table->unique(['id', 'organisation_id'], 'medicine_catalogue_id_tenant_unique');
            $table->index(['organisation_id', 'is_active', 'display_name'], 'medicine_catalogue_search_index');
        });

        Schema::create('clinical_service_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('organisation_id');
            $table->string('code', 64);
            $table->string('display_name', 500);
            $table->string('order_unit', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();

            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'service_catalogue_created_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'service_catalogue_updated_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('public_id');
            $table->unique(['organisation_id', 'code']);
            $table->unique(['id', 'organisation_id'], 'service_catalogue_id_tenant_unique');
            $table->index(['organisation_id', 'is_active', 'display_name'], 'service_catalogue_search_index');
        });

        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('clinical_encounter_id');
            $table->string('status', 32)->default('in_progress');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->foreign(['clinical_encounter_id', 'organisation_id', 'branch_id'], 'treatment_plans_encounter_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'treatment_plans_created_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'treatment_plans_updated_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('clinical_encounter_id');
            $table->unique(['id', 'organisation_id', 'branch_id'], 'treatment_plans_id_tenant_unique');
        });

        Schema::create('treatment_plan_medicine_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('treatment_plan_id');
            $table->unsignedBigInteger('medicine_catalogue_item_id');
            $table->string('medicine_code_snapshot', 64);
            $table->string('medicine_name_snapshot', 500);
            $table->string('strength_snapshot', 100)->nullable();
            $table->string('dosage_form_snapshot', 100)->nullable();
            $table->string('unit_snapshot', 100);
            $table->decimal('quantity_ordered', 12, 3);
            $table->string('dosage', 255);
            $table->string('frequency', 255);
            $table->string('duration', 255)->nullable();
            $table->string('route', 255)->nullable();
            $table->text('administration_instruction')->nullable();
            $table->text('indication')->nullable();
            $table->text('precaution')->nullable();
            $table->unsignedInteger('allergy_profile_version_validated');
            $table->unsignedSmallInteger('position');
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign(['treatment_plan_id', 'organisation_id', 'branch_id'], 'medicine_orders_plan_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('treatment_plans')->restrictOnDelete();
            $table->foreign(['medicine_catalogue_item_id', 'organisation_id'], 'medicine_orders_catalogue_tenant_fk')->references(['id', 'organisation_id'])->on('medicine_catalogue_items')->restrictOnDelete();
            $table->foreign(['recorded_by_user_id', 'organisation_id'], 'medicine_orders_recorded_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'medicine_orders_updated_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['withdrawn_by_user_id', 'organisation_id'], 'medicine_orders_withdrawn_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('public_id');
            $table->index(['treatment_plan_id', 'status', 'position'], 'medicine_orders_plan_status_index');
        });

        Schema::create('treatment_plan_service_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id');
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('treatment_plan_id');
            $table->unsignedBigInteger('clinical_service_catalogue_item_id');
            $table->string('service_code_snapshot', 64);
            $table->string('service_name_snapshot', 500);
            $table->string('unit_snapshot', 100);
            $table->decimal('quantity_ordered', 12, 3);
            $table->text('clinical_instruction')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamp('withdrawn_at')->nullable();
            $table->unsignedBigInteger('withdrawn_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign(['treatment_plan_id', 'organisation_id', 'branch_id'], 'service_orders_plan_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('treatment_plans')->restrictOnDelete();
            $table->foreign(['clinical_service_catalogue_item_id', 'organisation_id'], 'service_orders_catalogue_tenant_fk')->references(['id', 'organisation_id'])->on('clinical_service_catalogue_items')->restrictOnDelete();
            $table->foreign(['recorded_by_user_id', 'organisation_id'], 'service_orders_recorded_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'service_orders_updated_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['withdrawn_by_user_id', 'organisation_id'], 'service_orders_withdrawn_by_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('public_id');
            $table->index(['treatment_plan_id', 'status', 'position'], 'service_orders_plan_status_index');
        });

        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $this->addPostgresConstraints();
        } elseif ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX medicine_orders_active_position_unique ON treatment_plan_medicine_orders (treatment_plan_id, position) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX service_orders_active_position_unique ON treatment_plan_service_orders (treatment_plan_id, position) WHERE status = 'active'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('treatment_plan_service_orders');
        Schema::dropIfExists('treatment_plan_medicine_orders');
        Schema::dropIfExists('treatment_plans');
        Schema::dropIfExists('clinical_service_catalogue_items');
        Schema::dropIfExists('medicine_catalogue_items');
    }

    private function addPostgresConstraints(): void
    {
        DB::statement("ALTER TABLE medicine_catalogue_items ADD CONSTRAINT medicine_catalogue_authorisation_check CHECK (authorisation_class IN ('doctor_order_required'))");
        DB::statement("ALTER TABLE treatment_plans ADD CONSTRAINT treatment_plans_status_check CHECK (status IN ('in_progress'))");
        DB::statement('ALTER TABLE treatment_plans ADD CONSTRAINT treatment_plans_version_positive CHECK (lock_version > 0)');
        DB::statement("ALTER TABLE treatment_plan_medicine_orders ADD CONSTRAINT medicine_orders_status_check CHECK (status IN ('active', 'withdrawn'))");
        DB::statement('ALTER TABLE treatment_plan_medicine_orders ADD CONSTRAINT medicine_orders_quantity_positive CHECK (quantity_ordered > 0)');
        DB::statement('ALTER TABLE treatment_plan_medicine_orders ADD CONSTRAINT medicine_orders_position_positive CHECK (position > 0)');
        DB::statement('ALTER TABLE treatment_plan_medicine_orders ADD CONSTRAINT medicine_orders_allergy_version_positive CHECK (allergy_profile_version_validated > 0)');
        DB::statement("ALTER TABLE treatment_plan_medicine_orders ADD CONSTRAINT medicine_orders_withdrawal_provenance_check CHECK ((status = 'active' AND withdrawn_at IS NULL AND withdrawn_by_user_id IS NULL) OR (status = 'withdrawn' AND withdrawn_at IS NOT NULL AND withdrawn_by_user_id IS NOT NULL))");
        DB::statement("ALTER TABLE treatment_plan_service_orders ADD CONSTRAINT service_orders_status_check CHECK (status IN ('active', 'withdrawn'))");
        DB::statement('ALTER TABLE treatment_plan_service_orders ADD CONSTRAINT service_orders_quantity_positive CHECK (quantity_ordered > 0)');
        DB::statement('ALTER TABLE treatment_plan_service_orders ADD CONSTRAINT service_orders_position_positive CHECK (position > 0)');
        DB::statement("ALTER TABLE treatment_plan_service_orders ADD CONSTRAINT service_orders_withdrawal_provenance_check CHECK ((status = 'active' AND withdrawn_at IS NULL AND withdrawn_by_user_id IS NULL) OR (status = 'withdrawn' AND withdrawn_at IS NOT NULL AND withdrawn_by_user_id IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX medicine_orders_active_position_unique ON treatment_plan_medicine_orders (treatment_plan_id, position) WHERE status = 'active'");
        DB::statement("CREATE UNIQUE INDEX service_orders_active_position_unique ON treatment_plan_service_orders (treatment_plan_id, position) WHERE status = 'active'");
    }
};
