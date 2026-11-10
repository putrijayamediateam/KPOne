<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DSP-15 (owner choice 2026-11-10, "go with DSP-15"): a quarantine check may split one return across the batches
 * its line was dispensed from. A split check keeps its returns location but no batch or movement of its own; each
 * share is one append-only row here with its own STK-11 dispensary_return movement. A single-batch quarantine is
 * stored exactly as before (DSP-7). PostgreSQL repeats every rule: a split has at least two shares that add up
 * exactly to the returned quantity (checked at commit), each share pairs with its own movement into the check's
 * location from a batch this line was dispensed from, and no batch ever gets back more than the line dispensed from
 * it, counting single checks and shares together. Runs after DSP-7's check table (2026_11_07_000500), a one-off in
 * the Inventory range like DSP-7's.
 */
return new class extends Migration
{
    /** The DSP-7 insert check, now counting DSP-15 shares in the per-batch limit and allowing a split check. */
    private const CHECK_FUNCTION = <<<'SQL'
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
        IF NOT EXISTS (SELECT 1 FROM inventory_locations l WHERE l.id = NEW.inventory_location_id AND l.branch_id = NEW.branch_id AND l.type = 'dispensary_returns') THEN
            RAISE EXCEPTION 'A quarantine check must go to a returns location at its branch' USING ERRCODE = '23514';
        END IF;
        -- DSP-15: a split check has no batch or movement of its own; its shares carry them (checked at commit).
        IF NEW.inventory_batch_id IS NULL THEN
            RETURN NEW;
        END IF;
        SELECT * INTO mv FROM stock_movements WHERE id = NEW.stock_movement_id;
        IF mv.id IS NULL OR mv.movement_type IS DISTINCT FROM 'dispensary_return' OR mv.reference_type IS DISTINCT FROM 'dispensary_return_check'
           OR mv.reference_public_id IS DISTINCT FROM NEW.public_id OR mv.organisation_id IS DISTINCT FROM NEW.organisation_id
           OR mv.destination_location_id IS DISTINCT FROM NEW.inventory_location_id OR mv.inventory_batch_id IS DISTINCT FROM NEW.inventory_batch_id
           OR mv.quantity IS DISTINCT FROM ret.quantity OR mv.actor_user_id IS DISTINCT FROM NEW.checked_by_user_id
           OR NOT EXISTS (SELECT 1 FROM dispensary_item_batch_allocations a WHERE a.dispensary_item_id = ret.dispensary_item_id AND a.inventory_batch_id = NEW.inventory_batch_id) THEN
            RAISE EXCEPTION 'A quarantine check must pair with its own dispensary_return movement' USING ERRCODE = '23514';
        END IF;
        -- Never quarantine more of one batch than this line was dispensed from it.
        IF ret.quantity + dispensary_return_quarantined_from_batch(ret.dispensary_item_id, NEW.inventory_batch_id)
           > (SELECT SUM(a.quantity) FROM dispensary_item_batch_allocations a WHERE a.dispensary_item_id = ret.dispensary_item_id AND a.inventory_batch_id = NEW.inventory_batch_id) THEN
            RAISE EXCEPTION 'More of this batch would be quarantined than the line dispensed from it' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END; $$;
SQL;

    public function up(): void
    {
        Schema::create('dispensary_return_check_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('dispensary_return_check_id')->constrained('dispensary_return_checks')->restrictOnDelete();
            $table->foreignId('inventory_batch_id')->constrained('inventory_batches')->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->foreignId('stock_movement_id')->unique('dispensary_return_check_batch_movement_once')->constrained('stock_movements')->restrictOnDelete();
            $table->unique(['dispensary_return_check_id', 'inventory_batch_id'], 'dispensary_return_check_batch_once');
        });
        DB::statement('ALTER TABLE dispensary_return_check_batches ADD CONSTRAINT dispensary_return_check_batch_quantity CHECK (quantity > 0)');

        DB::statement('ALTER TABLE dispensary_return_checks DROP CONSTRAINT dispensary_return_check_shape');
        DB::statement("ALTER TABLE dispensary_return_checks ADD CONSTRAINT dispensary_return_check_shape CHECK (
            (outcome = 'dispose' AND refuse_reason IS NULL AND refuse_note_encrypted IS NULL AND inventory_location_id IS NULL AND inventory_batch_id IS NULL AND stock_movement_id IS NULL)
            OR (outcome = 'quarantine' AND refuse_reason IS NULL AND refuse_note_encrypted IS NULL AND inventory_location_id IS NOT NULL
                AND ((inventory_batch_id IS NOT NULL AND stock_movement_id IS NOT NULL) OR (inventory_batch_id IS NULL AND stock_movement_id IS NULL)))
            OR (outcome = 'refuse' AND refuse_reason IN ('wrong_case_or_patient', 'wrong_medicine_line', 'wrong_quantity', 'recorded_twice', 'other')
                AND ((refuse_reason = 'other') = (refuse_note_encrypted IS NOT NULL))
                AND inventory_location_id IS NULL AND inventory_batch_id IS NULL AND stock_movement_id IS NULL)
        )");

        DB::unprepared(<<<'SQL'
-- What this line has already put back into one batch: single-batch quarantine checks plus DSP-15 shares.
CREATE OR REPLACE FUNCTION dispensary_return_quarantined_from_batch(item_id bigint, batch_id bigint) RETURNS numeric LANGUAGE sql STABLE AS $$
    SELECT COALESCE((SELECT SUM(r.quantity) FROM dispensary_return_checks k JOIN dispensary_returns r ON r.id = k.dispensary_return_id
            WHERE r.dispensary_item_id = item_id AND k.outcome = 'quarantine' AND k.inventory_batch_id = batch_id), 0)
         + COALESCE((SELECT SUM(b.quantity) FROM dispensary_return_check_batches b JOIN dispensary_return_checks k ON k.id = b.dispensary_return_check_id
            JOIN dispensary_returns r ON r.id = k.dispensary_return_id WHERE r.dispensary_item_id = item_id AND b.inventory_batch_id = batch_id), 0)
$$;
CREATE OR REPLACE FUNCTION validate_dispensary_return_check_batch_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    chk dispensary_return_checks%ROWTYPE;
    ret dispensary_returns%ROWTYPE;
    mv stock_movements%ROWTYPE;
BEGIN
    SELECT * INTO chk FROM dispensary_return_checks WHERE id = NEW.dispensary_return_check_id;
    IF chk.id IS NULL OR chk.organisation_id <> NEW.organisation_id OR chk.outcome IS DISTINCT FROM 'quarantine'
       OR chk.inventory_batch_id IS NOT NULL OR chk.stock_movement_id IS NOT NULL THEN
        RAISE EXCEPTION 'A batch share belongs only to a split quarantine check' USING ERRCODE = '23514';
    END IF;
    SELECT * INTO ret FROM dispensary_returns WHERE id = chk.dispensary_return_id;
    SELECT * INTO mv FROM stock_movements WHERE id = NEW.stock_movement_id;
    IF mv.id IS NULL OR mv.movement_type IS DISTINCT FROM 'dispensary_return' OR mv.reference_type IS DISTINCT FROM 'dispensary_return_check'
       OR mv.reference_public_id IS DISTINCT FROM chk.public_id OR mv.organisation_id IS DISTINCT FROM NEW.organisation_id
       OR mv.destination_location_id IS DISTINCT FROM chk.inventory_location_id OR mv.inventory_batch_id IS DISTINCT FROM NEW.inventory_batch_id
       OR mv.quantity IS DISTINCT FROM NEW.quantity OR mv.actor_user_id IS DISTINCT FROM chk.checked_by_user_id
       OR NOT EXISTS (SELECT 1 FROM dispensary_item_batch_allocations a WHERE a.dispensary_item_id = ret.dispensary_item_id AND a.inventory_batch_id = NEW.inventory_batch_id) THEN
        RAISE EXCEPTION 'A batch share must pair with its own dispensary_return movement' USING ERRCODE = '23514';
    END IF;
    IF NEW.quantity + dispensary_return_quarantined_from_batch(ret.dispensary_item_id, NEW.inventory_batch_id)
       > (SELECT SUM(a.quantity) FROM dispensary_item_batch_allocations a WHERE a.dispensary_item_id = ret.dispensary_item_id AND a.inventory_batch_id = NEW.inventory_batch_id) THEN
        RAISE EXCEPTION 'More of this batch would be quarantined than the line dispensed from it' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER dispensary_return_check_batches_source BEFORE INSERT ON dispensary_return_check_batches FOR EACH ROW EXECUTE FUNCTION validate_dispensary_return_check_batch_insert();
CREATE TRIGGER dispensary_return_check_batches_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON dispensary_return_check_batches FOR EACH STATEMENT EXECUTE FUNCTION refuse_dispensary_return_check_mutation();
-- A split check needs at least two shares that add up exactly to the returned quantity, by commit.
CREATE OR REPLACE FUNCTION require_dispensary_return_split_shares() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    shares integer;
    total numeric;
BEGIN
    SELECT COUNT(*), COALESCE(SUM(b.quantity), 0) INTO shares, total FROM dispensary_return_check_batches b WHERE b.dispensary_return_check_id = NEW.id;
    IF shares < 2 OR total IS DISTINCT FROM (SELECT r.quantity FROM dispensary_returns r WHERE r.id = NEW.dispensary_return_id) THEN
        RAISE EXCEPTION 'A split quarantine needs two or more batch shares that add up to the returned quantity' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END; $$;
CREATE CONSTRAINT TRIGGER dispensary_return_checks_split_shares AFTER INSERT ON dispensary_return_checks DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
    WHEN (NEW.outcome = 'quarantine' AND NEW.inventory_batch_id IS NULL) EXECUTE FUNCTION require_dispensary_return_split_shares();
-- Every dispensary_return movement pairs with its single-batch check or with one share of a split check, by commit.
CREATE OR REPLACE FUNCTION require_dispensary_return_check_pair() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM dispensary_return_checks k WHERE k.stock_movement_id = NEW.id AND k.outcome = 'quarantine' AND k.public_id = NEW.reference_public_id
    ) AND NOT EXISTS (
        SELECT 1 FROM dispensary_return_check_batches b JOIN dispensary_return_checks k ON k.id = b.dispensary_return_check_id
        WHERE b.stock_movement_id = NEW.id AND k.outcome = 'quarantine' AND k.public_id = NEW.reference_public_id
    ) THEN
        RAISE EXCEPTION 'A dispensary_return movement needs its quarantine check' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END; $$;
SQL);
        DB::unprepared(self::CHECK_FUNCTION);
    }

    public function down(): void
    {
        if (DB::table('dispensary_return_check_batches')->exists() || DB::table('dispensary_return_checks')->where('outcome', 'quarantine')->whereNull('inventory_batch_id')->exists()) {
            throw new RuntimeException('Split quarantine evidence exists; DSP-15 cannot be rolled back.');
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS dispensary_return_checks_split_shares ON dispensary_return_checks;
DROP FUNCTION IF EXISTS require_dispensary_return_split_shares();
CREATE OR REPLACE FUNCTION require_dispensary_return_check_pair() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM dispensary_return_checks k WHERE k.stock_movement_id = NEW.id AND k.outcome = 'quarantine' AND k.public_id = NEW.reference_public_id
    ) THEN
        RAISE EXCEPTION 'A dispensary_return movement needs its quarantine check' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END; $$;
SQL);
        Schema::drop('dispensary_return_check_batches');
        DB::unprepared('DROP FUNCTION IF EXISTS validate_dispensary_return_check_batch_insert()');
        DB::statement('ALTER TABLE dispensary_return_checks DROP CONSTRAINT dispensary_return_check_shape');
        DB::statement("ALTER TABLE dispensary_return_checks ADD CONSTRAINT dispensary_return_check_shape CHECK (
            (outcome = 'dispose' AND refuse_reason IS NULL AND refuse_note_encrypted IS NULL AND inventory_location_id IS NULL AND inventory_batch_id IS NULL AND stock_movement_id IS NULL)
            OR (outcome = 'quarantine' AND refuse_reason IS NULL AND refuse_note_encrypted IS NULL AND inventory_location_id IS NOT NULL AND inventory_batch_id IS NOT NULL AND stock_movement_id IS NOT NULL)
            OR (outcome = 'refuse' AND refuse_reason IN ('wrong_case_or_patient', 'wrong_medicine_line', 'wrong_quantity', 'recorded_twice', 'other')
                AND ((refuse_reason = 'other') = (refuse_note_encrypted IS NOT NULL))
                AND inventory_location_id IS NULL AND inventory_batch_id IS NULL AND stock_movement_id IS NULL)
        )");
        // Back to DSP-7's insert check, which counts single-batch quarantines only. Built from two literal constants.
        DB::unprepared(str_replace( // @phpstan-ignore argument.type
            'dispensary_return_quarantined_from_batch(ret.dispensary_item_id, NEW.inventory_batch_id)',
            "COALESCE((SELECT SUM(r2.quantity) FROM dispensary_return_checks k2 JOIN dispensary_returns r2 ON r2.id = k2.dispensary_return_id WHERE r2.dispensary_item_id = ret.dispensary_item_id AND k2.outcome = 'quarantine' AND k2.inventory_batch_id = NEW.inventory_batch_id), 0)",
            self::CHECK_FUNCTION,
        ));
        DB::unprepared('DROP FUNCTION IF EXISTS dispensary_return_quarantined_from_batch(bigint, bigint)');
    }
};
