<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-24 (owner programme 2026-11-11, plan approved by B.E.R.N.I.S.S): the controlled-drug register is read from the
 * stock ledger; this table holds only its periodic checks. Each check covers the movements after the previous check,
 * up to the newest one the checker saw, and is signed by a second person: never anyone who recorded a movement in
 * that range, and never whoever did the previous check. Append only. DRAFT pending pharmacist review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_controlled_register_checks', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_sku_id')->constrained('inventory_skus')->restrictOnDelete();
            $t->unsignedInteger('sequence');
            // Movements with after_movement_id < id <= through_movement_id; 0 means "from the first movement".
            $t->unsignedBigInteger('after_movement_id');
            $t->unsignedBigInteger('through_movement_id');
            $t->decimal('ledger_quantity', 15, 3);
            $t->decimal('counted_quantity', 15, 3);
            $t->string('outcome', 10);
            $t->string('reason', 30)->nullable();
            $t->string('note', 200)->nullable();
            $t->foreignId('checked_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('checked_at');
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['organisation_id', 'idempotency_key'], 'controlled_register_checks_idempotency_unique');
            $t->unique(['branch_id', 'inventory_sku_id', 'sequence'], 'controlled_register_checks_sequence_unique');
            $t->unique(['branch_id', 'inventory_sku_id', 'after_movement_id'], 'controlled_register_checks_range_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE inventory_controlled_register_checks ADD CONSTRAINT controlled_register_checks_shape CHECK (
 sequence > 0 AND through_movement_id >= after_movement_id
 AND ledger_quantity >= 0 AND counted_quantity >= 0
 AND ((outcome = 'matches' AND counted_quantity = ledger_quantity AND reason IS NULL)
   OR (outcome = 'differs' AND counted_quantity <> ledger_quantity AND reason IN ('count_to_follow', 'stock_elsewhere', 'investigate', 'other')))
 AND (note IS NULL OR length(btrim(note)) BETWEEN 1 AND 200)
 AND (reason IS DISTINCT FROM 'other' OR note IS NOT NULL)
);
CREATE OR REPLACE FUNCTION guard_controlled_register_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 previous inventory_controlled_register_checks%ROWTYPE;
 ledger numeric;
BEGIN
 IF TG_OP <> 'INSERT' THEN
  RAISE EXCEPTION 'Controlled register checks are kept as signed' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = NEW.branch_id AND b.organisation_id = NEW.organisation_id)
  OR NOT EXISTS (SELECT 1 FROM inventory_skus s WHERE s.id = NEW.inventory_sku_id AND s.organisation_id = NEW.organisation_id)
  OR NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.checked_by_user_id AND u.organisation_id = NEW.organisation_id) THEN
  RAISE EXCEPTION 'A controlled register check belongs to one organisation' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM medicine_catalogue_inventory_skus map JOIN medicine_catalogue_items m ON m.id = map.medicine_catalogue_item_id
   WHERE map.inventory_sku_id = NEW.inventory_sku_id AND map.is_active AND m.is_controlled) THEN
  RAISE EXCEPTION 'Only a controlled medicine has a controlled register' USING ERRCODE = '23514';
 END IF;
 SELECT * INTO previous FROM inventory_controlled_register_checks c
  WHERE c.branch_id = NEW.branch_id AND c.inventory_sku_id = NEW.inventory_sku_id ORDER BY c.sequence DESC LIMIT 1;
 IF NEW.sequence <> COALESCE(previous.sequence, 0) + 1 OR NEW.after_movement_id <> COALESCE(previous.through_movement_id, 0) THEN
  RAISE EXCEPTION 'A controlled register check starts where the previous check ended' USING ERRCODE = '23514';
 END IF;
 IF previous.checked_by_user_id = NEW.checked_by_user_id THEN
  RAISE EXCEPTION 'The previous checker cannot check this register again' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM stock_movements m
   LEFT JOIN inventory_locations src ON src.id = m.source_location_id
   LEFT JOIN inventory_locations dst ON dst.id = m.destination_location_id
   WHERE m.inventory_sku_id = NEW.inventory_sku_id AND m.id > NEW.after_movement_id AND m.id <= NEW.through_movement_id
     AND (src.branch_id = NEW.branch_id OR dst.branch_id = NEW.branch_id) AND m.actor_user_id = NEW.checked_by_user_id) THEN
  RAISE EXCEPTION 'Someone who recorded a movement in this range cannot check it' USING ERRCODE = '23514';
 END IF;
 IF NEW.through_movement_id > 0 AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.id = NEW.through_movement_id AND m.inventory_sku_id = NEW.inventory_sku_id) THEN
  RAISE EXCEPTION 'A controlled register check ends on one of this medicine''s movements' USING ERRCODE = '23514';
 END IF;
 SELECT COALESCE(SUM(CASE WHEN dst.branch_id = NEW.branch_id THEN m.quantity ELSE 0 END)
               - SUM(CASE WHEN src.branch_id = NEW.branch_id THEN m.quantity ELSE 0 END), 0) INTO ledger
  FROM stock_movements m
  LEFT JOIN inventory_locations src ON src.id = m.source_location_id
  LEFT JOIN inventory_locations dst ON dst.id = m.destination_location_id
  WHERE m.inventory_sku_id = NEW.inventory_sku_id AND m.id <= NEW.through_movement_id
    AND (src.branch_id = NEW.branch_id OR dst.branch_id = NEW.branch_id);
 IF ledger <> NEW.ledger_quantity THEN
  RAISE EXCEPTION 'A controlled register check records the ledger balance' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION refuse_controlled_register_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Controlled register checks are kept as signed' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER controlled_register_checks_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_controlled_register_checks FOR EACH ROW EXECUTE FUNCTION guard_controlled_register_check();
CREATE TRIGGER controlled_register_checks_no_truncate BEFORE TRUNCATE ON inventory_controlled_register_checks FOR EACH STATEMENT EXECUTE FUNCTION refuse_controlled_register_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS controlled_register_checks_no_truncate ON inventory_controlled_register_checks; DROP TRIGGER IF EXISTS controlled_register_checks_guard ON inventory_controlled_register_checks;');
        }
        Schema::dropIfExists('inventory_controlled_register_checks');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS refuse_controlled_register_truncate(); DROP FUNCTION IF EXISTS guard_controlled_register_check();');
        }
    }
};
