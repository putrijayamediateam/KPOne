<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-7: supplier returns tied to a verified receipt lot, approved by someone other than the requester,
 * dispatched through the stock ledger and closed with a credit-note reference. No payment engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_supplier_returns', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('return_number', 40);
            $t->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            $t->foreignId('goods_receipt_id')->constrained('inventory_goods_receipts')->restrictOnDelete();
            $t->foreignId('receipt_lot_id')->constrained('inventory_receipt_lots')->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_location_id')->constrained()->restrictOnDelete();
            $t->string('base_unit', 100);
            $t->decimal('quantity', 15, 3);
            $t->string('reason', 30);
            $t->string('supplier_reference', 100)->nullable();
            $t->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'return_number'], 'supplier_returns_number_unique');
            $t->index(['organisation_id', 'branch_id'], 'supplier_returns_branch_index');
            $t->index('receipt_lot_id', 'supplier_returns_lot_index');
        });
        Schema::create('inventory_supplier_return_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('return_id')->constrained('inventory_supplier_returns')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 20);
            $t->string('state', 20);
            $t->unsignedInteger('lock_version');
            $t->string('reason_code', 30)->nullable();
            $t->string('reference', 200)->nullable();
            $t->unsignedBigInteger('credit_amount_sen')->nullable();
            $t->foreignId('movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['return_id', 'lock_version'], 'supplier_return_actions_version_unique');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'supplier_return_actions_retry_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE inventory_supplier_returns ADD CONSTRAINT supplier_returns_shape CHECK (quantity > 0 AND reason IN ('damaged','short_expiry','quality_issue','recall','wrong_item','overstock_agreed','other_agreed') AND (supplier_reference IS NULL OR length(trim(supplier_reference)) > 0))");
        DB::statement("ALTER TABLE inventory_supplier_return_actions ADD CONSTRAINT supplier_return_actions_shape CHECK (lock_version > 0 AND ((action='requested' AND state='awaiting_approval') OR (action='approved' AND state='approved') OR (action='rejected' AND state='rejected' AND reason_code IN ('not_agreed_by_supplier','stock_still_usable','wrong_quantity','other')) OR (action='cancelled' AND state='cancelled') OR (action='dispatched' AND state='dispatched' AND reference IS NOT NULL AND length(trim(reference)) > 0) OR (action='credit_recorded' AND state='credited' AND reference IS NOT NULL AND length(trim(reference)) > 0)) AND ((action='dispatched') = (movement_id IS NOT NULL)) AND (action='rejected' OR reason_code IS NULL) AND (action='credit_recorded' OR credit_amount_sen IS NULL))");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK (quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL) OR (movement_type IN ('purchase_receipt','transfer_receipt','stocktake_gain','adjustment_in') AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type IN ('transfer_dispatch','stocktake_loss','adjustment_out','expiry_writeoff','stock_writeoff','supplier_return') AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_supplier_return_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Supplier return evidence is append-only' USING ERRCODE='P0001';
END; $$;
CREATE OR REPLACE FUNCTION guard_supplier_return() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE committed numeric;
BEGIN
 -- One lot at a time, so two requests cannot both pass the returnable check.
 PERFORM 1 FROM inventory_receipt_lots WHERE id=NEW.receipt_lot_id FOR UPDATE;
 IF NOT EXISTS (
  SELECT 1 FROM inventory_receipt_lots lot
  JOIN inventory_receipt_line_evidence line ON line.id=lot.line_evidence_id
  JOIN inventory_goods_receipts receipt ON receipt.id=line.goods_receipt_id
  JOIN inventory_receipt_delivery_evidence delivery ON delivery.goods_receipt_id=receipt.id
  JOIN inventory_purchase_orders po ON po.id=receipt.purchase_order_id
  JOIN inventory_locations loc ON loc.id=po.destination_location_id
  JOIN inventory_batches batch ON batch.id=lot.inventory_batch_id
  JOIN inventory_skus sku ON sku.id=batch.inventory_sku_id
  JOIN users requester ON requester.id=NEW.requester_user_id AND requester.organisation_id=NEW.organisation_id
  WHERE lot.id=NEW.receipt_lot_id AND receipt.id=NEW.goods_receipt_id AND receipt.organisation_id=NEW.organisation_id
   AND lot.outcome IN ('accepted','quarantined') AND delivery.supplier_id=NEW.supplier_id AND delivery.branch_id=NEW.branch_id
   AND loc.id=NEW.inventory_location_id AND loc.branch_id=NEW.branch_id AND batch.id=NEW.inventory_batch_id
   AND sku.id=NEW.inventory_sku_id AND sku.stock_unit=NEW.base_unit
   AND NOT EXISTS (SELECT 1 FROM inventory_receipt_corrections c WHERE c.goods_receipt_id=receipt.id)
 ) THEN RAISE EXCEPTION 'Supplier return must come from a verified, uncorrected receipt lot at this branch' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(SUM(r.quantity),0) INTO committed FROM inventory_supplier_returns r
  WHERE r.receipt_lot_id=NEW.receipt_lot_id AND NOT EXISTS (
   SELECT 1 FROM inventory_supplier_return_actions a WHERE a.return_id=r.id AND a.state IN ('rejected','cancelled'));
 IF committed + NEW.quantity > (SELECT quantity FROM inventory_receipt_lots WHERE id=NEW.receipt_lot_id) THEN
  RAISE EXCEPTION 'Supplier return exceeds the returnable quantity of the receipt lot' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION guard_supplier_return_action() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r inventory_supplier_returns%ROWTYPE; previous inventory_supplier_return_actions%ROWTYPE;
BEGIN
 SELECT * INTO r FROM inventory_supplier_returns WHERE id=NEW.return_id FOR UPDATE;
 IF r.id IS NULL OR r.organisation_id<>NEW.organisation_id OR NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.actor_user_id AND organisation_id=r.organisation_id) THEN
  RAISE EXCEPTION 'Invalid supplier return action scope' USING ERRCODE='23514';
 END IF;
 SELECT * INTO previous FROM inventory_supplier_return_actions WHERE return_id=r.id ORDER BY lock_version DESC LIMIT 1;
 IF NEW.lock_version<>COALESCE(previous.lock_version,0)+1 THEN RAISE EXCEPTION 'Invalid supplier return version' USING ERRCODE='23514'; END IF;
 IF NEW.action IN ('approved','dispatched') THEN
  -- The receipt correction path locks the same receipt row, so a reversed receipt can never also be returned.
  PERFORM 1 FROM inventory_goods_receipts WHERE id=r.goods_receipt_id FOR UPDATE;
  IF EXISTS (SELECT 1 FROM inventory_receipt_corrections c WHERE c.goods_receipt_id=r.goods_receipt_id) THEN
   RAISE EXCEPTION 'The receipt of this supplier return was reversed' USING ERRCODE='23514';
  END IF;
 END IF;
 IF NEW.action='requested' THEN
  IF previous.id IS NOT NULL OR NEW.actor_user_id<>r.requester_user_id THEN RAISE EXCEPTION 'Invalid supplier return request' USING ERRCODE='23514'; END IF;
 ELSIF NEW.action IN ('approved','rejected') THEN
  IF previous.state IS DISTINCT FROM 'awaiting_approval' THEN RAISE EXCEPTION 'Supplier return is not awaiting approval' USING ERRCODE='23514'; END IF;
  IF NEW.actor_user_id=r.requester_user_id THEN RAISE EXCEPTION 'The requester may not approve or reject their own supplier return' USING ERRCODE='23514'; END IF;
 ELSIF NEW.action='cancelled' THEN
  IF previous.state NOT IN ('awaiting_approval','approved') OR NEW.actor_user_id<>r.requester_user_id THEN RAISE EXCEPTION 'Only the requester may cancel before dispatch' USING ERRCODE='23514'; END IF;
 ELSIF NEW.action='dispatched' THEN
  IF previous.state IS DISTINCT FROM 'approved' THEN RAISE EXCEPTION 'Supplier return must be approved before dispatch' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.id=NEW.movement_id AND m.organisation_id=r.organisation_id AND m.movement_type='supplier_return'
   AND m.reference_type='inventory_supplier_return' AND m.reference_public_id=r.public_id AND m.source_location_id=r.inventory_location_id
   AND m.inventory_sku_id=r.inventory_sku_id AND m.inventory_batch_id=r.inventory_batch_id AND m.quantity=r.quantity AND m.actor_user_id=NEW.actor_user_id) THEN
   RAISE EXCEPTION 'Dispatch requires its linked supplier return movement' USING ERRCODE='23514';
  END IF;
 ELSE
  IF previous.state IS DISTINCT FROM 'dispatched' THEN RAISE EXCEPTION 'A credit note is recorded only after dispatch' USING ERRCODE='23514'; END IF;
  PERFORM 1 FROM inventory_suppliers WHERE id=r.supplier_id FOR UPDATE;
  IF EXISTS (SELECT 1 FROM inventory_supplier_return_actions a JOIN inventory_supplier_returns o ON o.id=a.return_id
   WHERE a.action='credit_recorded' AND o.organisation_id=r.organisation_id AND o.supplier_id=r.supplier_id AND upper(trim(a.reference))=upper(trim(NEW.reference))) THEN
   RAISE EXCEPTION 'This supplier credit-note reference is already recorded' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION require_supplier_return_dispatch() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.reference_type<>'inventory_supplier_return' OR NOT EXISTS (SELECT 1 FROM inventory_supplier_return_actions a JOIN inventory_supplier_returns r ON r.id=a.return_id
  WHERE a.action='dispatched' AND a.movement_id=NEW.id AND r.public_id=NEW.reference_public_id) THEN
  RAISE EXCEPTION 'Supplier return movement requires approved dispatch evidence' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER supplier_returns_insert BEFORE INSERT ON inventory_supplier_returns FOR EACH ROW EXECUTE FUNCTION guard_supplier_return();
CREATE TRIGGER supplier_return_actions_insert BEFORE INSERT ON inventory_supplier_return_actions FOR EACH ROW EXECUTE FUNCTION guard_supplier_return_action();
CREATE CONSTRAINT TRIGGER supplier_return_movement_evidence AFTER INSERT ON stock_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.movement_type = 'supplier_return' OR NEW.reference_type = 'inventory_supplier_return') EXECUTE FUNCTION require_supplier_return_dispatch();
SQL);
        foreach (['inventory_supplier_returns', 'inventory_supplier_return_actions'] as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION guard_supplier_return_immutable()');
        }
    }

    public function down(): void
    {
        // Retain supplier return evidence and its movement type.
    }
};
