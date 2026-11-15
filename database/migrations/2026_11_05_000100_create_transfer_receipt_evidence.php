<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-6: actual receipt lines for two-stage transfers and independently resolved differences.
 * Additive only: existing Stock Request rows, statuses and movements are not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_requests', function (Blueprint $t): void {
            $t->string('receive_payload_hash', 64)->nullable();
        });
        Schema::create('inventory_transfer_receipt_lines', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_request_id')->constrained('inventory_stock_requests')->restrictOnDelete();
            $t->foreignId('stock_request_line_id')->unique()->constrained('inventory_stock_request_lines')->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->string('base_unit', 100);
            $t->decimal('dispatched_quantity', 15, 3);
            $t->decimal('accepted_quantity', 15, 3);
            $t->decimal('short_quantity', 15, 3);
            $t->decimal('damaged_quantity', 15, 3);
            $t->decimal('extra_quantity', 15, 3);
            $t->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->index(['organisation_id', 'stock_request_id'], 'transfer_receipt_lines_request_index');
        });
        Schema::create('inventory_transfer_differences', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('stock_request_id')->constrained('inventory_stock_requests')->restrictOnDelete();
            $t->foreignId('receipt_line_id')->constrained('inventory_transfer_receipt_lines')->restrictOnDelete();
            $t->string('kind', 16);
            $t->decimal('quantity', 15, 3);
            $t->foreignId('received_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->unique(['receipt_line_id', 'kind'], 'transfer_differences_line_kind_unique');
            $t->index(['organisation_id', 'stock_request_id'], 'transfer_differences_request_index');
        });
        Schema::create('inventory_transfer_difference_resolutions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('difference_id')->unique()->constrained('inventory_transfer_differences')->restrictOnDelete();
            $t->string('outcome', 32);
            $t->decimal('quantity', 15, 3);
            $t->string('reference', 200)->nullable();
            $t->foreignId('resolved_by_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'resolved_by_user_id', 'retry_key'], 'transfer_resolution_retry_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inventory_transfer_receipt_lines ADD CONSTRAINT transfer_receipt_lines_reconcile CHECK (dispatched_quantity > 0 AND accepted_quantity >= 0 AND short_quantity >= 0 AND damaged_quantity >= 0 AND extra_quantity >= 0 AND accepted_quantity + short_quantity + damaged_quantity = dispatched_quantity AND ((accepted_quantity > 0) = (movement_id IS NOT NULL)))');
        DB::statement("ALTER TABLE inventory_transfer_differences ADD CONSTRAINT transfer_differences_shape CHECK (kind IN ('short','damaged','extra') AND quantity > 0)");
        DB::statement("ALTER TABLE inventory_transfer_difference_resolutions ADD CONSTRAINT transfer_resolutions_shape CHECK (quantity > 0 AND outcome IN ('lost_in_transit','arrived_later','written_off','accepted_after_inspection','returned_to_source','recorded_by_stock_count') AND ((outcome IN ('arrived_later','accepted_after_inspection')) = (movement_id IS NOT NULL)) AND reference IS NOT NULL AND length(trim(reference)) > 0)");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_transfer_receipt_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Transfer receipt evidence is append-only' USING ERRCODE='P0001';
END; $$;
CREATE OR REPLACE FUNCTION guard_transfer_receipt_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (
  SELECT 1 FROM inventory_stock_requests r
  JOIN inventory_stock_request_lines l ON l.stock_request_id=r.id AND l.organisation_id=r.organisation_id
  JOIN inventory_skus s ON s.id=l.inventory_sku_id AND s.organisation_id=r.organisation_id
  JOIN users u ON u.id=NEW.received_by_user_id AND u.organisation_id=r.organisation_id
  WHERE r.id=NEW.stock_request_id AND r.organisation_id=NEW.organisation_id AND l.id=NEW.stock_request_line_id
   AND l.inventory_sku_id=NEW.inventory_sku_id AND l.inventory_batch_id=NEW.inventory_batch_id
   AND l.dispatched_quantity=NEW.dispatched_quantity AND s.stock_unit=NEW.base_unit
   AND r.dispatched_at IS NOT NULL
 ) THEN RAISE EXCEPTION 'Transfer receipt line does not match the dispatch' USING ERRCODE='23514'; END IF;
 IF NEW.movement_id IS NOT NULL AND NOT EXISTS (
  SELECT 1 FROM stock_movements m JOIN inventory_stock_requests r ON r.id=NEW.stock_request_id
  WHERE m.id=NEW.movement_id AND m.organisation_id=NEW.organisation_id AND m.movement_type='transfer_receipt'
   AND m.reference_type='inventory_stock_request' AND m.reference_public_id=r.public_id
   AND m.destination_location_id=r.destination_location_id AND m.inventory_sku_id=NEW.inventory_sku_id
   AND m.inventory_batch_id=NEW.inventory_batch_id AND m.quantity=NEW.accepted_quantity AND m.actor_user_id=NEW.received_by_user_id
 ) THEN RAISE EXCEPTION 'Accepted quantity requires its linked receipt movement' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION guard_transfer_difference() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE line inventory_transfer_receipt_lines%ROWTYPE; expected numeric;
BEGIN
 SELECT * INTO line FROM inventory_transfer_receipt_lines WHERE id=NEW.receipt_line_id;
 IF line.id IS NULL OR line.organisation_id<>NEW.organisation_id OR line.stock_request_id<>NEW.stock_request_id OR line.received_by_user_id<>NEW.received_by_user_id THEN
  RAISE EXCEPTION 'Invalid transfer difference scope' USING ERRCODE='23514';
 END IF;
 expected := CASE NEW.kind WHEN 'short' THEN line.short_quantity WHEN 'damaged' THEN line.damaged_quantity ELSE line.extra_quantity END;
 IF expected IS DISTINCT FROM NEW.quantity THEN RAISE EXCEPTION 'Transfer difference must equal the recorded receipt line' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION guard_transfer_difference_resolution() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE d inventory_transfer_differences%ROWTYPE; line inventory_transfer_receipt_lines%ROWTYPE; r inventory_stock_requests%ROWTYPE;
BEGIN
 SELECT * INTO d FROM inventory_transfer_differences WHERE id=NEW.difference_id FOR UPDATE;
 IF d.id IS NULL OR d.organisation_id<>NEW.organisation_id OR NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.resolved_by_user_id AND organisation_id=d.organisation_id) THEN
  RAISE EXCEPTION 'Invalid transfer resolution scope' USING ERRCODE='23514';
 END IF;
 IF NEW.resolved_by_user_id=d.received_by_user_id THEN RAISE EXCEPTION 'The receiver may not resolve their own transfer difference' USING ERRCODE='23514'; END IF;
 IF NEW.quantity<>d.quantity THEN RAISE EXCEPTION 'Resolution must cover the whole difference' USING ERRCODE='23514'; END IF;
 IF NOT ((d.kind='short' AND NEW.outcome IN ('lost_in_transit','arrived_later'))
      OR (d.kind='damaged' AND NEW.outcome IN ('written_off','accepted_after_inspection'))
      OR (d.kind='extra' AND NEW.outcome IN ('returned_to_source','recorded_by_stock_count'))) THEN
  RAISE EXCEPTION 'Outcome is not valid for this difference' USING ERRCODE='23514';
 END IF;
 IF NEW.movement_id IS NOT NULL THEN
  SELECT * INTO line FROM inventory_transfer_receipt_lines WHERE id=d.receipt_line_id;
  SELECT * INTO r FROM inventory_stock_requests WHERE id=d.stock_request_id;
  IF NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.id=NEW.movement_id AND m.organisation_id=d.organisation_id AND m.movement_type='transfer_receipt'
   AND m.reference_type='inventory_stock_request' AND m.reference_public_id=r.public_id AND m.destination_location_id=r.destination_location_id
   AND m.inventory_sku_id=line.inventory_sku_id AND m.inventory_batch_id=line.inventory_batch_id AND m.quantity=NEW.quantity AND m.actor_user_id=NEW.resolved_by_user_id) THEN
   RAISE EXCEPTION 'Accepted resolution requires its linked receipt movement' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION require_transfer_receipt_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.movement_type='transfer_receipt' AND NOT EXISTS (SELECT 1 FROM inventory_transfer_receipt_lines WHERE movement_id=NEW.id)
    AND NOT EXISTS (SELECT 1 FROM inventory_transfer_difference_resolutions WHERE movement_id=NEW.id) THEN
  RAISE EXCEPTION 'Transfer receipt movement requires receipt line or resolution evidence' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER transfer_receipt_lines_insert BEFORE INSERT ON inventory_transfer_receipt_lines FOR EACH ROW EXECUTE FUNCTION guard_transfer_receipt_line();
CREATE TRIGGER transfer_differences_insert BEFORE INSERT ON inventory_transfer_differences FOR EACH ROW EXECUTE FUNCTION guard_transfer_difference();
CREATE TRIGGER transfer_resolutions_insert BEFORE INSERT ON inventory_transfer_difference_resolutions FOR EACH ROW EXECUTE FUNCTION guard_transfer_difference_resolution();
CREATE OR REPLACE FUNCTION require_transfer_difference_rows() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (NEW.short_quantity > 0) <> EXISTS (SELECT 1 FROM inventory_transfer_differences WHERE receipt_line_id=NEW.id AND kind='short')
    OR (NEW.damaged_quantity > 0) <> EXISTS (SELECT 1 FROM inventory_transfer_differences WHERE receipt_line_id=NEW.id AND kind='damaged')
    OR (NEW.extra_quantity > 0) <> EXISTS (SELECT 1 FROM inventory_transfer_differences WHERE receipt_line_id=NEW.id AND kind='extra') THEN
  RAISE EXCEPTION 'Every short, damaged or extra quantity needs exactly one open difference' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER transfer_receipt_movement_evidence AFTER INSERT ON stock_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.movement_type = 'transfer_receipt') EXECUTE FUNCTION require_transfer_receipt_evidence();
CREATE CONSTRAINT TRIGGER transfer_receipt_line_differences AFTER INSERT ON inventory_transfer_receipt_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_transfer_difference_rows();
SQL);
        foreach (['inventory_transfer_receipt_lines', 'inventory_transfer_differences', 'inventory_transfer_difference_resolutions'] as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION guard_transfer_receipt_immutable()');
        }
    }

    public function down(): void
    {
        // Retain transfer receipt lines, differences and resolutions as stock evidence.
    }
};
