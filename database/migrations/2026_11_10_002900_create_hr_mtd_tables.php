<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-7d MTD (PCB) worked out by KPOne (HR-PLAN section 18; the owner's "start the HR-7d build", 2026-11-07). A yearly
 * schedule the clinic enters and someone else checks (KPOne ships no tax number), and each person's MTD details on
 * their pay profile. Rows are append-only; a schedule is never deleted; profile MTD details never change after entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_mtd_schedules', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedSmallInteger('year');
            $t->string('rounding', 12); // up_5_sen | nearest_sen | none
            $t->unsignedBigInteger('minimum_sen'); // a smaller MTD deducts nothing
            $t->unsignedBigInteger('individual_relief_sen'); // D
            $t->unsignedBigInteger('spouse_relief_sen'); // S, category 2 only
            $t->unsignedBigInteger('disabled_self_relief_sen'); // DU
            $t->unsignedBigInteger('disabled_spouse_relief_sen'); // SU, category 2 only
            $t->unsignedBigInteger('child_relief_sen'); // Q, per child
            $t->unsignedBigInteger('epf_relief_cap_sen'); // the year's EPF relief limit
            $t->string('source_note', 200);
            $t->string('status', 10)->default('entered'); // entered | checked | rejected | withdrawn | replaced
            $t->unsignedBigInteger('entered_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_mtd_schedules_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            foreach (['entered_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_mtd_schedules_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_mtd_schedules_scope_unique');
            $t->index(['organisation_id', 'year', 'status'], 'hr_mtd_schedules_year');
        });

        Schema::create('hr_mtd_schedule_rows', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('schedule_id');
            $t->unsignedSmallInteger('position');
            $t->unsignedBigInteger('chargeable_from_sen'); // M
            $t->unsignedBigInteger('chargeable_to_sen')->nullable(); // null: and above
            $t->unsignedInteger('rate_bps'); // R
            $t->bigInteger('base_cat13_sen'); // B for categories 1 and 3 (may be below zero)
            $t->bigInteger('base_cat2_sen'); // B for category 2
            $t->foreign(['schedule_id', 'organisation_id'], 'hr_mtd_schedule_rows_schedule_fk')->references(['id', 'organisation_id'])->on('hr_mtd_schedules')->restrictOnDelete();
            $t->unique(['schedule_id', 'position'], 'hr_mtd_schedule_rows_position');
        });

        Schema::table('hr_pay_profiles', function (Blueprint $t): void {
            $t->unsignedSmallInteger('mtd_category')->nullable(); // 1 single | 2 spouse not working | 3 spouse working
            $t->unsignedSmallInteger('mtd_children')->nullable();
            $t->boolean('mtd_disabled_self')->default(false);
            $t->boolean('mtd_disabled_spouse')->default(false);
        });

        DB::statement(<<<'SQL'
ALTER TABLE hr_mtd_schedules ADD CONSTRAINT hr_mtd_schedules_shape CHECK (
 year BETWEEN 2000 AND 2099
 AND rounding IN ('up_5_sen', 'nearest_sen', 'none')
 AND char_length(btrim(source_note)) BETWEEN 2 AND 200
 AND status IN ('entered', 'checked', 'rejected', 'withdrawn', 'replaced')
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (status IN ('entered', 'withdrawn')) = (decided_by_user_id IS NULL)
 AND (status = 'rejected') = (decision_reason IS NOT NULL)
 AND (decision_reason IS NULL OR decision_reason IN ('wrong_figures', 'wrong_category', 'wrong_date', 'other'))
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> entered_by_user_id)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_mtd_schedule_rows ADD CONSTRAINT hr_mtd_schedule_rows_shape CHECK (
 position BETWEEN 1 AND 40
 AND (chargeable_to_sen IS NULL OR chargeable_to_sen >= chargeable_from_sen)
 AND rate_bps <= 10000
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_pay_profiles ADD CONSTRAINT hr_pay_profiles_mtd_shape CHECK (
 (mtd_category IS NULL OR (mtd AND mtd_category IN (1, 2, 3)))
 AND ((mtd_category IS NULL) = (mtd_children IS NULL))
 AND (mtd_children IS NULL OR mtd_children <= 20)
 AND (NOT mtd_disabled_self OR mtd_category IS NOT NULL)
 AND (NOT mtd_disabled_spouse OR mtd_category = 2)
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_mtd_schedules_one_entered ON hr_mtd_schedules (organisation_id, year) WHERE status = 'entered'");
        DB::statement("CREATE UNIQUE INDEX hr_mtd_schedules_one_checked ON hr_mtd_schedules (organisation_id, year) WHERE status = 'checked'");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_mtd_schedule_rows_append BEFORE UPDATE OR DELETE ON hr_mtd_schedule_rows FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_mtd_schedules_no_delete BEFORE DELETE ON hr_mtd_schedules FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_mtd_schedules_truncate BEFORE TRUNCATE ON hr_mtd_schedules FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_mtd_schedule_rows_truncate BEFORE TRUNCATE ON hr_mtd_schedule_rows FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A schedule's figures never change after entry; only its status moves forward, decided by someone else.
CREATE OR REPLACE FUNCTION guard_hr_mtd_schedule() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'entered' THEN RAISE EXCEPTION 'An MTD schedule is entered for someone else to check' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.year <> OLD.year
   OR NEW.rounding <> OLD.rounding OR NEW.minimum_sen <> OLD.minimum_sen OR NEW.individual_relief_sen <> OLD.individual_relief_sen
   OR NEW.spouse_relief_sen <> OLD.spouse_relief_sen OR NEW.disabled_self_relief_sen <> OLD.disabled_self_relief_sen
   OR NEW.disabled_spouse_relief_sen <> OLD.disabled_spouse_relief_sen OR NEW.child_relief_sen <> OLD.child_relief_sen
   OR NEW.epf_relief_cap_sen <> OLD.epf_relief_cap_sen OR NEW.source_note <> OLD.source_note
   OR NEW.entered_by_user_id <> OLD.entered_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'An MTD schedule never changes after it is entered; enter a new one' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status AND NOT ((OLD.status = 'entered' AND NEW.status IN ('checked', 'rejected', 'withdrawn'))
   OR (OLD.status = 'checked' AND NEW.status = 'replaced')) THEN
  RAISE EXCEPTION 'An MTD schedule moves only from entered to checked, rejected or withdrawn, then from checked to replaced' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status <> 'entered' AND (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id OR NEW.decided_at IS DISTINCT FROM OLD.decided_at
   OR NEW.decision_reason IS DISTINCT FROM OLD.decision_reason) THEN
  RAISE EXCEPTION 'An MTD schedule decision never changes' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_mtd_schedules_guard BEFORE INSERT OR UPDATE ON hr_mtd_schedules FOR EACH ROW EXECUTE FUNCTION guard_hr_mtd_schedule();

-- Bands are written only with their schedule, while it waits to be checked.
CREATE OR REPLACE FUNCTION guard_hr_mtd_schedule_row() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_mtd_schedules WHERE id = NEW.schedule_id AND organisation_id = NEW.organisation_id AND status = 'entered') THEN
  RAISE EXCEPTION 'MTD bands are written only with their schedule, before it is checked' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_mtd_schedule_rows_guard BEFORE INSERT ON hr_mtd_schedule_rows FOR EACH ROW EXECUTE FUNCTION guard_hr_mtd_schedule_row();

-- A pay profile's MTD details never change after it is entered, as the rest of the profile (HR-7a).
CREATE OR REPLACE FUNCTION guard_hr_pay_profile_mtd() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.mtd_category IS DISTINCT FROM OLD.mtd_category OR NEW.mtd_children IS DISTINCT FROM OLD.mtd_children
   OR NEW.mtd_disabled_self <> OLD.mtd_disabled_self OR NEW.mtd_disabled_spouse <> OLD.mtd_disabled_spouse THEN
  RAISE EXCEPTION 'A pay profile never changes after it is entered; enter a new one' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_pay_profiles_mtd_guard BEFORE UPDATE ON hr_pay_profiles FOR EACH ROW EXECUTE FUNCTION guard_hr_pay_profile_mtd();
SQL);
    }

    public function down(): void
    {
        // HR payroll evidence is retained, following the evidence convention.
    }
};
