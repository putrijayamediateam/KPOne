<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STK-11 (owner decision 2026-11-05, for DSP-7): a "patient returns" location type and the dispensary_return
 * movement. Stock in a returns location is never allocated or dispensed; it arrives only as a dispensary return or
 * a governed count gain, and leaves only by a governed count or write-off. A direct transfer is allowed only between
 * two returns locations, so returned medicine never becomes dispensable again. No data changes.
 */
return new class extends Migration
{
    private const SHAPE_WITH_RETURN = "quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL) OR (movement_type IN ('purchase_receipt','transfer_receipt','stocktake_gain','adjustment_in','dispensary_return') AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type IN ('transfer_dispatch','stocktake_loss','adjustment_out','expiry_writeoff','stock_writeoff','supplier_return','treatment_usage','treatment_waste') AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NULL))";

    private const SHAPE_BEFORE = "quantity > 0 AND ((movement_type = 'opening_balance' AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'transfer' AND source_location_id IS NOT NULL AND destination_location_id IS NOT NULL AND source_location_id <> destination_location_id AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type = 'dispense' AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NOT NULL) OR (movement_type IN ('purchase_receipt','transfer_receipt','stocktake_gain','adjustment_in') AND source_location_id IS NULL AND destination_location_id IS NOT NULL AND dispensary_item_batch_allocation_id IS NULL) OR (movement_type IN ('transfer_dispatch','stocktake_loss','adjustment_out','expiry_writeoff','stock_writeoff','supplier_return','treatment_usage','treatment_waste') AND source_location_id IS NOT NULL AND destination_location_id IS NULL AND dispensary_item_batch_allocation_id IS NULL))";

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK ('.self::SHAPE_WITH_RETURN.')');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_returns_location_movement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE from_returns boolean; to_returns boolean;
BEGIN
 from_returns := EXISTS (SELECT 1 FROM inventory_locations l WHERE l.id=NEW.source_location_id AND l.type='dispensary_returns');
 to_returns := EXISTS (SELECT 1 FROM inventory_locations l WHERE l.id=NEW.destination_location_id AND l.type='dispensary_returns');
 IF NEW.movement_type='transfer' AND from_returns <> to_returns THEN
  RAISE EXCEPTION 'Returned medicine can only move to another returns location' USING ERRCODE='23514';
 END IF;
 IF from_returns AND NEW.movement_type NOT IN ('transfer','stocktake_loss','adjustment_out','expiry_writeoff','stock_writeoff') THEN
  RAISE EXCEPTION 'Stock in a returns location can only be written off or transferred' USING ERRCODE='23514';
 END IF;
 IF to_returns AND (NEW.movement_type NOT IN ('dispensary_return','stocktake_gain','adjustment_in','transfer')
   OR (NEW.movement_type IN ('stocktake_gain','adjustment_in') AND NEW.reference_type<>'inventory_governed_case')) THEN
  RAISE EXCEPTION 'Only returned medicine can go into a returns location' USING ERRCODE='23514';
 END IF;
 IF NEW.movement_type='dispensary_return' AND (NEW.reference_type<>'dispensary_return_check' OR NOT EXISTS (
   SELECT 1 FROM inventory_locations l WHERE l.id=NEW.destination_location_id AND l.organisation_id=NEW.organisation_id AND l.type='dispensary_returns' AND l.is_active AND l.branch_id IS NOT NULL)) THEN
  RAISE EXCEPTION 'A dispensary return must go to an active returns location at a branch' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION guard_returns_location_allocation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS (SELECT 1 FROM inventory_locations l WHERE l.id=NEW.inventory_location_id AND l.type='dispensary_returns') THEN
  RAISE EXCEPTION 'Stock in a returns location is never dispensed' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE OR REPLACE FUNCTION guard_returns_location_type() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.type IS DISTINCT FROM OLD.type AND 'dispensary_returns' IN (OLD.type, NEW.type) THEN
  RAISE EXCEPTION 'A returns location keeps its type' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER stock_movements_returns_location BEFORE INSERT ON stock_movements FOR EACH ROW EXECUTE FUNCTION guard_returns_location_movement();
CREATE TRIGGER dispensary_allocations_returns_location BEFORE INSERT OR UPDATE OF inventory_location_id ON dispensary_item_batch_allocations FOR EACH ROW EXECUTE FUNCTION guard_returns_location_allocation();
CREATE TRIGGER inventory_locations_returns_type BEFORE UPDATE OF type ON inventory_locations FOR EACH ROW EXECUTE FUNCTION guard_returns_location_type();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS stock_movements_returns_location ON stock_movements;
DROP TRIGGER IF EXISTS dispensary_allocations_returns_location ON dispensary_item_batch_allocations;
DROP TRIGGER IF EXISTS inventory_locations_returns_type ON inventory_locations;
DROP FUNCTION IF EXISTS guard_returns_location_movement();
DROP FUNCTION IF EXISTS guard_returns_location_allocation();
DROP FUNCTION IF EXISTS guard_returns_location_type();
SQL);
        // Refused by PostgreSQL if any dispensary_return movement exists, so retained evidence is never orphaned.
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_shape_check');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_shape_check CHECK ('.self::SHAPE_BEFORE.')');
    }
};
