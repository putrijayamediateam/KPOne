<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-8: non-dispensed treatment-room usage and waste. Each entry debits one batch at one location through the
 * stock ledger; a different supervisor may reverse an entry once, which credits the same quantity back.
 * No patient link, no billing, no kits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_treatment_usages', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->string('usage_number', 40);
            $t->string('kind', 10);
            $t->string('reason', 30);
            $t->foreignId('branch_room_id')->nullable()->constrained('branch_rooms')->restrictOnDelete();
            $t->foreignId('inventory_location_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_batch_id')->constrained()->restrictOnDelete();
            $t->string('base_unit', 100);
            $t->decimal('quantity', 15, 3);
            $t->foreignId('movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $t->foreignId('recorder_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'usage_number'], 'treatment_usages_number_unique');
            $t->unique(['organisation_id', 'recorder_user_id', 'retry_key'], 'treatment_usages_retry_unique');
            $t->index(['organisation_id', 'branch_id', 'created_at'], 'treatment_usages_branch_index');
        });
        Schema::create('inventory_treatment_usage_reversals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('usage_id')->unique()->constrained('inventory_treatment_usages')->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 30);
            $t->foreignId('movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'treatment_usage_reversals_retry_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE inventory_treatment_usages ADD CONSTRAINT treatment_usages_shape CHECK (quantity > 0 AND ((kind='usage' AND reason IN ('procedure','wound_care','nebulisation','injection','other_clinical_use')) OR (kind='waste' AND reason IN ('opened_not_used','spilled_or_broken','contaminated','other_waste'))))");
        DB::statement("ALTER TABLE inventory_treatment_usage_reversals ADD CONSTRAINT treatment_usage_reversals_shape CHECK (reason IN ('recorded_in_error','wrong_item_or_batch','wrong_quantity','wrong_location'))");
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK (quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL) OR (movement_type IN ('purchase_receipt','transfer_receipt','stocktake_gain','adjustment_in') AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type IN ('transfer_dispatch','stocktake_loss','adjustment_out','expiry_writeoff','stock_writeoff','supplier_return','treatment_usage','treatment_waste') AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NULL)))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_treatment_usage_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Treatment usage evidence is append-only' USING ERRCODE='P0001';
END; $$;
CREATE OR REPLACE FUNCTION guard_treatment_usage() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM inventory_locations l JOIN branches b ON b.id=l.branch_id JOIN inventory_skus s ON s.organisation_id=l.organisation_id
   JOIN inventory_batches bt ON bt.inventory_sku_id=s.id AND bt.organisation_id=s.organisation_id JOIN users u ON u.organisation_id=l.organisation_id
   WHERE l.id=NEW.inventory_location_id AND l.organisation_id=NEW.organisation_id AND l.branch_id=NEW.branch_id AND l.is_active AND b.organisation_id=NEW.organisation_id
   AND s.id=NEW.inventory_sku_id AND s.stock_unit=NEW.base_unit AND bt.id=NEW.inventory_batch_id AND u.id=NEW.recorder_user_id) THEN
  RAISE EXCEPTION 'Treatment usage scope mismatch' USING ERRCODE='23514';
 END IF;
 IF NEW.branch_room_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM branch_rooms r WHERE r.id=NEW.branch_room_id AND r.organisation_id=NEW.organisation_id AND r.branch_id=NEW.branch_id AND r.kind='treatment' AND r.is_active) THEN
  RAISE EXCEPTION 'Treatment usage room must be a treatment room of the same branch' USING ERRCODE='23514';
 END IF;
 -- Usable stock only: expired, held or unavailable batches leave through the governed Write-off.
 IF NOT EXISTS (SELECT 1 FROM inventory_batches bt JOIN inventory_skus s ON s.id=bt.inventory_sku_id JOIN inventory_items i ON i.id=s.inventory_item_id JOIN branches b ON b.id=NEW.branch_id
   WHERE bt.id=NEW.inventory_batch_id AND bt.status='available' AND s.is_active AND i.is_active AND bt.expiry_date > (now() AT TIME ZONE b.timezone)::date)
   OR EXISTS (SELECT 1 FROM inventory_batch_hold_states h WHERE h.inventory_batch_id=NEW.inventory_batch_id AND h.branch_id=NEW.branch_id AND h.active_action_id IS NOT NULL) THEN
  RAISE EXCEPTION 'Treatment usage is for usable stock only' USING ERRCODE='23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.id=NEW.movement_id AND m.organisation_id=NEW.organisation_id AND m.movement_type='treatment_'||NEW.kind
   AND m.reference_type='inventory_treatment_usage' AND m.reference_public_id=NEW.public_id AND m.source_location_id=NEW.inventory_location_id
   AND m.inventory_sku_id=NEW.inventory_sku_id AND m.inventory_batch_id=NEW.inventory_batch_id AND m.quantity=NEW.quantity AND m.actor_user_id=NEW.recorder_user_id) THEN
  RAISE EXCEPTION 'Treatment usage requires its matching stock movement' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION guard_treatment_usage_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE u inventory_treatment_usages%ROWTYPE;
BEGIN
 SELECT * INTO u FROM inventory_treatment_usages WHERE id=NEW.usage_id FOR UPDATE;
 IF u.id IS NULL OR u.organisation_id<>NEW.organisation_id OR NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.actor_user_id AND organisation_id=u.organisation_id) THEN
  RAISE EXCEPTION 'Invalid treatment usage reversal scope' USING ERRCODE='23514';
 END IF;
 IF NEW.actor_user_id=u.recorder_user_id THEN
  RAISE EXCEPTION 'The person who recorded this usage may not reverse it' USING ERRCODE='23514';
 END IF;
 IF EXISTS (SELECT 1 FROM stock_movements g WHERE g.organisation_id=u.organisation_id AND g.reference_type='inventory_governed_case' AND g.id>u.movement_id
   AND g.inventory_sku_id=u.inventory_sku_id AND g.inventory_batch_id=u.inventory_batch_id
   AND (g.source_location_id=u.inventory_location_id OR g.destination_location_id=u.inventory_location_id)) THEN
  RAISE EXCEPTION 'A governed count or write-off was posted after this usage entry' USING ERRCODE='23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.id=NEW.movement_id AND m.organisation_id=u.organisation_id AND m.movement_type='adjustment_in'
   AND m.reference_type='inventory_treatment_usage' AND m.reference_public_id=u.public_id AND m.destination_location_id=u.inventory_location_id
   AND m.inventory_sku_id=u.inventory_sku_id AND m.inventory_batch_id=u.inventory_batch_id AND m.quantity=u.quantity AND m.actor_user_id=NEW.actor_user_id) THEN
  RAISE EXCEPTION 'Treatment usage reversal requires its matching stock movement' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION require_treatment_usage_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.reference_type<>'inventory_treatment_usage' OR NOT (
   (NEW.movement_type IN ('treatment_usage','treatment_waste') AND EXISTS (SELECT 1 FROM inventory_treatment_usages u WHERE u.movement_id=NEW.id AND u.public_id=NEW.reference_public_id))
   OR (NEW.movement_type='adjustment_in' AND EXISTS (SELECT 1 FROM inventory_treatment_usage_reversals r JOIN inventory_treatment_usages u ON u.id=r.usage_id WHERE r.movement_id=NEW.id AND u.public_id=NEW.reference_public_id))) THEN
  RAISE EXCEPTION 'Treatment usage movement requires its usage or reversal evidence' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER treatment_usages_insert BEFORE INSERT ON inventory_treatment_usages FOR EACH ROW EXECUTE FUNCTION guard_treatment_usage();
CREATE TRIGGER treatment_usage_reversals_insert BEFORE INSERT ON inventory_treatment_usage_reversals FOR EACH ROW EXECUTE FUNCTION guard_treatment_usage_reversal();
CREATE CONSTRAINT TRIGGER treatment_usage_movement_evidence AFTER INSERT ON stock_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.movement_type IN ('treatment_usage','treatment_waste') OR NEW.reference_type = 'inventory_treatment_usage') EXECUTE FUNCTION require_treatment_usage_evidence();
SQL);
        foreach (['inventory_treatment_usages', 'inventory_treatment_usage_reversals'] as $table) {
            DB::unprepared('CREATE TRIGGER '.$table.'_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION guard_treatment_usage_immutable()');
        }
    }

    public function down(): void
    {
        // Retain treatment usage evidence and its movement types.
    }
};
