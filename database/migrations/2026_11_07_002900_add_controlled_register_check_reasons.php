<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STK-40b (owner, 16 Nov 2026: "go with option b for blind count"): a controlled register check always records what was
 * counted, so nothing tells a blind checker "differs" before the count is saved. A check that differs is recorded with
 * no reason; the checker who recorded it adds the reason afterwards, in this append-only table (one reason per check).
 * Checks recorded before STK-40b keep their reason on the check row. DRAFT pending pharmacist review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_controlled_register_check_reasons', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            // Short names: the generated ones are longer than PostgreSQL's 63 characters and would clash once cut.
            $t->foreignId('controlled_register_check_id');
            $t->unique('controlled_register_check_id', 'controlled_register_check_reasons_check_unique');
            $t->foreign('controlled_register_check_id', 'controlled_register_check_reasons_check_fk')->references('id')->on('inventory_controlled_register_checks')->restrictOnDelete();
            $t->string('reason', 30);
            $t->string('note', 200)->nullable();
            $t->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at');
            $t->uuid('idempotency_key');
            $t->char('request_hash', 64);
            $t->unique(['organisation_id', 'idempotency_key'], 'controlled_register_check_reasons_idempotency_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE inventory_controlled_register_checks DROP CONSTRAINT controlled_register_checks_shape;
ALTER TABLE inventory_controlled_register_checks ADD CONSTRAINT controlled_register_checks_shape CHECK (
 sequence > 0 AND through_movement_id >= after_movement_id
 AND ledger_quantity >= 0 AND counted_quantity >= 0
 AND ((outcome = 'matches' AND counted_quantity = ledger_quantity AND reason IS NULL)
   OR (outcome = 'differs' AND counted_quantity <> ledger_quantity
       AND (reason IS NULL OR reason IN ('count_to_follow', 'stock_elsewhere', 'investigate', 'other'))))
 AND (note IS NULL OR length(btrim(note)) BETWEEN 1 AND 200)
 AND (reason IS NOT NULL OR note IS NULL)
 AND (reason IS DISTINCT FROM 'other' OR note IS NOT NULL)
);
ALTER TABLE inventory_controlled_register_check_reasons ADD CONSTRAINT controlled_register_check_reasons_shape CHECK (
 reason IN ('count_to_follow', 'stock_elsewhere', 'investigate', 'other')
 AND (note IS NULL OR length(btrim(note)) BETWEEN 1 AND 200)
 AND (reason <> 'other' OR note IS NOT NULL)
);
CREATE OR REPLACE FUNCTION guard_controlled_register_check_reason() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 checked inventory_controlled_register_checks%ROWTYPE;
BEGIN
 IF TG_OP <> 'INSERT' THEN
  RAISE EXCEPTION 'Controlled register check reasons are kept as recorded' USING ERRCODE = '23514';
 END IF;
 SELECT * INTO checked FROM inventory_controlled_register_checks c WHERE c.id = NEW.controlled_register_check_id;
 IF checked.id IS NULL OR checked.organisation_id <> NEW.organisation_id OR checked.branch_id <> NEW.branch_id THEN
  RAISE EXCEPTION 'A check reason belongs to its check''s organisation and branch' USING ERRCODE = '23514';
 END IF;
 IF checked.outcome <> 'differs' OR checked.reason IS NOT NULL THEN
  RAISE EXCEPTION 'Only a check that differs and has no reason yet takes a reason' USING ERRCODE = '23514';
 END IF;
 IF checked.checked_by_user_id <> NEW.recorded_by_user_id THEN
  RAISE EXCEPTION 'Only the person who recorded the check adds its reason' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION refuse_controlled_register_check_reason_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Controlled register check reasons are kept as recorded' USING ERRCODE = '23514';
END $$;
CREATE TRIGGER controlled_register_check_reasons_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_controlled_register_check_reasons FOR EACH ROW EXECUTE FUNCTION guard_controlled_register_check_reason();
CREATE TRIGGER controlled_register_check_reasons_no_truncate BEFORE TRUNCATE ON inventory_controlled_register_check_reasons FOR EACH STATEMENT EXECUTE FUNCTION refuse_controlled_register_check_reason_truncate();
SQL);
    }

    public function down(): void
    {
        // Rolling back would lose a reason, or leave a differing check that the old rule refuses: refuse instead.
        if (DB::table('inventory_controlled_register_check_reasons')->exists()
            || DB::table('inventory_controlled_register_checks')->where('outcome', 'differs')->whereNull('reason')->exists()) {
            throw new RuntimeException('STK-40b check reasons are recorded; this migration cannot be rolled back.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS controlled_register_check_reasons_no_truncate ON inventory_controlled_register_check_reasons;
DROP TRIGGER IF EXISTS controlled_register_check_reasons_guard ON inventory_controlled_register_check_reasons;
ALTER TABLE inventory_controlled_register_checks DROP CONSTRAINT controlled_register_checks_shape;
ALTER TABLE inventory_controlled_register_checks ADD CONSTRAINT controlled_register_checks_shape CHECK (
 sequence > 0 AND through_movement_id >= after_movement_id
 AND ledger_quantity >= 0 AND counted_quantity >= 0
 AND ((outcome = 'matches' AND counted_quantity = ledger_quantity AND reason IS NULL)
   OR (outcome = 'differs' AND counted_quantity <> ledger_quantity AND reason IN ('count_to_follow', 'stock_elsewhere', 'investigate', 'other')))
 AND (note IS NULL OR length(btrim(note)) BETWEEN 1 AND 200)
 AND (reason IS DISTINCT FROM 'other' OR note IS NOT NULL)
);
SQL);
        }
        Schema::dropIfExists('inventory_controlled_register_check_reasons');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS refuse_controlled_register_check_reason_truncate(); DROP FUNCTION IF EXISTS guard_controlled_register_check_reason();');
        }
    }
};
