<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-48 (owner decision 21, 20-21 Nov 2026; "build before Monday", 25 Nov 2026): requests for quotation before a
 * purchase order. One quote round holds the items once; each chosen supplier gets its own RFQ with the same lines.
 * A supplier's answer is typed as an append-only quote version; the version in force is frozen when the RFQ is
 * chosen, and the chosen RFQ becomes one draft purchase order. Statuses move only forward, one RFQ per round is
 * chosen, a purchase order is linked once and to one RFQ only, and nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_rfq_rounds', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $t->date('quote_by_date');
            $t->string('status', 10);
            $t->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->string('cancel_reason', 30)->nullable();
            $t->string('cancel_note', 200)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->uuid('convert_idempotency_key')->nullable();
            $t->unique(['organisation_id', 'idempotency_key'], 'rfq_rounds_idempotency_unique');
            $t->unique(['organisation_id', 'convert_idempotency_key'], 'rfq_rounds_convert_idempotency_unique');
            $t->index(['branch_id', 'status', 'created_at'], 'rfq_rounds_branch_status_index');
        });
        Schema::create('inventory_rfq_round_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('rfq_round_id')->constrained('inventory_rfq_rounds')->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained('inventory_skus')->restrictOnDelete();
            $t->decimal('quantity', 15, 3);
            $t->unique(['rfq_round_id', 'inventory_sku_id'], 'rfq_round_lines_sku_unique');
        });
        Schema::create('inventory_rfqs', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('rfq_number', 30)->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('rfq_round_id')->constrained('inventory_rfq_rounds')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('inventory_suppliers')->restrictOnDelete();
            $t->string('status', 12);
            $t->foreignId('sent_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('sent_at')->nullable();
            // The quote version in force; set by the service after each saved version (see the FK below).
            $t->unsignedBigInteger('current_quote_id')->nullable();
            $t->foreignId('chosen_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('chosen_at')->nullable();
            $t->string('chosen_reason', 30)->nullable();
            $t->string('chosen_note', 200)->nullable();
            $t->boolean('expired_confirmed')->default(false);
            $t->foreignId('purchase_order_id')->nullable()->unique()->constrained('inventory_purchase_orders')->restrictOnDelete();
            $t->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->string('cancel_reason', 30)->nullable();
            $t->string('cancel_note', 200)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->unique(['rfq_round_id', 'supplier_id'], 'rfqs_round_supplier_unique');
        });
        Schema::create('inventory_rfq_quotes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('rfq_id')->constrained('inventory_rfqs')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedSmallInteger('lead_time_days');
            $t->date('valid_until');
            $t->string('quote_reference', 60)->nullable();
            $t->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at');
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['rfq_id', 'version'], 'rfq_quotes_version_unique');
            $t->unique(['organisation_id', 'idempotency_key'], 'rfq_quotes_idempotency_unique');
        });
        Schema::create('inventory_rfq_quote_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('rfq_quote_id')->constrained('inventory_rfq_quotes')->restrictOnDelete();
            $t->foreignId('rfq_round_line_id')->constrained('inventory_rfq_round_lines')->restrictOnDelete();
            $t->boolean('cannot_supply')->default(false);
            $t->unsignedBigInteger('pack_price_sen')->nullable();
            $t->decimal('pack_size', 15, 3)->nullable();
            $t->unique(['rfq_quote_id', 'rfq_round_line_id'], 'rfq_quote_lines_unique');
        });
        Schema::table('inventory_rfqs', function (Blueprint $t): void {
            $t->foreign('current_quote_id', 'rfqs_current_quote_foreign')->references('id')->on('inventory_rfq_quotes')->restrictOnDelete();
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE inventory_rfq_rounds ADD CONSTRAINT rfq_rounds_shape CHECK (
 status IN ('open', 'converted', 'cancelled') AND lock_version > 0
 AND ((status = 'open') = (closed_by_user_id IS NULL)) AND ((closed_by_user_id IS NULL) = (closed_at IS NULL))
 AND ((status = 'cancelled') = (cancel_reason IS NOT NULL))
 AND ((status = 'converted') = (convert_idempotency_key IS NOT NULL))
 AND (cancel_reason IS NULL OR cancel_reason IN ('not_needed', 'supplier_no_reply', 'entered_wrongly', 'other'))
 AND (cancel_note IS NULL OR length(btrim(cancel_note)) BETWEEN 1 AND 200)
 AND (cancel_reason IS DISTINCT FROM 'other' OR cancel_note IS NOT NULL)
);
ALTER TABLE inventory_rfq_round_lines ADD CONSTRAINT rfq_round_lines_shape CHECK (quantity > 0 AND quantity <= 1000000);
ALTER TABLE inventory_rfqs ADD CONSTRAINT rfqs_shape CHECK (
 status IN ('draft', 'sent', 'quoted', 'chosen', 'not_chosen', 'cancelled') AND lock_version > 0
 AND ((sent_by_user_id IS NULL) = (sent_at IS NULL))
 AND (status NOT IN ('quoted', 'chosen') OR current_quote_id IS NOT NULL)
 -- A chosen RFQ cancelled before conversion keeps the record of who chose it.
 AND (status <> 'chosen' OR chosen_by_user_id IS NOT NULL) AND (chosen_by_user_id IS NULL OR status IN ('chosen', 'cancelled'))
 AND ((chosen_by_user_id IS NULL) = (chosen_at IS NULL))
 AND (chosen_reason IS NULL OR chosen_reason IN ('faster_delivery', 'preferred_supplier', 'stock_availability', 'other'))
 AND (chosen_note IS NULL OR length(btrim(chosen_note)) BETWEEN 1 AND 200)
 AND (chosen_reason IS DISTINCT FROM 'other' OR chosen_note IS NOT NULL)
 AND (purchase_order_id IS NULL OR status = 'chosen')
 AND ((status IN ('not_chosen', 'cancelled')) = (closed_by_user_id IS NOT NULL)) AND ((closed_by_user_id IS NULL) = (closed_at IS NULL))
 AND ((status = 'cancelled') = (cancel_reason IS NOT NULL))
 AND (cancel_reason IS NULL OR cancel_reason IN ('not_needed', 'supplier_no_reply', 'entered_wrongly', 'other'))
 AND (cancel_note IS NULL OR length(btrim(cancel_note)) BETWEEN 1 AND 200)
 AND (cancel_reason IS DISTINCT FROM 'other' OR cancel_note IS NOT NULL)
);
-- One chosen RFQ per round.
CREATE UNIQUE INDEX rfqs_one_chosen_per_round ON inventory_rfqs (rfq_round_id) WHERE status = 'chosen';
ALTER TABLE inventory_rfq_quotes ADD CONSTRAINT rfq_quotes_shape CHECK (
 version > 0 AND lead_time_days <= 365 AND (quote_reference IS NULL OR length(btrim(quote_reference)) BETWEEN 1 AND 60)
);
ALTER TABLE inventory_rfq_quote_lines ADD CONSTRAINT rfq_quote_lines_shape CHECK (
 (cannot_supply AND pack_price_sen IS NULL AND pack_size IS NULL)
 OR (NOT cannot_supply AND pack_price_sen IS NOT NULL AND pack_price_sen <= 99999999 AND pack_size IS NOT NULL AND pack_size > 0 AND pack_size <= 100000)
);

CREATE OR REPLACE FUNCTION guard_rfq_round() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'Quote rounds are kept' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'open' OR NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = NEW.branch_id AND b.organisation_id = NEW.organisation_id)
   OR NOT EXISTS (SELECT 1 FROM inventory_locations l WHERE l.id = NEW.destination_location_id AND l.organisation_id = NEW.organisation_id
    AND (l.branch_id IS NULL OR l.branch_id = NEW.branch_id) AND l.type <> 'dispensary_returns') THEN
   RAISE EXCEPTION 'A quote round starts open, at its own branch and organisation' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status <> 'open' OR NEW.status = 'open' OR NEW.lock_version <> OLD.lock_version + 1
  OR (NEW.public_id, NEW.organisation_id, NEW.branch_id, NEW.destination_location_id, NEW.quote_by_date, NEW.created_by_user_id, NEW.created_at, NEW.idempotency_key, NEW.request_hash)
   IS DISTINCT FROM (OLD.public_id, OLD.organisation_id, OLD.branch_id, OLD.destination_location_id, OLD.quote_by_date, OLD.created_by_user_id, OLD.created_at, OLD.idempotency_key, OLD.request_hash) THEN
  RAISE EXCEPTION 'A quote round closes once and otherwise never changes' USING ERRCODE = '23514';
 END IF;
 IF NEW.status = 'converted' AND NOT EXISTS (SELECT 1 FROM inventory_rfqs r WHERE r.rfq_round_id = NEW.id AND r.status = 'chosen' AND r.purchase_order_id IS NOT NULL) THEN
  RAISE EXCEPTION 'A converted round has its chosen RFQ linked to an order' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_rfq_round_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN
  RAISE EXCEPTION 'Quote round lines never change' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_rfq_rounds r WHERE r.id = NEW.rfq_round_id AND r.organisation_id = NEW.organisation_id AND r.status = 'open')
  OR NOT EXISTS (SELECT 1 FROM inventory_skus s WHERE s.id = NEW.inventory_sku_id AND s.organisation_id = NEW.organisation_id)
  OR EXISTS (SELECT 1 FROM inventory_rfqs q WHERE q.rfq_round_id = NEW.rfq_round_id AND q.status <> 'draft') THEN
  RAISE EXCEPTION 'A quote round line is added to a new open round of its organisation' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION rfq_status_rank(status text) RETURNS integer LANGUAGE sql IMMUTABLE AS $$
 SELECT CASE status WHEN 'draft' THEN 1 WHEN 'sent' THEN 2 WHEN 'quoted' THEN 3 WHEN 'chosen' THEN 4 ELSE 5 END
$$;

CREATE OR REPLACE FUNCTION guard_rfq() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 round inventory_rfq_rounds%ROWTYPE;
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'RFQs are kept' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'INSERT' THEN
  SELECT * INTO round FROM inventory_rfq_rounds r WHERE r.id = NEW.rfq_round_id;
  IF NEW.status <> 'draft' OR NEW.current_quote_id IS NOT NULL OR NEW.purchase_order_id IS NOT NULL OR round.status IS DISTINCT FROM 'open'
   OR round.organisation_id <> NEW.organisation_id
   OR NOT EXISTS (SELECT 1 FROM inventory_suppliers s WHERE s.id = NEW.supplier_id AND s.organisation_id = NEW.organisation_id) THEN
   RAISE EXCEPTION 'An RFQ starts as a draft in an open round of its organisation' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.lock_version <> OLD.lock_version + 1
  OR (NEW.public_id, NEW.rfq_number, NEW.organisation_id, NEW.rfq_round_id, NEW.supplier_id)
   IS DISTINCT FROM (OLD.public_id, OLD.rfq_number, OLD.organisation_id, OLD.rfq_round_id, OLD.supplier_id)
  OR (OLD.sent_at IS NOT NULL AND (NEW.sent_by_user_id, NEW.sent_at) IS DISTINCT FROM (OLD.sent_by_user_id, OLD.sent_at)) THEN
  RAISE EXCEPTION 'An RFQ keeps its identity, supplier and sent record' USING ERRCODE = '23514';
 END IF;
 -- Statuses move only forward. A quote can be corrected (quoted to quoted) until it is chosen; a chosen RFQ links
 -- its purchase order once, or is cancelled before conversion.
 IF OLD.status IN ('not_chosen', 'cancelled')
  OR rfq_status_rank(NEW.status) < rfq_status_rank(OLD.status)
  OR (OLD.status = NEW.status AND OLD.status NOT IN ('quoted', 'chosen'))
  OR (OLD.status = 'draft' AND NEW.status NOT IN ('sent', 'quoted', 'not_chosen', 'cancelled'))
  OR (OLD.status = 'sent' AND NEW.status NOT IN ('quoted', 'not_chosen', 'cancelled'))
  OR (OLD.status = 'quoted' AND NEW.status NOT IN ('quoted', 'chosen', 'not_chosen', 'cancelled'))
  OR (OLD.status = 'chosen' AND NEW.status NOT IN ('chosen', 'cancelled')) THEN
  RAISE EXCEPTION 'An RFQ status moves only forward' USING ERRCODE = '23514';
 END IF;
 IF OLD.status = 'chosen' THEN
  -- Frozen: the quote in force and the choice never change; the order is linked once; cancelling needs no order.
  IF (NEW.current_quote_id, NEW.chosen_by_user_id, NEW.chosen_at, NEW.chosen_reason, NEW.chosen_note, NEW.expired_confirmed)
    IS DISTINCT FROM (OLD.current_quote_id, OLD.chosen_by_user_id, OLD.chosen_at, OLD.chosen_reason, OLD.chosen_note, OLD.expired_confirmed)
   OR OLD.purchase_order_id IS NOT NULL
   OR (NEW.status = 'chosen' AND NEW.purchase_order_id IS NULL) THEN
   RAISE EXCEPTION 'A chosen RFQ is frozen and converted once' USING ERRCODE = '23514';
  END IF;
 ELSIF NEW.status = 'chosen' AND NEW.purchase_order_id IS NOT NULL THEN
  RAISE EXCEPTION 'An RFQ is chosen before it is converted' USING ERRCODE = '23514';
 END IF;
 IF NEW.current_quote_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inventory_rfq_quotes q WHERE q.id = NEW.current_quote_id AND q.rfq_id = NEW.id) THEN
  RAISE EXCEPTION 'The quote in force belongs to its RFQ' USING ERRCODE = '23514';
 END IF;
 IF NEW.purchase_order_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inventory_purchase_orders po WHERE po.id = NEW.purchase_order_id
   AND po.organisation_id = NEW.organisation_id AND po.supplier_id = NEW.supplier_id AND po.status = 'draft') THEN
  RAISE EXCEPTION 'A converted RFQ links to a draft order of the same supplier and organisation' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_rfq_quote() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN
  RAISE EXCEPTION 'Quote versions are append-only' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_rfqs r WHERE r.id = NEW.rfq_id AND r.organisation_id = NEW.organisation_id AND r.status IN ('draft', 'sent', 'quoted'))
  OR NEW.version <> 1 + COALESCE((SELECT max(q.version) FROM inventory_rfq_quotes q WHERE q.rfq_id = NEW.rfq_id), 0) THEN
  RAISE EXCEPTION 'A quote version is added, in order, to an RFQ that is not yet chosen or closed' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION guard_rfq_quote_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN
  RAISE EXCEPTION 'Quote lines are append-only' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_rfq_quotes q JOIN inventory_rfqs r ON r.id = q.rfq_id JOIN inventory_rfq_round_lines l ON l.rfq_round_id = r.rfq_round_id
   WHERE q.id = NEW.rfq_quote_id AND l.id = NEW.rfq_round_line_id AND q.organisation_id = NEW.organisation_id AND r.status IN ('draft', 'sent', 'quoted')) THEN
  RAISE EXCEPTION 'A quote line answers a line of its own round' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

-- At commit, the quote in force answers every line of the round.
CREATE OR REPLACE FUNCTION check_rfq_quote_complete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.current_quote_id IS NOT NULL AND EXISTS (SELECT 1 FROM inventory_rfq_round_lines l WHERE l.rfq_round_id = NEW.rfq_round_id
   AND NOT EXISTS (SELECT 1 FROM inventory_rfq_quote_lines ql WHERE ql.rfq_quote_id = NEW.current_quote_id AND ql.rfq_round_line_id = l.id)) THEN
  RAISE EXCEPTION 'A quote answers every line of its round' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION refuse_rfq_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'RFQs are kept' USING ERRCODE = '23514';
END $$;

CREATE TRIGGER rfq_rounds_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_rfq_rounds FOR EACH ROW EXECUTE FUNCTION guard_rfq_round();
CREATE TRIGGER rfq_round_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_rfq_round_lines FOR EACH ROW EXECUTE FUNCTION guard_rfq_round_line();
CREATE TRIGGER rfqs_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_rfqs FOR EACH ROW EXECUTE FUNCTION guard_rfq();
CREATE TRIGGER rfq_quotes_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_rfq_quotes FOR EACH ROW EXECUTE FUNCTION guard_rfq_quote();
CREATE TRIGGER rfq_quote_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_rfq_quote_lines FOR EACH ROW EXECUTE FUNCTION guard_rfq_quote_line();
CREATE CONSTRAINT TRIGGER rfqs_quote_complete AFTER UPDATE ON inventory_rfqs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_rfq_quote_complete();
CREATE TRIGGER rfq_rounds_no_truncate BEFORE TRUNCATE ON inventory_rfq_rounds FOR EACH STATEMENT EXECUTE FUNCTION refuse_rfq_truncate();
CREATE TRIGGER rfq_round_lines_no_truncate BEFORE TRUNCATE ON inventory_rfq_round_lines FOR EACH STATEMENT EXECUTE FUNCTION refuse_rfq_truncate();
CREATE TRIGGER rfqs_no_truncate BEFORE TRUNCATE ON inventory_rfqs FOR EACH STATEMENT EXECUTE FUNCTION refuse_rfq_truncate();
CREATE TRIGGER rfq_quotes_no_truncate BEFORE TRUNCATE ON inventory_rfq_quotes FOR EACH STATEMENT EXECUTE FUNCTION refuse_rfq_truncate();
CREATE TRIGGER rfq_quote_lines_no_truncate BEFORE TRUNCATE ON inventory_rfq_quote_lines FOR EACH STATEMENT EXECUTE FUNCTION refuse_rfq_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS rfq_quote_lines_no_truncate ON inventory_rfq_quote_lines; DROP TRIGGER IF EXISTS rfq_quotes_no_truncate ON inventory_rfq_quotes;
                DROP TRIGGER IF EXISTS rfqs_no_truncate ON inventory_rfqs; DROP TRIGGER IF EXISTS rfq_round_lines_no_truncate ON inventory_rfq_round_lines;
                DROP TRIGGER IF EXISTS rfq_rounds_no_truncate ON inventory_rfq_rounds; DROP TRIGGER IF EXISTS rfqs_quote_complete ON inventory_rfqs;
                DROP TRIGGER IF EXISTS rfq_quote_lines_guard ON inventory_rfq_quote_lines; DROP TRIGGER IF EXISTS rfq_quotes_guard ON inventory_rfq_quotes;
                DROP TRIGGER IF EXISTS rfqs_guard ON inventory_rfqs; DROP TRIGGER IF EXISTS rfq_round_lines_guard ON inventory_rfq_round_lines;
                DROP TRIGGER IF EXISTS rfq_rounds_guard ON inventory_rfq_rounds;');
        }
        Schema::table('inventory_rfqs', function (Blueprint $t): void {
            $t->dropForeign('rfqs_current_quote_foreign');
        });
        Schema::dropIfExists('inventory_rfq_quote_lines');
        Schema::dropIfExists('inventory_rfq_quotes');
        Schema::dropIfExists('inventory_rfqs');
        Schema::dropIfExists('inventory_rfq_round_lines');
        Schema::dropIfExists('inventory_rfq_rounds');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS refuse_rfq_truncate(); DROP FUNCTION IF EXISTS check_rfq_quote_complete(); DROP FUNCTION IF EXISTS guard_rfq_quote_line();
                DROP FUNCTION IF EXISTS guard_rfq_quote(); DROP FUNCTION IF EXISTS guard_rfq(); DROP FUNCTION IF EXISTS rfq_status_rank(text);
                DROP FUNCTION IF EXISTS guard_rfq_round_line(); DROP FUNCTION IF EXISTS guard_rfq_round();');
        }
    }
};
