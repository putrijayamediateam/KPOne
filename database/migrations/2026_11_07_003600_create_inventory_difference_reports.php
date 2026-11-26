<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-45 (owner, 25 Nov 2026: "Lulus nota + kebenaran"; decision 18 of 20-21 Nov 2026): staff report a difference on a
 * batch at a location, which opens the governed STK-5 count case or joins the newest live one. A report never moves
 * stock. Reports are append-only; whoever reported on a case can never count, recount or approve it, and whoever counted
 * or recounted it can't report on it. STK-5's `guard_governed_stock_action` gains only the reporter exclusion; `down()`
 * restores the STK-5 body word for word.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_difference_reports', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('balance_id')->constrained('inventory_stock_balances')->restrictOnDelete();
            $t->foreignId('case_id')->constrained('inventory_governed_cases')->restrictOnDelete();
            $t->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $t->string('observed', 24);
            $t->decimal('rough_quantity', 15, 3)->nullable();
            $t->string('base_unit', 100);
            $t->text('note_encrypted')->nullable();
            $t->boolean('opened_case');
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'reporter_user_id', 'retry_key'], 'difference_reports_retry_unique');
            $t->index(['organisation_id', 'branch_id', 'reporter_user_id', 'id'], 'difference_reports_reporter_index');
            $t->index('case_id');
        });
        DB::statement("ALTER TABLE inventory_difference_reports ADD CONSTRAINT difference_reports_shape CHECK (observed IN ('fewer','more','damaged_or_expired','unsure') AND (rough_quantity IS NULL OR rough_quantity >= 0))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_inventory_difference_report() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c inventory_governed_cases%ROWTYPE; latest inventory_governed_actions%ROWTYPE;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Difference reports are append-only' USING ERRCODE='P0001'; END IF;
 SELECT * INTO c FROM inventory_governed_cases WHERE id=NEW.case_id;
 IF c.id IS NULL OR c.kind<>'count' OR c.organisation_id<>NEW.organisation_id OR c.branch_id<>NEW.branch_id OR c.balance_id<>NEW.balance_id OR c.base_unit<>NEW.base_unit OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id=NEW.reporter_user_id AND u.organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Invalid difference report scope' USING ERRCODE='23514'; END IF;
 SELECT * INTO latest FROM inventory_governed_actions WHERE case_id=c.id ORDER BY lock_version DESC LIMIT 1;
 IF latest.id IS NULL OR latest.state NOT IN ('awaiting_count','awaiting_recount','awaiting_approval') OR NOT EXISTS (SELECT 1 FROM inventory_stock_balances b JOIN inventory_skus s ON s.id=b.inventory_sku_id WHERE b.id=c.balance_id AND b.lock_version=c.expected_balance_version AND b.quantity=c.expected_quantity AND s.stock_unit=c.base_unit) THEN RAISE EXCEPTION 'A difference report needs an open, live count' USING ERRCODE='23514'; END IF;
 IF EXISTS (SELECT 1 FROM inventory_governed_actions a WHERE a.case_id=c.id AND a.action IN ('counted','recounted') AND a.actor_user_id=NEW.reporter_user_id) THEN RAISE EXCEPTION 'A counter cannot report on the same count' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_difference_reports_append BEFORE INSERT OR UPDATE OR DELETE ON inventory_difference_reports FOR EACH ROW EXECUTE FUNCTION guard_inventory_difference_report();
CREATE TRIGGER inventory_difference_reports_truncate BEFORE TRUNCATE ON inventory_difference_reports FOR EACH STATEMENT EXECUTE FUNCTION guard_inventory_difference_report();
SQL);
        DB::unprepared(<<<'SQL'
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
  IF previous.id IS NULL OR c.kind<>'count' OR NEW.quantity IS NULL OR (NEW.action='counted' AND previous.state<>'awaiting_count') OR (NEW.action='recounted' AND (previous.state<>'awaiting_recount' OR previous.actor_user_id=NEW.actor_user_id)) OR EXISTS (SELECT 1 FROM inventory_difference_reports r WHERE r.case_id=c.id AND r.reporter_user_id=NEW.actor_user_id) THEN RAISE EXCEPTION 'Invalid independent count' USING ERRCODE='23514'; END IF;
  expected_state:=CASE WHEN NEW.quantity=c.expected_quantity THEN 'closed_zero' WHEN NEW.action='counted' THEN 'awaiting_recount' ELSE 'awaiting_approval' END;
 ELSE
  IF previous.state IS DISTINCT FROM 'awaiting_approval' OR NEW.quantity IS NULL OR NEW.quantity<=0 OR NEW.actor_user_id=c.proposer_user_id OR EXISTS (SELECT 1 FROM inventory_governed_actions a WHERE a.case_id IN (c.id,c.reverses_case_id) AND a.action IN ('counted','recounted') AND a.actor_user_id=NEW.actor_user_id) OR EXISTS(SELECT 1 FROM inventory_governed_cases original WHERE original.id=c.reverses_case_id AND original.proposer_user_id=NEW.actor_user_id) OR EXISTS (SELECT 1 FROM inventory_difference_reports r WHERE r.case_id=c.id AND r.reporter_user_id=NEW.actor_user_id) THEN RAISE EXCEPTION 'Independent approver required' USING ERRCODE='23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.id=NEW.movement_id AND m.organisation_id=c.organisation_id AND m.inventory_sku_id=c.inventory_sku_id AND m.inventory_batch_id=c.inventory_batch_id AND m.reference_type='inventory_governed_case' AND m.reference_public_id=c.public_id AND m.actor_user_id=NEW.actor_user_id AND m.quantity=NEW.quantity AND (m.source_location_id=c.inventory_location_id OR m.destination_location_id=c.inventory_location_id)) THEN RAISE EXCEPTION 'Approval requires linked movement' USING ERRCODE='23514'; END IF;
  IF NEW.quantity IS DISTINCT FROM (CASE WHEN c.kind='count' THEN abs(previous.quantity-c.expected_quantity) ELSE c.quantity END) THEN RAISE EXCEPTION 'Approval quantity does not match evidence' USING ERRCODE='23514'; END IF;
  expected_state:='posted';
 END IF;
 IF NEW.state<>expected_state THEN RAISE EXCEPTION 'Invalid action state' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END; $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
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
SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS inventory_difference_reports_truncate ON inventory_difference_reports; DROP TRIGGER IF EXISTS inventory_difference_reports_append ON inventory_difference_reports;');
        Schema::dropIfExists('inventory_difference_reports');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_inventory_difference_report()');
    }
};
