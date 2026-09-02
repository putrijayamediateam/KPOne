<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_plans', function (Blueprint $table): void {
            $table->index(['organisation_id', 'branch_id', 'status'], 'treatment_plans_tenant_status_index');
        });
        Schema::table('treatment_plan_medicine_orders', function (Blueprint $table): void {
            $table->unique(['id', 'organisation_id', 'branch_id'], 'medicine_orders_id_tenant_unique');
        });

        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->string('removal_reason', 40)->nullable()->after('removed_at');
            $table->timestamp('returned_from_dispensary_at')->nullable()->after('removal_reason');
        });

        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->string('code', 64);
            $table->string('generic_name', 300);
            $table->string('brand_name', 300)->nullable();
            $table->string('strength', 100)->nullable();
            $table->string('dosage_form', 100)->nullable();
            $table->string('route', 100)->nullable();
            $table->string('manufacturer', 200)->nullable();
            $table->string('mal_number', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->unique(['organisation_id', 'code']);
            $table->unique(['id', 'organisation_id'], 'inventory_items_id_tenant_unique');
        });

        Schema::create('inventory_skus', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('inventory_item_id');
            $table->string('sku_code', 64);
            $table->string('barcode', 100)->nullable();
            $table->decimal('pack_size', 12, 3);
            $table->string('purchase_unit', 100);
            $table->string('stock_unit', 100);
            $table->string('dispensing_unit', 100);
            $table->decimal('unit_conversion', 12, 3);
            $table->string('storage_type', 40)->default('ambient');
            $table->decimal('minimum_temperature', 5, 2)->nullable();
            $table->decimal('maximum_temperature', 5, 2)->nullable();
            $table->boolean('cold_chain_required')->default(false);
            $table->boolean('do_not_freeze')->default(false);
            $table->boolean('protect_from_light')->default(false);
            $table->boolean('batch_tracking_required')->default(true);
            $table->boolean('expiry_tracking_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign(['inventory_item_id', 'organisation_id'], 'inventory_skus_item_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_items')->restrictOnDelete();
            $table->unique(['organisation_id', 'sku_code']);
            $table->unique(['id', 'organisation_id'], 'inventory_skus_id_tenant_unique');
        });

        Schema::create('medicine_catalogue_inventory_skus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('medicine_catalogue_item_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('approved_by_user_id');
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->foreign(['medicine_catalogue_item_id', 'organisation_id'], 'medicine_inventory_map_catalogue_fk')->references(['id', 'organisation_id'])->on('medicine_catalogue_items')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'medicine_inventory_map_sku_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['approved_by_user_id', 'organisation_id'], 'medicine_inventory_map_approver_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['medicine_catalogue_item_id', 'inventory_sku_id'], 'medicine_inventory_map_unique');
        });

        Schema::create('medicine_catalogue_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('medicine_catalogue_item_id');
            $table->string('alias', 500);
            $table->string('normalized_alias', 500);
            $table->timestamps();
            $table->foreign(['medicine_catalogue_item_id', 'organisation_id'], 'medicine_alias_catalogue_fk')->references(['id', 'organisation_id'])->on('medicine_catalogue_items')->restrictOnDelete();
            $table->unique(['organisation_id', 'normalized_alias']);
        });

        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 64);
            $table->string('name', 200);
            $table->string('type', 40);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organisation_id'], 'inventory_locations_branch_tenant_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->unique(['id', 'organisation_id'], 'inventory_locations_id_tenant_unique');
            $table->unique(['id', 'organisation_id', 'branch_id'], 'inventory_locations_id_tenant_branch_unique');
            $table->unique(['organisation_id', 'code']);
        });
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->foreign(['parent_id', 'organisation_id'], 'inventory_locations_parent_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
        });

        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->string('batch_number', 100);
            $table->date('expiry_date');
            $table->date('received_at')->nullable();
            $table->string('status', 32)->default('available');
            $table->timestamps();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'inventory_batches_sku_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->unique(['id', 'organisation_id'], 'inventory_batches_id_tenant_unique');
            $table->unique(['id', 'organisation_id', 'inventory_sku_id'], 'inventory_batches_id_tenant_sku_unique');
            $table->unique(['organisation_id', 'inventory_sku_id', 'batch_number', 'expiry_date'], 'inventory_batches_identity_unique');
        });

        Schema::create('inventory_stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('inventory_location_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->unsignedBigInteger('inventory_batch_id');
            $table->decimal('quantity', 15, 3)->default(0);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->foreign(['inventory_location_id', 'organisation_id'], 'stock_balances_location_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'stock_balances_sku_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['inventory_batch_id', 'organisation_id', 'inventory_sku_id'], 'stock_balances_batch_tenant_sku_fk')->references(['id', 'organisation_id', 'inventory_sku_id'])->on('inventory_batches')->restrictOnDelete();
            $table->unique(['organisation_id', 'inventory_location_id', 'inventory_sku_id', 'inventory_batch_id'], 'stock_balances_tuple_unique');
        });

        Schema::create('dispensary_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('clinical_encounter_id');
            $table->unsignedBigInteger('treatment_plan_id');
            $table->string('status', 32);
            $table->unsignedBigInteger('current_handler_user_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('received_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign(['visit_id', 'organisation_id', 'branch_id'], 'dispensary_cases_visit_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $table->foreign(['clinical_encounter_id', 'organisation_id', 'branch_id'], 'dispensary_cases_encounter_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('clinical_encounters')->restrictOnDelete();
            $table->foreign(['treatment_plan_id', 'organisation_id', 'branch_id'], 'dispensary_cases_plan_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('treatment_plans')->restrictOnDelete();
            $table->foreign(['current_handler_user_id', 'organisation_id'], 'dispensary_cases_handler_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique('treatment_plan_id');
            $table->unique(['id', 'organisation_id', 'branch_id'], 'dispensary_cases_id_tenant_unique');
            $table->index(['organisation_id', 'branch_id', 'status', 'received_at'], 'dispensary_cases_board_index');
        });

        Schema::create('dispensary_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('dispensary_case_id');
            $table->unsignedInteger('attempt_number');
            $table->unsignedInteger('treatment_plan_lock_version_received');
            $table->string('status', 24);
            $table->unsignedBigInteger('open_case_guard')->nullable()->unique();
            $table->unsignedBigInteger('sent_by_user_id');
            $table->timestamp('sent_at');
            $table->unsignedBigInteger('started_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->unsignedBigInteger('returned_by_user_id')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('completed_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign(['dispensary_case_id', 'organisation_id', 'branch_id'], 'dispensary_handoffs_case_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_cases')->restrictOnDelete();
            foreach (['sent_by_user_id', 'started_by_user_id', 'returned_by_user_id', 'completed_by_user_id'] as $column) {
                $table->foreign([$column, 'organisation_id'], 'dispensary_handoffs_'.str_replace('_user_id', '', $column).'_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $table->unique(['dispensary_case_id', 'attempt_number'], 'dispensary_handoffs_attempt_unique');
            $table->unique(['id', 'organisation_id', 'branch_id'], 'dispensary_handoffs_id_tenant_unique');
        });

        Schema::create('dispensary_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('dispensary_handoff_id');
            $table->unsignedBigInteger('treatment_plan_medicine_order_id');
            $table->unsignedBigInteger('medicine_catalogue_item_id');
            $table->string('medicine_order_public_id', 36);
            $table->string('medicine_code_snapshot', 64);
            $table->string('medicine_name_snapshot', 500);
            $table->string('strength_snapshot', 100)->nullable();
            $table->string('dosage_form_snapshot', 100)->nullable();
            $table->string('unit_snapshot', 100);
            $table->decimal('quantity_ordered', 12, 3);
            $table->string('dosage', 500);
            $table->string('frequency', 500);
            $table->string('duration', 500)->nullable();
            $table->string('route', 500)->nullable();
            $table->text('administration_instruction')->nullable();
            $table->text('precaution')->nullable();
            $table->unsignedInteger('allergy_profile_version_validated');
            $table->decimal('quantity_dispensed', 12, 3)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('reason', 40)->nullable();
            $table->unsignedBigInteger('handled_by_user_id')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->foreign(['dispensary_handoff_id', 'organisation_id', 'branch_id'], 'dispensary_items_handoff_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_handoffs')->restrictOnDelete();
            $table->foreign(['treatment_plan_medicine_order_id', 'organisation_id', 'branch_id'], 'dispensary_items_order_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('treatment_plan_medicine_orders')->restrictOnDelete();
            $table->foreign(['medicine_catalogue_item_id', 'organisation_id'], 'dispensary_items_catalogue_tenant_fk')->references(['id', 'organisation_id'])->on('medicine_catalogue_items')->restrictOnDelete();
            $table->foreign(['handled_by_user_id', 'organisation_id'], 'dispensary_items_handler_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['dispensary_handoff_id', 'treatment_plan_medicine_order_id'], 'dispensary_items_handoff_order_unique');
            $table->unique(['id', 'organisation_id', 'branch_id'], 'dispensary_items_id_tenant_unique');
        });

        Schema::create('dispensary_item_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('dispensary_case_id');
            $table->unsignedBigInteger('dispensary_handoff_id');
            $table->unsignedBigInteger('dispensary_item_id');
            $table->decimal('proposed_quantity_dispensed', 12, 3);
            $table->string('reason', 40);
            $table->string('status', 32);
            $table->unsignedInteger('expected_case_lock_version');
            $table->unsignedInteger('expected_item_lock_version');
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->foreign(['dispensary_case_id', 'organisation_id', 'branch_id'], 'dispensary_exceptions_case_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_cases')->restrictOnDelete();
            $table->foreign(['dispensary_handoff_id', 'organisation_id', 'branch_id'], 'dispensary_exceptions_handoff_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_handoffs')->restrictOnDelete();
            $table->foreign(['dispensary_item_id', 'organisation_id', 'branch_id'], 'dispensary_exceptions_item_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_items')->restrictOnDelete();
            $table->foreign(['created_by_user_id', 'organisation_id'], 'dispensary_exceptions_creator_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->foreign(['acknowledged_by_user_id', 'organisation_id'], 'dispensary_exceptions_ack_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->index(['dispensary_item_id', 'status'], 'dispensary_exceptions_item_status_index');
        });

        Schema::create('dispensary_item_batch_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('dispensary_item_id');
            $table->unsignedBigInteger('inventory_location_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->unsignedBigInteger('inventory_batch_id');
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
            $table->foreign(['dispensary_item_id', 'organisation_id', 'branch_id'], 'dispensary_allocations_item_tenant_fk')->references(['id', 'organisation_id', 'branch_id'])->on('dispensary_items')->restrictOnDelete();
            $table->foreign(['inventory_location_id', 'organisation_id', 'branch_id'], 'dispensary_allocations_location_tenant_branch_fk')->references(['id', 'organisation_id', 'branch_id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'dispensary_allocations_sku_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['inventory_batch_id', 'organisation_id', 'inventory_sku_id'], 'dispensary_allocations_batch_tenant_sku_fk')->references(['id', 'organisation_id', 'inventory_sku_id'])->on('inventory_batches')->restrictOnDelete();
            $table->unique(['dispensary_item_id', 'inventory_location_id', 'inventory_batch_id'], 'dispensary_allocations_tuple_unique');
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->unsignedBigInteger('inventory_batch_id');
            $table->unsignedBigInteger('source_location_id')->nullable();
            $table->unsignedBigInteger('destination_location_id')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->string('movement_type', 32);
            $table->unsignedBigInteger('dispensary_item_batch_allocation_id')->nullable()->unique();
            $table->string('reference_type', 80);
            $table->uuid('reference_public_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'stock_movements_sku_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['inventory_batch_id', 'organisation_id', 'inventory_sku_id'], 'stock_movements_batch_tenant_sku_fk')->references(['id', 'organisation_id', 'inventory_sku_id'])->on('inventory_batches')->restrictOnDelete();
            $table->foreign(['source_location_id', 'organisation_id'], 'stock_movements_source_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign(['destination_location_id', 'organisation_id'], 'stock_movements_destination_tenant_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign('dispensary_item_batch_allocation_id')->references('id')->on('dispensary_item_batch_allocations')->restrictOnDelete();
            $table->foreign(['actor_user_id', 'organisation_id'], 'stock_movements_actor_tenant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->index(['organisation_id', 'inventory_sku_id', 'inventory_batch_id', 'occurred_at'], 'stock_movements_ledger_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->addPostgresConstraints();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS enforce_completed_dispensary_allocations() CASCADE');
        }
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('dispensary_item_batch_allocations');
        Schema::dropIfExists('dispensary_item_exceptions');
        Schema::dropIfExists('dispensary_items');
        Schema::dropIfExists('dispensary_handoffs');
        Schema::dropIfExists('dispensary_cases');
        Schema::dropIfExists('inventory_stock_balances');
        Schema::dropIfExists('inventory_batches');
        Schema::table('inventory_locations', fn (Blueprint $table) => $table->dropForeign('inventory_locations_parent_tenant_fk'));
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('medicine_catalogue_aliases');
        Schema::dropIfExists('medicine_catalogue_inventory_skus');
        Schema::dropIfExists('inventory_skus');
        Schema::dropIfExists('inventory_items');
        Schema::table('queue_entries', fn (Blueprint $table) => $table->dropColumn(['removal_reason', 'returned_from_dispensary_at']));
        Schema::table('treatment_plan_medicine_orders', fn (Blueprint $table) => $table->dropUnique('medicine_orders_id_tenant_unique'));
        Schema::table('treatment_plans', fn (Blueprint $table) => $table->dropIndex('treatment_plans_tenant_status_index'));
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE treatment_plans DROP CONSTRAINT treatment_plans_status_check');
            DB::statement("ALTER TABLE treatment_plans ADD CONSTRAINT treatment_plans_status_check CHECK (status = 'in_progress')");
        }
    }

    private function addPostgresConstraints(): void
    {
        DB::statement('ALTER TABLE treatment_plans DROP CONSTRAINT treatment_plans_status_check');
        DB::statement("ALTER TABLE treatment_plans ADD CONSTRAINT treatment_plans_status_check CHECK (status IN ('in_progress', 'ready_for_dispensing'))");
        DB::statement("ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_removal_reason_check CHECK (status = 'removed' OR removal_reason IS NULL) NOT VALID");
        DB::statement("ALTER TABLE dispensary_cases ADD CONSTRAINT dispensary_cases_status_check CHECK (status IN ('pending', 'dispensing', 'returned_to_doctor', 'completed'))");
        DB::statement('ALTER TABLE dispensary_cases ADD CONSTRAINT dispensary_cases_version_positive CHECK (lock_version > 0)');
        DB::statement("ALTER TABLE dispensary_handoffs ADD CONSTRAINT dispensary_handoffs_status_check CHECK (status IN ('open', 'returned', 'completed'))");
        DB::statement("ALTER TABLE dispensary_handoffs ADD CONSTRAINT dispensary_handoffs_open_guard_check CHECK ((status = 'open' AND open_case_guard = dispensary_case_id) OR (status <> 'open' AND open_case_guard IS NULL))");
        DB::statement('ALTER TABLE dispensary_handoffs ADD CONSTRAINT dispensary_handoffs_versions_positive CHECK (attempt_number > 0 AND treatment_plan_lock_version_received > 0)');
        DB::statement("ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_state_check CHECK ((status = 'pending' AND quantity_dispensed IS NULL AND reason IS NULL) OR (status = 'dispensed' AND quantity_dispensed = quantity_ordered AND reason IS NULL) OR (status = 'partial' AND quantity_dispensed > 0 AND quantity_dispensed < quantity_ordered AND reason IS NOT NULL) OR (status = 'not_dispensed' AND quantity_dispensed = 0 AND reason IS NOT NULL))");
        DB::statement("ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_reason_check CHECK (reason IS NULL OR reason IN ('patient_declined', 'out_of_stock', 'clarification_required', 'other'))");
        DB::statement('ALTER TABLE dispensary_items ADD CONSTRAINT dispensary_items_versions_positive CHECK (lock_version > 0 AND allergy_profile_version_validated > 0 AND quantity_ordered > 0)');
        DB::statement("ALTER TABLE dispensary_item_exceptions ADD CONSTRAINT dispensary_exceptions_check CHECK (reason = 'patient_declined' AND status IN ('awaiting_acknowledgement', 'acknowledged', 'superseded') AND proposed_quantity_dispensed >= 0)");
        DB::statement('ALTER TABLE dispensary_item_batch_allocations ADD CONSTRAINT dispensary_allocations_quantity_positive CHECK (quantity > 0)');
        DB::statement("ALTER TABLE inventory_batches ADD CONSTRAINT inventory_batches_status_check CHECK (status IN ('available', 'quarantined', 'damaged'))");
        DB::statement('ALTER TABLE inventory_batches ADD CONSTRAINT inventory_batches_dates_check CHECK (received_at IS NULL OR received_at <= expiry_date)');
        DB::statement('ALTER TABLE inventory_stock_balances ADD CONSTRAINT stock_balances_nonnegative CHECK (quantity >= 0 AND lock_version > 0)');
        DB::statement('ALTER TABLE inventory_skus ADD CONSTRAINT inventory_skus_units_positive CHECK (pack_size > 0 AND unit_conversion > 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK (quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_completed_dispensary_allocations() RETURNS trigger AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM dispensary_cases c
        JOIN dispensary_handoffs h ON h.dispensary_case_id = c.id AND h.status = 'completed'
        JOIN dispensary_items i ON i.dispensary_handoff_id = h.id
        LEFT JOIN LATERAL (
            SELECT COALESCE(SUM(a.quantity), 0) AS allocated_quantity,
                   COUNT(a.id) AS allocation_count,
                   COUNT(m.id) AS movement_count,
                   COUNT(m.id) FILTER (
                       WHERE m.organisation_id = a.organisation_id
                         AND m.source_location_id = a.inventory_location_id
                         AND m.inventory_sku_id = a.inventory_sku_id
                         AND m.inventory_batch_id = a.inventory_batch_id
                         AND m.quantity = a.quantity
                   ) AS matching_movement_count
            FROM dispensary_item_batch_allocations a
            LEFT JOIN stock_movements m
              ON m.dispensary_item_batch_allocation_id = a.id
             AND m.movement_type = 'dispense'
            WHERE a.dispensary_item_id = i.id
        ) evidence ON true
        WHERE c.status = 'completed'
          AND (
            i.status = 'pending'
            OR evidence.allocated_quantity <> COALESCE(i.quantity_dispensed, 0)
            OR evidence.allocation_count <> evidence.movement_count
            OR evidence.allocation_count <> evidence.matching_movement_count
          )
    ) THEN
        RAISE EXCEPTION 'completed Dispensary allocation evidence is inconsistent' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
SQL);
        foreach (['dispensary_cases', 'dispensary_handoffs', 'dispensary_items', 'dispensary_item_batch_allocations', 'stock_movements'] as $table) {
            DB::statement("CREATE CONSTRAINT TRIGGER {$table}_completed_allocation_guard AFTER INSERT OR UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION enforce_completed_dispensary_allocations()");
        }
    }
};
