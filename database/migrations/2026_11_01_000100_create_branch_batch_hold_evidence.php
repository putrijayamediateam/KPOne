<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batch_hold_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->string('action', 10);
            $t->string('reason', 40);
            $t->foreignId('placed_action_id')->nullable()->constrained('inventory_batch_hold_actions')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('lock_version');
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'inventory_batch_hold_retry_unique');
            $t->unique(['inventory_batch_id', 'branch_id', 'lock_version'], 'inventory_batch_hold_version_unique');
        });
        Schema::create('inventory_batch_hold_states', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('active_action_id')->nullable()->constrained('inventory_batch_hold_actions')->restrictOnDelete();
            $t->unsignedInteger('lock_version')->default(0);
            $t->unique(['inventory_batch_id', 'branch_id']);
        });
        DB::statement('CREATE UNIQUE INDEX inventory_batch_one_active_hold ON inventory_batch_hold_states(inventory_batch_id, branch_id) WHERE active_action_id IS NOT NULL');
        Schema::table('dispensary_item_batch_allocations', fn (Blueprint $t) => $t->string('fefo_exception_reason', 40)->nullable());
        DB::statement("ALTER TABLE dispensary_item_batch_allocations ADD CONSTRAINT dispensary_fefo_reason CHECK (fefo_exception_reason IS NULL OR fefo_exception_reason IN ('earlier_batch_damaged','earlier_batch_insufficient','earlier_batch_held','other_approved'))");
        DB::statement("ALTER TABLE inventory_batch_hold_actions ADD CONSTRAINT inventory_batch_hold_reason CHECK ((action='place' AND placed_action_id IS NULL AND reason IN ('suspected_quality_issue','recall','damaged','other_safety')) OR (action='release' AND placed_action_id IS NOT NULL AND reason IN ('cleared_by_supplier','inspection_passed','other_approved')))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_completed_fefo_reason() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.fefo_exception_reason IS DISTINCT FROM OLD.fefo_exception_reason AND EXISTS (SELECT 1 FROM dispensary_items i JOIN dispensary_handoffs h ON h.id=i.dispensary_handoff_id JOIN dispensary_cases c ON c.id=h.dispensary_case_id WHERE i.id=OLD.dispensary_item_id AND c.status='completed') THEN RAISE EXCEPTION 'Completed FEFO evidence is immutable' USING ERRCODE='P0001'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER completed_fefo_reason_immutable BEFORE UPDATE OF fefo_exception_reason ON dispensary_item_batch_allocations FOR EACH ROW EXECUTE FUNCTION guard_completed_fefo_reason();
CREATE OR REPLACE FUNCTION guard_inventory_batch_hold_state() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE event inventory_batch_hold_actions%ROWTYPE;
BEGIN
 IF TG_OP IN ('DELETE','TRUNCATE') THEN RAISE EXCEPTION 'Hold state cannot be erased' USING ERRCODE='P0001'; END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_batches b JOIN branches br ON br.id=NEW.branch_id AND br.organisation_id=b.organisation_id WHERE b.id=NEW.inventory_batch_id AND b.organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Invalid hold projection scope' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.lock_version<>0 OR NEW.active_action_id IS NOT NULL THEN RAISE EXCEPTION 'Invalid initial hold state' USING ERRCODE='23514'; END IF;
 ELSE
  IF NEW.organisation_id<>OLD.organisation_id OR NEW.branch_id<>OLD.branch_id OR NEW.inventory_batch_id<>OLD.inventory_batch_id OR NEW.lock_version<>OLD.lock_version+1 THEN RAISE EXCEPTION 'Invalid hold state version' USING ERRCODE='23514'; END IF;
  SELECT * INTO event FROM inventory_batch_hold_actions WHERE inventory_batch_id=NEW.inventory_batch_id AND branch_id=NEW.branch_id AND lock_version=NEW.lock_version;
  IF event.id IS NULL OR event.organisation_id<>NEW.organisation_id OR (event.action='place' AND (OLD.active_action_id IS NOT NULL OR NEW.active_action_id IS DISTINCT FROM event.id)) OR (event.action='release' AND (NEW.active_action_id IS NOT NULL OR OLD.active_action_id IS DISTINCT FROM event.placed_action_id)) THEN RAISE EXCEPTION 'Hold projection needs matching evidence' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_batch_hold_state_write BEFORE INSERT OR UPDATE ON inventory_batch_hold_states FOR EACH ROW EXECUTE FUNCTION guard_inventory_batch_hold_state();
CREATE TRIGGER inventory_batch_hold_state_erase BEFORE DELETE OR TRUNCATE ON inventory_batch_hold_states FOR EACH STATEMENT EXECUTE FUNCTION guard_inventory_batch_hold_state();
CREATE OR REPLACE FUNCTION guard_inventory_batch_hold_action() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE previous inventory_batch_hold_actions%ROWTYPE;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Batch hold actions are append-only' USING ERRCODE='P0001'; END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_batches b JOIN branches br ON br.id=NEW.branch_id AND br.organisation_id=b.organisation_id JOIN users u ON u.id=NEW.actor_user_id AND u.organisation_id=b.organisation_id WHERE b.id=NEW.inventory_batch_id AND b.organisation_id=NEW.organisation_id) THEN RAISE EXCEPTION 'Invalid hold scope' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS (SELECT 1 FROM inventory_batch_hold_states h WHERE h.inventory_batch_id=NEW.inventory_batch_id AND h.branch_id=NEW.branch_id AND h.organisation_id=NEW.organisation_id AND h.lock_version=NEW.lock_version-1 AND ((NEW.action='place' AND h.active_action_id IS NULL) OR (NEW.action='release' AND h.active_action_id=NEW.placed_action_id))) THEN RAISE EXCEPTION 'Hold action needs current state' USING ERRCODE='23514'; END IF;
 IF NEW.action='release' THEN
  SELECT * INTO previous FROM inventory_batch_hold_actions WHERE id=NEW.placed_action_id;
  IF previous.action IS DISTINCT FROM 'place' OR previous.branch_id IS DISTINCT FROM NEW.branch_id OR previous.inventory_batch_id IS DISTINCT FROM NEW.inventory_batch_id OR previous.actor_user_id=NEW.actor_user_id THEN RAISE EXCEPTION 'Independent release required' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER inventory_batch_hold_actions_insert BEFORE INSERT ON inventory_batch_hold_actions FOR EACH ROW EXECUTE FUNCTION guard_inventory_batch_hold_action();
CREATE TRIGGER inventory_batch_hold_actions_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON inventory_batch_hold_actions FOR EACH STATEMENT EXECUTE FUNCTION guard_inventory_batch_hold_action();
SQL);
    }

    public function down(): void
    {
        // Retain hold, release and allocation evidence.
    }
};
