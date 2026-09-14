<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->string('code', 64);
            $table->string('name', 200);
            $table->string('contact_name', 200)->nullable();
            $table->string('business_email', 254)->nullable();
            $table->string('business_phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $table->unique(['organisation_id', 'code'], 'inventory_suppliers_org_code_unique');
            $table->unique(['id', 'organisation_id'], 'inventory_suppliers_id_org_unique');
        });

        Schema::create('inventory_purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('destination_location_id');
            $table->string('order_number', 40);
            $table->string('status', 32)->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('submitted_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 300)->nullable();
            $table->timestamps();
            $table->foreign(['supplier_id', 'organisation_id'], 'purchase_orders_supplier_fk')->references(['id', 'organisation_id'])->on('inventory_suppliers')->restrictOnDelete();
            $table->foreign(['destination_location_id', 'organisation_id'], 'purchase_orders_location_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            foreach (['created_by_user_id', 'submitted_by_user_id', 'approved_by_user_id', 'closed_by_user_id', 'cancelled_by_user_id'] as $column) {
                $table->foreign([$column, 'organisation_id'], 'purchase_orders_'.str_replace('_user_id', '', $column).'_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $table->unique(['organisation_id', 'order_number'], 'purchase_orders_org_number_unique');
            $table->unique(['id', 'organisation_id'], 'purchase_orders_id_org_unique');
            $table->index(['organisation_id', 'status', 'created_at'], 'purchase_orders_status_index');
        });

        Schema::create('inventory_purchase_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->decimal('ordered_quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->default(0);
            $table->timestamps();
            $table->foreign(['purchase_order_id', 'organisation_id'], 'purchase_order_lines_order_fk')->references(['id', 'organisation_id'])->on('inventory_purchase_orders')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'purchase_order_lines_sku_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->unique(['purchase_order_id', 'inventory_sku_id'], 'purchase_order_lines_order_sku_unique');
            $table->unique(['id', 'organisation_id'], 'purchase_order_lines_id_org_unique');
        });

        Schema::create('inventory_goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('received_by_user_id');
            $table->timestamp('received_at');
            $table->timestamps();
            $table->foreign(['purchase_order_id', 'organisation_id'], 'goods_receipts_order_fk')->references(['id', 'organisation_id'])->on('inventory_purchase_orders')->restrictOnDelete();
            $table->foreign(['received_by_user_id', 'organisation_id'], 'goods_receipts_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'idempotency_key'], 'goods_receipts_idempotency_unique');
            $table->unique(['id', 'organisation_id'], 'goods_receipts_id_org_unique');
        });

        Schema::create('inventory_goods_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('goods_receipt_id');
            $table->unsignedBigInteger('purchase_order_line_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->unsignedBigInteger('inventory_batch_id');
            $table->decimal('quantity', 15, 3);
            $table->timestamps();
            $table->foreign(['goods_receipt_id', 'organisation_id'], 'goods_receipt_lines_receipt_fk')->references(['id', 'organisation_id'])->on('inventory_goods_receipts')->restrictOnDelete();
            $table->foreign(['purchase_order_line_id', 'organisation_id'], 'goods_receipt_lines_order_line_fk')->references(['id', 'organisation_id'])->on('inventory_purchase_order_lines')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'goods_receipt_lines_sku_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['inventory_batch_id', 'organisation_id', 'inventory_sku_id'], 'goods_receipt_lines_batch_fk')->references(['id', 'organisation_id', 'inventory_sku_id'])->on('inventory_batches')->restrictOnDelete();
            $table->unique(['goods_receipt_id', 'purchase_order_line_id'], 'goods_receipt_lines_receipt_order_line_unique');
        });

        Schema::create('inventory_stock_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('requesting_branch_id');
            $table->unsignedBigInteger('source_location_id');
            $table->unsignedBigInteger('destination_location_id');
            $table->string('request_number', 40);
            $table->string('status', 24)->default('requested');
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 300)->nullable();
            $table->unsignedBigInteger('dispatched_by_user_id')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->uuid('dispatch_idempotency_key')->nullable();
            $table->unsignedBigInteger('received_by_user_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->uuid('receive_idempotency_key')->nullable();
            $table->timestamps();
            $table->foreign(['requesting_branch_id', 'organisation_id'], 'stock_requests_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['source_location_id', 'organisation_id'], 'stock_requests_source_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign(['destination_location_id', 'organisation_id'], 'stock_requests_destination_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            foreach (['requested_by_user_id', 'decided_by_user_id', 'dispatched_by_user_id', 'received_by_user_id'] as $column) {
                $table->foreign([$column, 'organisation_id'], 'stock_requests_'.str_replace('_user_id', '', $column).'_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $table->unique(['organisation_id', 'request_number'], 'stock_requests_org_number_unique');
            $table->unique(['organisation_id', 'dispatch_idempotency_key'], 'stock_requests_dispatch_key_unique');
            $table->unique(['organisation_id', 'receive_idempotency_key'], 'stock_requests_receive_key_unique');
            $table->unique(['id', 'organisation_id'], 'stock_requests_id_org_unique');
            $table->index(['organisation_id', 'requesting_branch_id', 'status'], 'stock_requests_status_index');
        });

        Schema::create('inventory_stock_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('stock_request_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->unsignedBigInteger('inventory_batch_id')->nullable();
            $table->decimal('requested_quantity', 15, 3);
            $table->decimal('dispatched_quantity', 15, 3)->nullable();
            $table->decimal('received_quantity', 15, 3)->nullable();
            $table->timestamps();
            $table->foreign(['stock_request_id', 'organisation_id'], 'stock_request_lines_request_fk')->references(['id', 'organisation_id'])->on('inventory_stock_requests')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'stock_request_lines_sku_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['inventory_batch_id', 'organisation_id', 'inventory_sku_id'], 'stock_request_lines_batch_fk')->references(['id', 'organisation_id', 'inventory_sku_id'])->on('inventory_batches')->restrictOnDelete();
            $table->unique(['stock_request_id', 'inventory_sku_id'], 'stock_request_lines_request_sku_unique');
        });

        Schema::create('inventory_stocktakes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('inventory_location_id');
            $table->string('stocktake_number', 40);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedBigInteger('created_by_user_id');
            $table->unsignedBigInteger('counted_by_user_id')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->foreign(['inventory_location_id', 'organisation_id'], 'stocktakes_location_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            foreach (['created_by_user_id', 'counted_by_user_id', 'posted_by_user_id', 'cancelled_by_user_id'] as $column) {
                $table->foreign([$column, 'organisation_id'], 'stocktakes_'.str_replace('_user_id', '', $column).'_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $table->unique(['organisation_id', 'stocktake_number'], 'stocktakes_org_number_unique');
            $table->unique(['id', 'organisation_id'], 'stocktakes_id_org_unique');
            $table->index(['organisation_id', 'status', 'created_at'], 'stocktakes_status_index');
        });

        Schema::create('inventory_stocktake_lines', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('stocktake_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->unsignedBigInteger('inventory_batch_id');
            $table->decimal('expected_quantity', 15, 3);
            $table->unsignedInteger('expected_balance_lock_version');
            $table->decimal('physical_quantity', 15, 3)->nullable();
            $table->decimal('variance_quantity', 15, 3)->nullable();
            $table->timestamps();
            $table->foreign(['stocktake_id', 'organisation_id'], 'stocktake_lines_stocktake_fk')->references(['id', 'organisation_id'])->on('inventory_stocktakes')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'stocktake_lines_sku_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['inventory_batch_id', 'organisation_id', 'inventory_sku_id'], 'stocktake_lines_batch_fk')->references(['id', 'organisation_id', 'inventory_sku_id'])->on('inventory_batches')->restrictOnDelete();
            $table->unique(['stocktake_id', 'inventory_sku_id', 'inventory_batch_id'], 'stocktake_lines_identity_unique');
        });

        Schema::create('inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->unsignedBigInteger('inventory_location_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->unsignedBigInteger('inventory_batch_id');
            $table->string('direction', 3);
            $table->decimal('quantity', 15, 3);
            $table->string('reason_code', 40);
            $table->string('reason_note', 300)->nullable();
            $table->unsignedBigInteger('posted_by_user_id');
            $table->timestamp('posted_at');
            $table->timestamps();
            $table->foreign(['inventory_location_id', 'organisation_id'], 'adjustments_location_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'adjustments_sku_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['inventory_batch_id', 'organisation_id', 'inventory_sku_id'], 'adjustments_batch_fk')->references(['id', 'organisation_id', 'inventory_sku_id'])->on('inventory_batches')->restrictOnDelete();
            $table->foreign(['posted_by_user_id', 'organisation_id'], 'adjustments_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'idempotency_key'], 'adjustments_idempotency_unique');
            $table->unique(['id', 'organisation_id'], 'adjustments_id_org_unique');
        });

        Schema::create('inventory_reorder_levels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('inventory_location_id');
            $table->unsignedBigInteger('inventory_sku_id');
            $table->decimal('reorder_level', 15, 3);
            $table->unsignedBigInteger('updated_by_user_id');
            $table->timestamps();
            $table->foreign(['inventory_location_id', 'organisation_id'], 'reorder_levels_location_fk')->references(['id', 'organisation_id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign(['inventory_sku_id', 'organisation_id'], 'reorder_levels_sku_fk')->references(['id', 'organisation_id'])->on('inventory_skus')->restrictOnDelete();
            $table->foreign(['updated_by_user_id', 'organisation_id'], 'reorder_levels_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $table->unique(['organisation_id', 'inventory_location_id', 'inventory_sku_id'], 'reorder_levels_identity_unique');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->addPostgresConstraints();
        }
    }

    public function down(): void
    {
        foreach ($this->operationTables() as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Inventory operations rollback refused because retained evidence exists in [{$table}].");
            }
        }

        $newMovementExists = DB::table('stock_movements')->whereIn('movement_type', $this->newMovementTypes())->exists();
        if ($newMovementExists) {
            throw new RuntimeException('Inventory operations with posted movement evidence cannot be rolled back destructively.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_shape_check');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK (quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL)))");
        }

        foreach ($this->operationTables() as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function addPostgresConstraints(): void
    {
        DB::statement("ALTER TABLE inventory_purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft', 'submitted', 'approved', 'partially_received', 'fully_received', 'closed', 'cancelled') AND lock_version > 0)");
        DB::statement('ALTER TABLE inventory_purchase_order_lines ADD CONSTRAINT purchase_order_lines_quantity_check CHECK (ordered_quantity > 0 AND received_quantity >= 0 AND received_quantity <= ordered_quantity)');
        DB::statement('ALTER TABLE inventory_goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_quantity_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE inventory_stock_requests ADD CONSTRAINT stock_requests_status_check CHECK (status IN ('requested', 'approved', 'rejected', 'dispatched', 'received') AND lock_version > 0 AND source_location_id <> destination_location_id)");
        DB::statement('ALTER TABLE inventory_stock_request_lines ADD CONSTRAINT stock_request_lines_quantity_check CHECK (requested_quantity > 0 AND (dispatched_quantity IS NULL OR dispatched_quantity > 0) AND (received_quantity IS NULL OR received_quantity > 0))');
        DB::statement("ALTER TABLE inventory_stocktakes ADD CONSTRAINT stocktakes_status_check CHECK (status IN ('draft', 'counting', 'review', 'posted', 'cancelled') AND lock_version > 0)");
        DB::statement('ALTER TABLE inventory_stocktake_lines ADD CONSTRAINT stocktake_lines_quantity_check CHECK (expected_quantity >= 0 AND expected_balance_lock_version > 0 AND (physical_quantity IS NULL OR physical_quantity >= 0))');
        DB::statement("ALTER TABLE inventory_adjustments ADD CONSTRAINT adjustments_shape_check CHECK (direction IN ('in', 'out') AND quantity > 0 AND reason_code IN ('correction', 'damage', 'found_stock', 'count_variance', 'other'))");
        DB::statement('ALTER TABLE inventory_reorder_levels ADD CONSTRAINT reorder_levels_nonnegative_check CHECK (reorder_level >= 0)');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK (quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL) OR (movement_type IN ('purchase_receipt', 'transfer_receipt', 'stocktake_gain', 'adjustment_in') AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type IN ('transfer_dispatch', 'stocktake_loss', 'adjustment_out') AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NULL)))");
    }

    /** @return list<string> */
    private function newMovementTypes(): array
    {
        return ['purchase_receipt', 'transfer_dispatch', 'transfer_receipt', 'stocktake_gain', 'stocktake_loss', 'adjustment_in', 'adjustment_out'];
    }

    /** @return list<string> */
    private function operationTables(): array
    {
        return [
            'inventory_reorder_levels',
            'inventory_adjustments',
            'inventory_stocktake_lines',
            'inventory_stocktakes',
            'inventory_stock_request_lines',
            'inventory_stock_requests',
            'inventory_goods_receipt_lines',
            'inventory_goods_receipts',
            'inventory_purchase_order_lines',
            'inventory_purchase_orders',
            'inventory_suppliers',
        ];
    }
};
