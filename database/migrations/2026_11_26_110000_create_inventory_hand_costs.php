<?php

// STK-46: exact schema and no-op rollback approved by the owner on 26 November 2026.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_hand_costs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->bigInteger('cost_total_sen');
            $table->string('reason_code', 40);
            $table->text('note')->nullable();
            $table->string('reference', 200)->nullable();
            $table->string('status', 16)->default('waiting');
            $table->foreignId('entered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('entered_at');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_reason', 200)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->uuid('idempotency_key');
            $table->char('payload_hash', 64);
            $table->uuid('decision_idempotency_key')->nullable();
            $table->char('decision_payload_hash', 64)->nullable();
            $table->unique(['organisation_id', 'idempotency_key'], 'hand_cost_enter_retry_unique');
            $table->unique(['organisation_id', 'decision_idempotency_key'], 'hand_cost_decide_retry_unique');
            $table->index(['organisation_id', 'stock_movement_id', 'status', 'decided_at', 'id'], 'hand_cost_latest_accepted');
        });
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX hand_cost_one_waiting ON inventory_hand_costs(stock_movement_id) WHERE status = 'waiting';
ALTER TABLE inventory_hand_costs ADD CONSTRAINT hand_cost_amount CHECK (cost_total_sen BETWEEN 0 AND 999999999999);
ALTER TABLE inventory_hand_costs ADD CONSTRAINT hand_cost_reason CHECK (
 reason_code IN ('supplier_invoice_found','opening_price_list','estimate_was_wrong','other') AND
 (reason_code <> 'other' OR note IS NOT NULL));
ALTER TABLE inventory_hand_costs ADD CONSTRAINT hand_cost_hash CHECK (
 payload_hash ~ '^[0-9a-f]{64}$' AND (decision_payload_hash IS NULL OR decision_payload_hash ~ '^[0-9a-f]{64}$'));
ALTER TABLE inventory_hand_costs ADD CONSTRAINT hand_cost_decision CHECK (
 (status = 'waiting' AND lock_version = 1 AND decided_by_user_id IS NULL AND decided_at IS NULL
  AND decision_reason IS NULL AND decision_idempotency_key IS NULL AND decision_payload_hash IS NULL)
 OR
 (status IN ('accepted','rejected') AND lock_version = 2 AND decided_by_user_id IS NOT NULL
  AND decided_by_user_id <> entered_by_user_id AND decided_at IS NOT NULL AND decided_at >= entered_at
  AND decision_idempotency_key IS NOT NULL AND decision_payload_hash IS NOT NULL
  AND (status <> 'rejected' OR (decision_reason IS NOT NULL AND length(btrim(decision_reason)) > 0))));
CREATE OR REPLACE FUNCTION guard_inventory_hand_cost() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE movement stock_movements%ROWTYPE; destination_branch bigint; receipt_id bigint;
BEGIN
 IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN RAISE EXCEPTION 'Hand cost history is immutable'; END IF;
 IF TG_OP = 'INSERT' AND NEW.status <> 'waiting' THEN RAISE EXCEPTION 'A hand cost starts waiting'; END IF;
 IF TG_OP = 'UPDATE' THEN
  IF OLD.status <> 'waiting' OR NEW.status NOT IN ('accepted','rejected') OR
   (to_jsonb(NEW) - ARRAY['status','decided_by_user_id','decided_at','decision_reason','decision_idempotency_key','decision_payload_hash','lock_version'])
    IS DISTINCT FROM
   (to_jsonb(OLD) - ARRAY['status','decided_by_user_id','decided_at','decision_reason','decision_idempotency_key','decision_payload_hash','lock_version'])
  THEN RAISE EXCEPTION 'Only a waiting hand cost may be decided once'; END IF;
 END IF;
 SELECT * INTO STRICT movement FROM stock_movements WHERE id = NEW.stock_movement_id;
 SELECT branch_id INTO STRICT destination_branch FROM inventory_locations
  WHERE id = movement.destination_location_id AND organisation_id = NEW.organisation_id;
 IF movement.organisation_id <> NEW.organisation_id OR NEW.branch_id IS DISTINCT FROM destination_branch THEN
  RAISE EXCEPTION 'Hand cost scope differs from movement';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.entered_by_user_id AND organisation_id = NEW.organisation_id)
  OR (NEW.decided_by_user_id IS NOT NULL AND NOT EXISTS
   (SELECT 1 FROM users WHERE id = NEW.decided_by_user_id AND organisation_id = NEW.organisation_id)) THEN
  RAISE EXCEPTION 'Hand cost actor belongs to another organisation';
 END IF;
 IF movement.movement_type = 'opening_balance' THEN
  IF movement.unit_cost_sen IS NOT NULL OR movement.cost_total_sen IS NOT NULL THEN
   RAISE EXCEPTION 'An actual opening cost cannot be replaced';
  END IF;
 ELSIF movement.movement_type = 'purchase_receipt' THEN
  IF movement.cost_total_sen IS NOT NULL OR movement.cost_source = 'actual' OR (movement.cost_source IS NOT NULL AND movement.cost_source <> 'estimate')
   OR movement.reference_type <> 'inventory_goods_receipt' THEN
   RAISE EXCEPTION 'An actual receipt cost cannot be replaced';
  END IF;
  SELECT id INTO STRICT receipt_id FROM inventory_goods_receipts
   WHERE public_id = movement.reference_public_id AND organisation_id = NEW.organisation_id FOR UPDATE;
  IF NEW.status <> 'rejected' AND EXISTS (SELECT 1 FROM inventory_receipt_corrections WHERE goods_receipt_id = receipt_id) THEN
   RAISE EXCEPTION 'A reversed receipt cannot receive or accept a hand cost';
  END IF;
 ELSE RAISE EXCEPTION 'Only an opening or purchase receipt may have a hand cost';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hand_cost_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_hand_costs
 FOR EACH ROW EXECUTE FUNCTION guard_inventory_hand_cost();
CREATE TRIGGER hand_cost_no_truncate BEFORE TRUNCATE ON inventory_hand_costs
 FOR EACH STATEMENT EXECUTE FUNCTION guard_inventory_hand_cost();
SQL);
    }

    public function down(): void
    {
        // Intentionally preserve immutable hand-cost history and its guards on rollback.
        // Reversal of this financial evidence requires a separately reviewed forward migration.
    }
};
