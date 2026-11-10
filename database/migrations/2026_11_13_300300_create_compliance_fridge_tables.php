<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-11b vaccine fridge log (docs/teams/hr/HR-11-DESIGN.md section 6). The clinic enters its own fridges and allowed range
 * (KPOne ships none). A reading is append-only, stored in whole tenths of a degree and keeps the range that applied when
 * it was taken, so a later change of range never changes an old alert. A review (what was done) and an "entered in
 * error" mark are separate append-only rows. Nothing here is a certification: it records what a person typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_fridges', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('name', 80);
            $t->smallInteger('min_tenths');
            $t->smallInteger('max_tenths');
            $t->smallInteger('readings_per_day')->default(1);
            $t->time('expect_by')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'compliance_fridges_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('branch_id', 'compliance_fridges_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('created_by_user_id', 'compliance_fridges_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX compliance_fridges_name_once ON compliance_fridges (branch_id, lower(name)) WHERE is_active');

        Schema::create('compliance_fridge_readings', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('fridge_id');
            $t->unsignedBigInteger('branch_id');
            $t->timestampTz('taken_at');
            $t->smallInteger('temp_tenths');
            $t->smallInteger('low_tenths')->nullable();
            $t->smallInteger('high_tenths')->nullable();
            $t->smallInteger('range_min_tenths');
            $t->smallInteger('range_max_tenths');
            $t->string('reason_code', 20)->nullable();
            $t->unsignedBigInteger('entered_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'compliance_fridge_readings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('fridge_id', 'compliance_fridge_readings_fridge_fk')->references('id')->on('compliance_fridges')->restrictOnDelete();
            $t->foreign('branch_id', 'compliance_fridge_readings_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('entered_by_user_id', 'compliance_fridge_readings_user_fk')->references('id')->on('users')->restrictOnDelete();
            $t->index(['fridge_id', 'taken_at'], 'compliance_fridge_readings_fridge_time');
        });

        Schema::create('compliance_fridge_reviews', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('reading_id')->unique();
            $t->string('action_code', 20);
            $t->text('note_encrypted')->nullable();
            $t->unsignedBigInteger('reviewed_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'compliance_fridge_reviews_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('reading_id', 'compliance_fridge_reviews_reading_fk')->references('id')->on('compliance_fridge_readings')->restrictOnDelete();
            $t->foreign('reviewed_by_user_id', 'compliance_fridge_reviews_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('compliance_fridge_corrections', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('reading_id')->unique();
            $t->string('reason_code', 20);
            $t->unsignedBigInteger('corrected_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'compliance_fridge_corrections_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('reading_id', 'compliance_fridge_corrections_reading_fk')->references('id')->on('compliance_fridge_readings')->restrictOnDelete();
            $t->foreign('corrected_by_user_id', 'compliance_fridge_corrections_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE compliance_fridges ADD CONSTRAINT compliance_fridges_shape CHECK (char_length(btrim(name)) BETWEEN 2 AND 80 AND min_tenths BETWEEN -300 AND 300 AND max_tenths BETWEEN -300 AND 300 AND min_tenths < max_tenths AND readings_per_day BETWEEN 1 AND 6)');
        DB::statement("ALTER TABLE compliance_fridge_readings ADD CONSTRAINT compliance_fridge_readings_shape CHECK (temp_tenths BETWEEN -300 AND 300 AND (low_tenths IS NULL OR low_tenths BETWEEN -300 AND 300) AND (high_tenths IS NULL OR high_tenths BETWEEN -300 AND 300) AND (low_tenths IS NULL OR low_tenths <= temp_tenths) AND (high_tenths IS NULL OR high_tenths >= temp_tenths) AND range_min_tenths < range_max_tenths AND (reason_code IS NULL OR reason_code IN ('door_open', 'defrost', 'power_cut', 'delivery', 'other')))");
        DB::statement("ALTER TABLE compliance_fridge_reviews ADD CONSTRAINT compliance_fridge_reviews_shape CHECK (action_code IN ('rechecked_ok', 'moved_stock', 'called_supplier', 'discarded_stock', 'other') AND (action_code <> 'other' OR note_encrypted IS NOT NULL))");
        DB::statement("ALTER TABLE compliance_fridge_corrections ADD CONSTRAINT compliance_fridge_corrections_shape CHECK (reason_code IN ('wrong_number', 'wrong_fridge', 'duplicate', 'other'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER compliance_fridge_readings_append BEFORE UPDATE OR DELETE ON compliance_fridge_readings FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_fridge_reviews_append BEFORE UPDATE OR DELETE ON compliance_fridge_reviews FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_fridge_corrections_append BEFORE UPDATE OR DELETE ON compliance_fridge_corrections FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_fridges_no_delete BEFORE DELETE ON compliance_fridges FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_fridge_readings_truncate BEFORE TRUNCATE ON compliance_fridge_readings FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_fridge_reviews_truncate BEFORE TRUNCATE ON compliance_fridge_reviews FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_fridge_corrections_truncate BEFORE TRUNCATE ON compliance_fridge_corrections FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_fridges_truncate BEFORE TRUNCATE ON compliance_fridges FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();

-- A fridge keeps its branch and organisation; an archived fridge stays archived.
CREATE OR REPLACE FUNCTION guard_compliance_fridge() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id
   OR NEW.created_by_user_id <> OLD.created_by_user_id THEN
  RAISE EXCEPTION 'A fridge keeps its branch; archive it and add a new one' USING ERRCODE = 'P0001';
 END IF;
 IF NOT OLD.is_active AND NEW.is_active THEN
  RAISE EXCEPTION 'An archived fridge stays archived' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_fridges_guard BEFORE UPDATE ON compliance_fridges FOR EACH ROW EXECUTE FUNCTION guard_compliance_fridge();

-- A reading belongs to its fridge's organisation and branch, keeps that fridge's range at the time, and goes only to an
-- active fridge. A review and a correction must match the reading's organisation.
CREATE OR REPLACE FUNCTION guard_compliance_fridge_reading() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE f compliance_fridges%ROWTYPE;
BEGIN
 SELECT * INTO f FROM compliance_fridges WHERE id = NEW.fridge_id;
 IF f.id IS NULL OR f.organisation_id <> NEW.organisation_id OR f.branch_id <> NEW.branch_id THEN
  RAISE EXCEPTION 'A reading must belong to its fridge and branch' USING ERRCODE = 'P0001';
 END IF;
 IF NOT f.is_active THEN
  RAISE EXCEPTION 'An archived fridge takes no new readings' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.range_min_tenths <> f.min_tenths OR NEW.range_max_tenths <> f.max_tenths THEN
  RAISE EXCEPTION 'A reading keeps the fridge range that applied when it was taken' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_fridge_readings_guard BEFORE INSERT ON compliance_fridge_readings FOR EACH ROW EXECUTE FUNCTION guard_compliance_fridge_reading();

CREATE OR REPLACE FUNCTION guard_compliance_fridge_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r compliance_fridge_readings%ROWTYPE;
BEGIN
 SELECT * INTO r FROM compliance_fridge_readings WHERE id = NEW.reading_id;
 IF r.id IS NULL OR r.organisation_id <> NEW.organisation_id THEN
  RAISE EXCEPTION 'This must belong to the reading''s organisation' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_fridge_reviews_guard BEFORE INSERT ON compliance_fridge_reviews FOR EACH ROW EXECUTE FUNCTION guard_compliance_fridge_child();
CREATE TRIGGER compliance_fridge_corrections_guard BEFORE INSERT ON compliance_fridge_corrections FOR EACH ROW EXECUTE FUNCTION guard_compliance_fridge_child();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_fridge_corrections');
        Schema::dropIfExists('compliance_fridge_reviews');
        Schema::dropIfExists('compliance_fridge_readings');
        Schema::dropIfExists('compliance_fridges');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_compliance_fridge_child(); DROP FUNCTION IF EXISTS guard_compliance_fridge_reading(); DROP FUNCTION IF EXISTS guard_compliance_fridge();');
    }
};
