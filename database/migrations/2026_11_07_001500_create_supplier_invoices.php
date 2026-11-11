<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-22 (approved by B.E.R.N.I.S.S for the owner, 2026-11-11): a supplier's invoice recorded against a purchase
 * order and compared, line by line, with what was ordered and received. KPOne pays nothing. Lines are frozen when
 * recorded; the header changes only once, when a mismatch is accepted or rejected by someone who did not record it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_supplier_invoices', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('inventory_purchase_orders')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            $t->string('invoice_number', 60);
            $t->string('invoice_key', 60);
            $t->date('invoice_date');
            $t->unsignedBigInteger('lines_total_sen');
            $t->unsignedBigInteger('stated_total_sen')->nullable();
            $t->string('status', 10);
            $t->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at');
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unsignedInteger('lock_version')->default(1);
            $t->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_reason', 30)->nullable();
            $t->string('decision_note', 200)->nullable();
            $t->unique(['organisation_id', 'idempotency_key'], 'supplier_invoices_idempotency_unique');
            $t->index(['branch_id', 'status', 'recorded_at'], 'supplier_invoices_branch_status_index');
        });
        Schema::create('inventory_supplier_invoice_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('supplier_invoice_id')->constrained('inventory_supplier_invoices')->restrictOnDelete();
            $t->foreignId('purchase_order_line_id')->constrained('inventory_purchase_order_lines')->restrictOnDelete();
            $t->decimal('ordered_quantity', 15, 3);
            $t->decimal('received_quantity', 15, 3);
            $t->decimal('invoiced_quantity', 15, 3);
            $t->unsignedBigInteger('invoiced_total_sen');
            $t->unsignedBigInteger('expected_total_sen')->nullable();
            $t->string('price_basis', 10);
            $t->string('result', 20);
            $t->unique(['supplier_invoice_id', 'purchase_order_line_id'], 'supplier_invoice_lines_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
-- One live invoice per supplier and number; a rejected one may be recorded again, corrected.
CREATE UNIQUE INDEX supplier_invoices_number_live ON inventory_supplier_invoices (organisation_id, supplier_id, invoice_key) WHERE status <> 'rejected';
-- v1: one live invoice per purchase order.
CREATE UNIQUE INDEX supplier_invoices_order_live ON inventory_supplier_invoices (purchase_order_id) WHERE status <> 'rejected';
ALTER TABLE inventory_supplier_invoices ADD CONSTRAINT supplier_invoices_shape CHECK (
 status IN ('matched', 'waiting', 'accepted', 'rejected')
 AND length(btrim(invoice_number)) BETWEEN 1 AND 60 AND invoice_key = upper(btrim(invoice_number))
 AND lock_version > 0
 AND (stated_total_sen IS NULL OR stated_total_sen = lines_total_sen)
 AND ((status IN ('accepted', 'rejected')) = (decided_by_user_id IS NOT NULL))
 AND ((decided_by_user_id IS NULL) = (decided_at IS NULL) AND (decided_by_user_id IS NULL) = (decision_reason IS NULL))
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> recorded_by_user_id)
 AND (decision_reason IS NULL
   OR (status = 'accepted' AND decision_reason IN ('price_agreed', 'credit_note_expected', 'rounding', 'other'))
   OR (status = 'rejected' AND decision_reason IN ('wrong_quantity', 'wrong_price', 'not_our_order', 'other')))
 AND (decision_note IS NULL OR (decided_by_user_id IS NOT NULL AND length(btrim(decision_note)) BETWEEN 1 AND 200))
 AND (decision_reason IS DISTINCT FROM 'other' OR decision_note IS NOT NULL)
);
ALTER TABLE inventory_supplier_invoice_lines ADD CONSTRAINT supplier_invoice_lines_shape CHECK (
 ordered_quantity > 0 AND received_quantity >= 0 AND invoiced_quantity >= 0
 AND price_basis IN ('receipt', 'estimate', 'none')
 AND (price_basis = 'none') = (expected_total_sen IS NULL)
 AND result IN ('matches', 'quantity_differs', 'price_differs', 'both_differ', 'price_unknown')
);
CREATE OR REPLACE FUNCTION guard_supplier_invoice() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Supplier invoices are kept' USING ERRCODE='23514'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status NOT IN ('matched', 'waiting') OR NEW.decided_by_user_id IS NOT NULL OR NEW.lock_version <> 1 THEN
   RAISE EXCEPTION 'A supplier invoice starts matched or waiting' USING ERRCODE='23514';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM inventory_purchase_orders po JOIN inventory_locations l ON l.id = po.destination_location_id
    WHERE po.id = NEW.purchase_order_id AND po.organisation_id = NEW.organisation_id AND po.supplier_id = NEW.supplier_id AND l.branch_id = NEW.branch_id) THEN
   RAISE EXCEPTION 'A supplier invoice belongs to its purchase order and supplier' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
 END IF;
 -- The only change: a waiting invoice is accepted or rejected once, by someone who did not record it.
 IF OLD.status <> 'waiting' OR NEW.status NOT IN ('accepted', 'rejected') OR NEW.lock_version <> OLD.lock_version + 1
  OR (NEW.id, NEW.public_id, NEW.organisation_id, NEW.branch_id, NEW.purchase_order_id, NEW.supplier_id, NEW.invoice_number, NEW.invoice_key, NEW.invoice_date,
      NEW.lines_total_sen, NEW.stated_total_sen, NEW.recorded_by_user_id, NEW.recorded_at, NEW.idempotency_key, NEW.request_hash)
   IS DISTINCT FROM (OLD.id, OLD.public_id, OLD.organisation_id, OLD.branch_id, OLD.purchase_order_id, OLD.supplier_id, OLD.invoice_number, OLD.invoice_key, OLD.invoice_date,
      OLD.lines_total_sen, OLD.stated_total_sen, OLD.recorded_by_user_id, OLD.recorded_at, OLD.idempotency_key, OLD.request_hash) THEN
  RAISE EXCEPTION 'Only a waiting supplier invoice is decided, once' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER supplier_invoices_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_supplier_invoices FOR EACH ROW EXECUTE FUNCTION guard_supplier_invoice();
CREATE OR REPLACE FUNCTION guard_supplier_invoice_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Supplier invoice lines are frozen when recorded' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_supplier_invoices i JOIN inventory_purchase_order_lines l ON l.purchase_order_id = i.purchase_order_id
   WHERE i.id = NEW.supplier_invoice_id AND l.id = NEW.purchase_order_line_id) THEN
  RAISE EXCEPTION 'A supplier invoice line belongs to the invoice''s purchase order' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER supplier_invoice_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_supplier_invoice_lines FOR EACH ROW EXECUTE FUNCTION guard_supplier_invoice_line();
-- At commit: the header total is the sum of its lines, and Matched means every line matched.
CREATE OR REPLACE FUNCTION check_supplier_invoice_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE total bigint; lines int; differing int;
BEGIN
 SELECT coalesce(sum(invoiced_total_sen), 0), count(*), count(*) FILTER (WHERE result <> 'matches') INTO total, lines, differing
   FROM inventory_supplier_invoice_lines WHERE supplier_invoice_id = NEW.id;
 IF lines = 0 OR total <> NEW.lines_total_sen OR (NEW.status = 'matched') <> (differing = 0) THEN
  RAISE EXCEPTION 'A supplier invoice''s total and status follow its lines' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER supplier_invoices_lines_agree AFTER INSERT ON inventory_supplier_invoices DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_supplier_invoice_lines();
-- A line added later to an existing invoice is checked against its header the same way.
CREATE OR REPLACE FUNCTION check_supplier_invoice_line_header() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE header inventory_supplier_invoices%ROWTYPE; total bigint; differing int;
BEGIN
 SELECT * INTO header FROM inventory_supplier_invoices WHERE id = NEW.supplier_invoice_id;
 SELECT coalesce(sum(invoiced_total_sen), 0), count(*) FILTER (WHERE result <> 'matches') INTO total, differing
   FROM inventory_supplier_invoice_lines WHERE supplier_invoice_id = NEW.supplier_invoice_id;
 IF total <> header.lines_total_sen OR (header.status = 'matched') <> (differing = 0) THEN
  RAISE EXCEPTION 'A supplier invoice''s total and status follow its lines' USING ERRCODE='23514';
 END IF;
 RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER supplier_invoice_lines_agree AFTER INSERT ON inventory_supplier_invoice_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_supplier_invoice_line_header();
CREATE TRIGGER supplier_invoices_no_truncate BEFORE TRUNCATE ON inventory_supplier_invoices FOR EACH STATEMENT EXECUTE FUNCTION guard_supplier_invoice_line();
CREATE TRIGGER supplier_invoice_lines_no_truncate BEFORE TRUNCATE ON inventory_supplier_invoice_lines FOR EACH STATEMENT EXECUTE FUNCTION guard_supplier_invoice_line();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS supplier_invoice_lines_agree ON inventory_supplier_invoice_lines; DROP TRIGGER IF EXISTS supplier_invoices_lines_agree ON inventory_supplier_invoices; DROP TRIGGER IF EXISTS supplier_invoice_lines_no_truncate ON inventory_supplier_invoice_lines; DROP TRIGGER IF EXISTS supplier_invoices_no_truncate ON inventory_supplier_invoices;
                DROP TRIGGER IF EXISTS supplier_invoice_lines_guard ON inventory_supplier_invoice_lines; DROP TRIGGER IF EXISTS supplier_invoices_guard ON inventory_supplier_invoices;');
        }
        Schema::dropIfExists('inventory_supplier_invoice_lines');
        Schema::dropIfExists('inventory_supplier_invoices');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS check_supplier_invoice_line_header(); DROP FUNCTION IF EXISTS check_supplier_invoice_lines(); DROP FUNCTION IF EXISTS guard_supplier_invoice_line(); DROP FUNCTION IF EXISTS guard_supplier_invoice();');
        }
    }
};
