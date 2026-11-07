<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-5b roster compliance rules (HR-PLAN 12.3 rule 7). KPOne ships no number: each branch's opening hours and the
 * minimum of resident doctors on duty, and the organisation's work-hour limits, are clinic-entered and kept as
 * append-only versions; the newest version is in force. The planner reads them to warn, and never refuses anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_coverage_rules', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedInteger('version');
            // ISO weekday (1 Monday … 7 Sunday) => {"open": "HH:MM", "close": "HH:MM"}; a missing day is closed.
            $t->jsonb('opening_hours');
            $t->unsignedSmallInteger('min_resident_doctors');
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_coverage_rules_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('branch_id', 'hr_coverage_rules_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_coverage_rules_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['branch_id', 'version'], 'hr_coverage_rules_once');
        });

        Schema::create('hr_work_hour_rules', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            // Each limit may be left empty, which turns that warning off.
            $t->unsignedSmallInteger('max_days_in_row')->nullable();
            $t->unsignedSmallInteger('min_rest_minutes')->nullable();
            $t->unsignedSmallInteger('max_week_minutes')->nullable();
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_work_hour_rules_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_work_hour_rules_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'hr_work_hour_rules_once');
        });

        DB::statement("ALTER TABLE hr_coverage_rules ADD CONSTRAINT hr_coverage_rules_shape CHECK (version >= 1 AND jsonb_typeof(opening_hours) = 'object' AND min_resident_doctors BETWEEN 0 AND 20)");
        DB::statement('ALTER TABLE hr_work_hour_rules ADD CONSTRAINT hr_work_hour_rules_shape CHECK (version >= 1 AND (max_days_in_row IS NULL OR max_days_in_row BETWEEN 1 AND 13)
            AND (min_rest_minutes IS NULL OR min_rest_minutes BETWEEN 60 AND 1440) AND (max_week_minutes IS NULL OR max_week_minutes BETWEEN 60 AND 6000))');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_coverage_rules_append BEFORE UPDATE OR DELETE ON hr_coverage_rules FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_work_hour_rules_append BEFORE UPDATE OR DELETE ON hr_work_hour_rules FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_coverage_rules_truncate BEFORE TRUNCATE ON hr_coverage_rules FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_work_hour_rules_truncate BEFORE TRUNCATE ON hr_work_hour_rules FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A branch's rule stays inside the branch's organisation, and versions are numbered without gaps.
CREATE OR REPLACE FUNCTION guard_hr_coverage_rule() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE top integer;
BEGIN
 IF (SELECT organisation_id FROM branches WHERE id = NEW.branch_id) IS DISTINCT FROM NEW.organisation_id THEN
  RAISE EXCEPTION 'A coverage rule stays inside its branch''s organisation' USING ERRCODE = '23514';
 END IF;
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_coverage_rules WHERE branch_id = NEW.branch_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Coverage rule versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_coverage_rules_guard BEFORE INSERT ON hr_coverage_rules FOR EACH ROW EXECUTE FUNCTION guard_hr_coverage_rule();

CREATE OR REPLACE FUNCTION guard_hr_work_hour_rule() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE top integer;
BEGIN
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_work_hour_rules WHERE organisation_id = NEW.organisation_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Work-hour rule versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_work_hour_rules_guard BEFORE INSERT ON hr_work_hour_rules FOR EACH ROW EXECUTE FUNCTION guard_hr_work_hour_rule();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
