<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-7 step 2 (owner decisions 2026-11-05, TEAM.md decisions 20 and 21): a CA Supervisor who did not record a
 * return checks it once: Dispose (no stock change), Quarantine (credits the units to a patient returns location
 * through STK-11's dispensary_return movement) or Refuse (a fixed reason; the quantity can be recorded again).
 * A controlled medicine is never quarantined. Runs after STK-11 (2026_11_07_000400). Append-only; PostgreSQL repeats
 * the checker, controlled, outcome-shape and movement-pairing rules, and refused returns stop counting towards the
 * returned quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispensary_return_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensary_return_id')->unique('dispensary_return_checked_once')->constrained('dispensary_returns')->restrictOnDelete();
            $table->string('outcome', 20);
            $table->string('refuse_reason', 40)->nullable();
            $table->text('refuse_note_encrypted')->nullable();
            $table->foreignId('inventory_location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->nullable()->constrained('inventory_batches')->restrictOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->unique('dispensary_return_check_movement_once')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('checked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_at');
            $table->uuid('retry_key');
            $table->string('payload_hash', 64);
            $table->unique(['organisation_id', 'checked_by_user_id', 'retry_key'], 'dispensary_return_check_retry_once');
        });
        DB::statement("ALTER TABLE dispensary_return_checks ADD CONSTRAINT dispensary_return_check_shape CHECK (
            (outcome = 'dispose' AND refuse_reason IS NULL AND refuse_note_encrypted IS NULL AND inventory_location_id IS NULL AND inventory_batch_id IS NULL AND stock_movement_id IS NULL)
            OR (outcome = 'quarantine' AND refuse_reason IS NULL AND refuse_note_encrypted IS NULL AND inventory_location_id IS NOT NULL AND inventory_batch_id IS NOT NULL AND stock_movement_id IS NOT NULL)
            OR (outcome = 'refuse' AND refuse_reason IN ('wrong_case_or_patient', 'wrong_medicine_line', 'wrong_quantity', 'recorded_twice', 'other')
                AND ((refuse_reason = 'other') = (refuse_note_encrypted IS NOT NULL))
                AND inventory_location_id IS NULL AND inventory_batch_id IS NULL AND stock_movement_id IS NULL)
        )");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION dispensary_return_open_quantity(item_id bigint) RETURNS numeric LANGUAGE sql STABLE AS $$
    SELECT COALESCE(SUM(r.quantity), 0) FROM dispensary_returns r
    WHERE r.dispensary_item_id = item_id
      AND NOT EXISTS (SELECT 1 FROM dispensary_return_checks k WHERE k.dispensary_return_id = r.id AND k.outcome = 'refuse')
$$;
CREATE OR REPLACE FUNCTION validate_dispensary_return_check_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    ret dispensary_returns%ROWTYPE;
    controlled boolean;
    mv stock_movements%ROWTYPE;
BEGIN
    SELECT * INTO ret FROM dispensary_returns WHERE id = NEW.dispensary_return_id;
    IF ret.id IS NULL OR ret.organisation_id <> NEW.organisation_id OR ret.branch_id <> NEW.branch_id
       OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.checked_by_user_id AND u.organisation_id = NEW.organisation_id) THEN
        RAISE EXCEPTION 'Invalid dispensary return check source' USING ERRCODE = '23514';
    END IF;
    IF ret.recorded_by_user_id = NEW.checked_by_user_id THEN
        RAISE EXCEPTION 'A dispensary return must be checked by someone other than its recorder' USING ERRCODE = '23514';
    END IF;
    controlled := COALESCE((SELECT m.is_controlled
        FROM dispensary_items i JOIN medicine_catalogue_items m ON m.id = i.medicine_catalogue_item_id
        WHERE i.id = ret.dispensary_item_id), false);
    IF NEW.outcome = 'quarantine' THEN
        IF controlled THEN
            RAISE EXCEPTION 'A returned controlled medicine is never quarantined' USING ERRCODE = '23514';
        END IF;
        SELECT * INTO mv FROM stock_movements WHERE id = NEW.stock_movement_id;
        IF mv.id IS NULL OR mv.movement_type IS DISTINCT FROM 'dispensary_return' OR mv.reference_type IS DISTINCT FROM 'dispensary_return_check'
           OR mv.reference_public_id IS DISTINCT FROM NEW.public_id OR mv.organisation_id IS DISTINCT FROM NEW.organisation_id
           OR mv.destination_location_id IS DISTINCT FROM NEW.inventory_location_id OR mv.inventory_batch_id IS DISTINCT FROM NEW.inventory_batch_id
           OR mv.quantity IS DISTINCT FROM ret.quantity OR mv.actor_user_id IS DISTINCT FROM NEW.checked_by_user_id
           OR NOT EXISTS (SELECT 1 FROM inventory_locations l WHERE l.id = NEW.inventory_location_id AND l.branch_id = NEW.branch_id AND l.type = 'dispensary_returns')
           OR NOT EXISTS (SELECT 1 FROM dispensary_item_batch_allocations a WHERE a.dispensary_item_id = ret.dispensary_item_id AND a.inventory_batch_id = NEW.inventory_batch_id) THEN
            RAISE EXCEPTION 'A quarantine check must pair with its own dispensary_return movement' USING ERRCODE = '23514';
        END IF;
        -- Never quarantine more of one batch than this line was dispensed from it.
        IF ret.quantity + COALESCE((SELECT SUM(r2.quantity) FROM dispensary_return_checks k2 JOIN dispensary_returns r2 ON r2.id = k2.dispensary_return_id
                WHERE r2.dispensary_item_id = ret.dispensary_item_id AND k2.outcome = 'quarantine' AND k2.inventory_batch_id = NEW.inventory_batch_id), 0)
           > (SELECT SUM(a.quantity) FROM dispensary_item_batch_allocations a WHERE a.dispensary_item_id = ret.dispensary_item_id AND a.inventory_batch_id = NEW.inventory_batch_id) THEN
            RAISE EXCEPTION 'More of this batch would be quarantined than the line dispensed from it' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER dispensary_return_checks_source BEFORE INSERT ON dispensary_return_checks FOR EACH ROW EXECUTE FUNCTION validate_dispensary_return_check_insert();
CREATE OR REPLACE FUNCTION refuse_dispensary_return_check_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Dispensary return checks are append-only' USING ERRCODE = 'P0001';
END; $$;
CREATE TRIGGER dispensary_return_checks_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON dispensary_return_checks FOR EACH STATEMENT EXECUTE FUNCTION refuse_dispensary_return_check_mutation();
-- Every dispensary_return movement must be paired with its quarantine check by commit (STK-11 leaves this to DSP-7).
CREATE OR REPLACE FUNCTION require_dispensary_return_check_pair() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM dispensary_return_checks k WHERE k.stock_movement_id = NEW.id AND k.outcome = 'quarantine' AND k.public_id = NEW.reference_public_id
    ) THEN
        RAISE EXCEPTION 'A dispensary_return movement needs its quarantine check' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER stock_movements_dispensary_return_pair AFTER INSERT ON stock_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
    WHEN (NEW.movement_type = 'dispensary_return' OR NEW.reference_type = 'dispensary_return_check') EXECUTE FUNCTION require_dispensary_return_check_pair();
SQL);
    }

    public function down(): void
    {
        // Retain the append-only check evidence; no purge.
    }
};
