<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_governed_cases', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('balance_id')->constrained('inventory_stock_balances')->restrictOnDelete();
            $t->string('kind', 16);
            $t->string('reason', 30);
            $t->string('base_unit', 100);
            $t->decimal('expected_quantity', 15, 3);
            $t->unsignedInteger('expected_balance_version');
            $t->decimal('quantity', 15, 3)->nullable();
            $t->string('disposal_reference', 200)->nullable();
            $t->foreignId('proposer_user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('reverses_case_id')->nullable()->unique()->constrained('inventory_governed_cases')->restrictOnDelete();
            $t->timestampTz('created_at');
        });
        Schema::create('inventory_governed_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('case_id')->constrained('inventory_governed_cases')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('action', 16);
            $t->string('state', 24);
            $t->decimal('quantity', 15, 3)->nullable();
            $t->foreignId('movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $t->unsignedInteger('lock_version');
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'governed_stock_retry_unique');
            $t->unique(['case_id', 'lock_version'], 'governed_stock_version_unique');
        });
        DB::statement("ALTER TABLE inventory_governed_cases ADD CONSTRAINT governed_stock_case_shape CHECK (expected_quantity >= 0 AND expected_balance_version > 0 AND ((kind='count' AND reason='count_variance' AND quantity IS NULL AND disposal_reference IS NULL AND reverses_case_id IS NULL) OR (kind='writeoff' AND reason IN ('expired','damaged','contaminated','other_approved') AND quantity > 0 AND disposal_reference IS NOT NULL AND length(trim(disposal_reference)) > 0 AND reverses_case_id IS NULL) OR (kind='reversal' AND reason='correction' AND quantity > 0 AND disposal_reference IS NULL AND reverses_case_id IS NOT NULL)))");
        DB::statement("ALTER TABLE inventory_governed_actions ADD CONSTRAINT governed_stock_action_shape CHECK (lock_version > 0 AND (quantity IS NULL OR quantity >= 0) AND action IN ('created','counted','recounted','approved') AND state IN ('awaiting_count','awaiting_recount','awaiting_approval','closed_zero','posted') AND ((action='approved' AND movement_id IS NOT NULL) OR (action<>'approved' AND movement_id IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_governed_stock_case() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Governed stock cases are append-only' USING ERRCODE='P0001'; END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_stock_balances b JOIN inventory_locations l ON l.id=b.inventory_location_id AND l.organisation_id=b.organisation_id JOIN inventory_batches batch ON batch.id=b.inventory_batch_id AND batch.organisation_id=b.organisation_id JOIN inventory_skus s ON s.id=b.inventory_sku_id AND s.organisation_id=b.organisation_id JOIN users u ON u.id=NEW.proposer_user_id AND u.organisation_id=b.organisation_id WHERE b.id=NEW.balance_id AND b.organisation_id=NEW.organisation_id AND b.inventory_location_id=NEW.inventory_location_id AND b.inventory_batch_id=NEW.inventory_batch_id AND b.inventory_sku_id=NEW.inventory_sku_id AND batch.inventory_sku_id=s.id AND s.stock_unit=NEW.base_unit AND l.branch_id=NEW.branch_id AND b.quantity=NEW.expected_quantity AND b.lock_version=NEW.expected_balance_version) THEN RAISE EXCEPTION 'Invalid governed stock snapshot' USING ERRCODE='23514'; END IF;
 IF NEW.reverses_case_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inventory_governed_cases c JOIN inventory_governed_actions a ON a.case_id=c.id AND a.action='approved' WHERE c.id=NEW.reverses_case_id AND c.organisation_id=NEW.organisation_id AND c.branch_id=NEW.branch_id AND c.balance_id=NEW.balance_id AND c.kind<>'reversal' AND a.quantity=NEW.quantity) THEN RAISE EXCEPTION 'Invalid reversal reference' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER governed_stock_cases_append BEFORE INSERT OR UPDATE OR DELETE ON inventory_governed_cases FOR EACH ROW EXECUTE FUNCTION guard_governed_stock_case();
CREATE TRIGGER governed_stock_cases_truncate BEFORE TRUNCATE ON inventory_governed_cases FOR EACH STATEMENT EXECUTE FUNCTION guard_governed_stock_case();
CREATE OR REPLACE FUNCTION guard_governed_stock_action() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c inventory_governed_cases%ROWTYPE; previous inventory_governed_actions%ROWTYPE; expected_state text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Governed stock actions are append-only' USING ERRCODE='P0001'; END IF;
 SELECT * INTO c FROM inventory_governed_cases WHERE id=NEW.case_id FOR UPDATE;
 IF c.id IS NULL OR c.organisation_id<>NEW.organisation_id OR NOT EXISTS(SELECT 1 FROM users WHERE id=NEW.actor_user_id AND organisation_id=c.organisation_id) THEN RAISE EXCEPTION 'Invalid action scope' USING ERRCODE='23514'; END IF;
 SELECT * INTO previous FROM inventory_governed_actions WHERE case_id=c.id ORDER BY lock_version DESC LIMIT 1;
 IF NEW.lock_version<>COALESCE(previous.lock_version,0)+1 THEN RAISE EXCEPTION 'Invalid action version' USING ERRCODE='23514'; END IF;
 IF NEW.action='created' THEN
  IF previous.id IS NOT NULL OR NEW.actor_user_id<>c.proposer_user_id OR NEW.quantity IS NOT NULL THEN RAISE EXCEPTION 'Invalid creation evidence' USING ERRCODE='23514'; END IF;
  expected_state:=CASE WHEN c.kind='count' THEN 'awaiting_count' ELSE 'awaiting_approval' END;
 ELSIF NEW.action IN ('counted','recounted') THEN
  IF previous.id IS NULL OR c.kind<>'count' OR NEW.quantity IS NULL OR (NEW.action='counted' AND previous.state<>'awaiting_count') OR (NEW.action='recounted' AND (previous.state<>'awaiting_recount' OR previous.actor_user_id=NEW.actor_user_id)) THEN RAISE EXCEPTION 'Invalid independent count' USING ERRCODE='23514'; END IF;
  expected_state:=CASE WHEN NEW.quantity=c.expected_quantity THEN 'closed_zero' WHEN NEW.action='counted' THEN 'awaiting_recount' ELSE 'awaiting_approval' END;
 ELSE
  IF previous.state IS DISTINCT FROM 'awaiting_approval' OR NEW.quantity IS NULL OR NEW.quantity<=0 OR NEW.actor_user_id=c.proposer_user_id OR EXISTS (SELECT 1 FROM inventory_governed_actions a WHERE a.case_id IN (c.id,c.reverses_case_id) AND a.action IN ('counted','recounted') AND a.actor_user_id=NEW.actor_user_id) OR EXISTS(SELECT 1 FROM inventory_governed_cases original WHERE original.id=c.reverses_case_id AND original.proposer_user_id=NEW.actor_user_id) THEN RAISE EXCEPTION 'Independent approver required' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.id=NEW.movement_id AND m.organisation_id=c.organisation_id AND m.inventory_sku_id=c.inventory_sku_id AND m.inventory_batch_id=c.inventory_batch_id AND m.reference_type='inventory_governed_case' AND m.reference_public_id=c.public_id AND m.actor_user_id=NEW.actor_user_id AND m.quantity=NEW.quantity AND (m.source_location_id=c.inventory_location_id OR m.destination_location_id=c.inventory_location_id)) THEN RAISE EXCEPTION 'Approval requires linked movement' USING ERRCODE='23514'; END IF;
  IF NEW.quantity IS DISTINCT FROM (CASE WHEN c.kind='count' THEN abs(previous.quantity-c.expected_quantity) ELSE c.quantity END) THEN RAISE EXCEPTION 'Approval quantity does not match evidence' USING ERRCODE='23514'; END IF;
  expected_state:='posted';
 END IF;
 IF NEW.state<>expected_state THEN RAISE EXCEPTION 'Invalid action state' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER governed_stock_actions_append BEFORE INSERT OR UPDATE OR DELETE ON inventory_governed_actions FOR EACH ROW EXECUTE FUNCTION guard_governed_stock_action();
CREATE TRIGGER governed_stock_actions_truncate BEFORE TRUNCATE ON inventory_governed_actions FOR EACH STATEMENT EXECUTE FUNCTION guard_governed_stock_action();
SQL);
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK (quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL) OR (movement_type IN ('purchase_receipt','transfer_receipt','stocktake_gain','adjustment_in') AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type IN ('transfer_dispatch','stocktake_loss','adjustment_out','expiry_writeoff','stock_writeoff') AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION require_governed_stock_approval() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.reference_type='inventory_governed_case' OR NEW.movement_type IN ('expiry_writeoff','stock_writeoff') THEN
  IF NEW.reference_type<>'inventory_governed_case' OR NOT EXISTS(SELECT 1 FROM inventory_governed_actions a JOIN inventory_governed_cases c ON c.id=a.case_id WHERE a.action='approved' AND a.state='posted' AND a.movement_id=NEW.id AND c.public_id=NEW.reference_public_id AND c.organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Governed movement requires approval evidence' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE CONSTRAINT TRIGGER governed_stock_movement_approval AFTER INSERT ON stock_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION require_governed_stock_approval();
SQL);
    }

    public function down(): void
    {
        // Retain governed evidence and its movement types.
    }
};
