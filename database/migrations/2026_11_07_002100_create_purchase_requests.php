<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-18 (owner decision 2026-11-12, "agree purchase requests"): a CA or CA Supervisor asks for stock at their branch;
 * a CA Supervisor or Finance Officer who is NOT the requester converts the request into a draft purchase order (with
 * quantities they may change, the asked quantity kept) or rejects it; the requester may withdraw it while it waits.
 * Lines are frozen when sent, except the converted quantity, which is written once at conversion. Actions are
 * append-only. "Never the requester" is enforced here as well as in the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_purchase_requests', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('request_number', 30)->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $t->foreignId('preferred_supplier_id')->nullable()->constrained('inventory_suppliers')->restrictOnDelete();
            $t->string('status', 10);
            $t->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('requested_at');
            $t->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('reject_reason', 30)->nullable();
            $t->foreignId('purchase_order_id')->nullable()->unique()->constrained('inventory_purchase_orders')->restrictOnDelete();
            $t->unsignedInteger('lock_version')->default(1);
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['organisation_id', 'idempotency_key'], 'purchase_requests_idempotency_unique');
            $t->index(['branch_id', 'status', 'requested_at'], 'purchase_requests_branch_status_index');
        });
        Schema::create('inventory_purchase_request_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_request_id')->constrained('inventory_purchase_requests')->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained('inventory_skus')->restrictOnDelete();
            $t->decimal('requested_quantity', 15, 3);
            $t->string('note', 200)->nullable();
            // Written once, when the request is converted; 0 drops the line from the order.
            $t->decimal('converted_quantity', 15, 3)->nullable();
            $t->unique(['purchase_request_id', 'inventory_sku_id'], 'purchase_request_lines_sku_unique');
        });
        Schema::create('inventory_purchase_request_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_request_id')->constrained('inventory_purchase_requests')->restrictOnDelete();
            $t->string('action', 10);
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 30)->nullable();
            $t->timestamp('created_at');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE inventory_purchase_requests ADD CONSTRAINT purchase_requests_shape CHECK (
 status IN ('waiting', 'converted', 'rejected', 'withdrawn') AND lock_version > 0
 AND ((status = 'waiting') = (decided_by_user_id IS NULL)) AND ((decided_by_user_id IS NULL) = (decided_at IS NULL))
 AND ((status = 'converted') = (purchase_order_id IS NOT NULL))
 AND ((status = 'rejected') = (reject_reason IS NOT NULL))
 AND (reject_reason IS NULL OR reject_reason IN ('not_needed', 'stock_elsewhere', 'budget', 'other'))
 -- Never the requester: only the requester withdraws, and the requester never converts or rejects.
 AND (status <> 'withdrawn' OR decided_by_user_id = requested_by_user_id)
 AND (status NOT IN ('converted', 'rejected') OR decided_by_user_id <> requested_by_user_id)
);
ALTER TABLE inventory_purchase_request_lines ADD CONSTRAINT purchase_request_lines_shape CHECK (
 requested_quantity > 0 AND (converted_quantity IS NULL OR converted_quantity >= 0)
 AND (note IS NULL OR length(btrim(note)) BETWEEN 1 AND 200)
);
ALTER TABLE inventory_purchase_request_actions ADD CONSTRAINT purchase_request_actions_shape CHECK (
 action IN ('sent', 'converted', 'rejected', 'withdrawn')
);
CREATE OR REPLACE FUNCTION guard_purchase_request() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'Purchase requests are kept' USING ERRCODE = '23514';
 END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'waiting' THEN
   RAISE EXCEPTION 'A purchase request starts waiting for review' USING ERRCODE = '23514';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM inventory_locations l WHERE l.id = NEW.destination_location_id AND l.organisation_id = NEW.organisation_id AND l.branch_id = NEW.branch_id)
   OR NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = NEW.branch_id AND b.organisation_id = NEW.organisation_id)
   OR (NEW.preferred_supplier_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inventory_suppliers s WHERE s.id = NEW.preferred_supplier_id AND s.organisation_id = NEW.organisation_id)) THEN
   RAISE EXCEPTION 'A purchase request belongs to its branch and organisation' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status <> 'waiting' OR NEW.status = 'waiting'
  OR (NEW.public_id, NEW.request_number, NEW.organisation_id, NEW.branch_id, NEW.destination_location_id, NEW.preferred_supplier_id, NEW.requested_by_user_id, NEW.requested_at, NEW.idempotency_key, NEW.request_hash)
   IS DISTINCT FROM (OLD.public_id, OLD.request_number, OLD.organisation_id, OLD.branch_id, OLD.destination_location_id, OLD.preferred_supplier_id, OLD.requested_by_user_id, OLD.requested_at, OLD.idempotency_key, OLD.request_hash)
  OR NEW.lock_version <> OLD.lock_version + 1 THEN
  RAISE EXCEPTION 'A purchase request is decided once and otherwise never changes' USING ERRCODE = '23514';
 END IF;
 IF NEW.status = 'converted' AND NOT EXISTS (SELECT 1 FROM inventory_purchase_orders po WHERE po.id = NEW.purchase_order_id AND po.organisation_id = NEW.organisation_id AND po.destination_location_id = NEW.destination_location_id) THEN
  RAISE EXCEPTION 'A converted request points at its own draft order' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_purchase_request_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 request inventory_purchase_requests%ROWTYPE;
BEGIN
 IF TG_OP = 'DELETE' THEN
  RAISE EXCEPTION 'Purchase request lines are kept' USING ERRCODE = '23514';
 END IF;
 SELECT * INTO request FROM inventory_purchase_requests r WHERE r.id = NEW.purchase_request_id;
 IF TG_OP = 'INSERT' THEN
  IF request.status IS DISTINCT FROM 'waiting' OR request.organisation_id <> NEW.organisation_id OR NEW.converted_quantity IS NOT NULL
   OR NOT EXISTS (SELECT 1 FROM inventory_skus s WHERE s.id = NEW.inventory_sku_id AND s.organisation_id = NEW.organisation_id) THEN
   RAISE EXCEPTION 'A purchase request line is added to a waiting request of its organisation' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 -- The only change: the converted quantity, written once when the request is converted.
 IF request.status <> 'converted' OR OLD.converted_quantity IS NOT NULL OR NEW.converted_quantity IS NULL
  OR (NEW.organisation_id, NEW.purchase_request_id, NEW.inventory_sku_id, NEW.requested_quantity, NEW.note)
   IS DISTINCT FROM (OLD.organisation_id, OLD.purchase_request_id, OLD.inventory_sku_id, OLD.requested_quantity, OLD.note) THEN
  RAISE EXCEPTION 'A purchase request line is frozen except its converted quantity' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION guard_purchase_request_action() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Purchase request actions are append-only' USING ERRCODE = '23514';
END $$;
CREATE OR REPLACE FUNCTION refuse_purchase_request_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Purchase requests are kept' USING ERRCODE = '23514';
END $$;
-- At commit, a converted request has a converted quantity on every line.
CREATE OR REPLACE FUNCTION check_purchase_request_converted() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status = 'converted' AND EXISTS (SELECT 1 FROM inventory_purchase_request_lines l WHERE l.purchase_request_id = NEW.id AND l.converted_quantity IS NULL) THEN
  RAISE EXCEPTION 'A converted request records the quantity of every line' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE TRIGGER purchase_requests_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_purchase_requests FOR EACH ROW EXECUTE FUNCTION guard_purchase_request();
CREATE TRIGGER purchase_request_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_purchase_request_lines FOR EACH ROW EXECUTE FUNCTION guard_purchase_request_line();
CREATE TRIGGER purchase_request_actions_guard BEFORE UPDATE OR DELETE ON inventory_purchase_request_actions FOR EACH ROW EXECUTE FUNCTION guard_purchase_request_action();
CREATE CONSTRAINT TRIGGER purchase_requests_converted AFTER UPDATE ON inventory_purchase_requests DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_purchase_request_converted();
CREATE TRIGGER purchase_requests_no_truncate BEFORE TRUNCATE ON inventory_purchase_requests FOR EACH STATEMENT EXECUTE FUNCTION refuse_purchase_request_truncate();
CREATE TRIGGER purchase_request_lines_no_truncate BEFORE TRUNCATE ON inventory_purchase_request_lines FOR EACH STATEMENT EXECUTE FUNCTION refuse_purchase_request_truncate();
CREATE TRIGGER purchase_request_actions_no_truncate BEFORE TRUNCATE ON inventory_purchase_request_actions FOR EACH STATEMENT EXECUTE FUNCTION refuse_purchase_request_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS purchase_request_actions_no_truncate ON inventory_purchase_request_actions; DROP TRIGGER IF EXISTS purchase_request_lines_no_truncate ON inventory_purchase_request_lines;
                DROP TRIGGER IF EXISTS purchase_requests_no_truncate ON inventory_purchase_requests; DROP TRIGGER IF EXISTS purchase_requests_converted ON inventory_purchase_requests;
                DROP TRIGGER IF EXISTS purchase_request_actions_guard ON inventory_purchase_request_actions; DROP TRIGGER IF EXISTS purchase_request_lines_guard ON inventory_purchase_request_lines;
                DROP TRIGGER IF EXISTS purchase_requests_guard ON inventory_purchase_requests;');
        }
        Schema::dropIfExists('inventory_purchase_request_actions');
        Schema::dropIfExists('inventory_purchase_request_lines');
        Schema::dropIfExists('inventory_purchase_requests');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS check_purchase_request_converted(); DROP FUNCTION IF EXISTS refuse_purchase_request_truncate(); DROP FUNCTION IF EXISTS guard_purchase_request_action();
                DROP FUNCTION IF EXISTS guard_purchase_request_line(); DROP FUNCTION IF EXISTS guard_purchase_request();');
        }
    }
};
