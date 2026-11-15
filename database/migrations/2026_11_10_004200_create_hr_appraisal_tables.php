<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-10b appraisal (HR-PLAN 20.4; owner, 2026-11-10: "all continue with your recommendations"). HR enters the criteria
 * (versioned, at most 15; KPOne ships none) and opens a cycle with dates and chosen staff files. For each person a
 * manager rates every criterion 1 to 5 with a comment and sends it; the attendance, leave and overtime summary for the
 * cycle is frozen into it then. The staff member reads it and may add their own comment; a Director signs it off, and
 * a signed-off appraisal never changes. No ranking and no total score. Ratings and comments are encrypted. Nothing is
 * deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_appraisal_criteria_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            $t->jsonb('criteria');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_appraisal_criteria_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_appraisal_criteria_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'hr_appraisal_criteria_once');
            $t->unique(['id', 'organisation_id'], 'hr_appraisal_criteria_scope');
        });

        Schema::create('hr_appraisal_cycles', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('name', 80);
            $t->date('period_start');
            $t->date('period_end');
            $t->unsignedBigInteger('criteria_version_id');
            $t->string('status', 8); // open | closed
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by_user_id')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_appraisal_cycles_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['criteria_version_id', 'organisation_id'], 'hr_appraisal_cycles_criteria_fk')->references(['id', 'organisation_id'])->on('hr_appraisal_criteria_versions')->restrictOnDelete();
            foreach (['created_by_user_id', 'closed_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_appraisal_cycles_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_appraisal_cycles_scope');
        });

        Schema::create('hr_appraisals', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('cycle_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('user_id'); // the person appraised; staff with an account only
            $t->string('status', 10); // draft | sent | signed_off
            $t->text('ratings_encrypted')->nullable(); // [{criterion, rating, comment}], the manager's
            $t->unsignedBigInteger('appraiser_user_id')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->text('summary_encrypted')->nullable(); // attendance, leave and overtime frozen at send
            $t->text('staff_comment_encrypted')->nullable();
            $t->timestampTz('staff_commented_at')->nullable();
            $t->unsignedBigInteger('signed_off_by_user_id')->nullable();
            $t->timestampTz('signed_off_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['cycle_id', 'organisation_id'], 'hr_appraisals_cycle_fk')->references(['id', 'organisation_id'])->on('hr_appraisal_cycles')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_appraisals_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['user_id', 'appraiser_user_id', 'signed_off_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_appraisals_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['cycle_id', 'worker_id'], 'hr_appraisals_once');
            $t->index(['organisation_id', 'user_id'], 'hr_appraisals_person');
        });

        DB::statement("ALTER TABLE hr_appraisal_criteria_versions ADD CONSTRAINT hr_appraisal_criteria_shape CHECK (
            version >= 1 AND jsonb_typeof(criteria) = 'array' AND jsonb_array_length(criteria) BETWEEN 1 AND 15)");
        DB::statement("ALTER TABLE hr_appraisal_cycles ADD CONSTRAINT hr_appraisal_cycles_shape CHECK (
            char_length(btrim(name)) BETWEEN 3 AND 80 AND name !~ '[<>{}\\r\\n]' AND period_end >= period_start
            AND period_end <= period_start + 400 AND status IN ('open', 'closed') AND lock_version >= 1
            AND ((status = 'closed') = (closed_at IS NOT NULL) AND (closed_at IS NULL) = (closed_by_user_id IS NULL)))");
        DB::statement("ALTER TABLE hr_appraisals ADD CONSTRAINT hr_appraisals_shape CHECK (
            status IN ('draft', 'sent', 'signed_off') AND lock_version >= 1 AND appraiser_user_id IS DISTINCT FROM user_id
            AND signed_off_by_user_id IS DISTINCT FROM user_id
            AND ((status = 'draft') = (sent_at IS NULL))
            AND (status = 'draft' OR (ratings_encrypted IS NOT NULL AND appraiser_user_id IS NOT NULL AND summary_encrypted IS NOT NULL))
            AND ((status = 'signed_off') = (signed_off_at IS NOT NULL) AND (signed_off_at IS NULL) = (signed_off_by_user_id IS NULL))
            AND ((staff_comment_encrypted IS NULL) = (staff_commented_at IS NULL))
            AND (staff_commented_at IS NULL OR status <> 'draft'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_appraisal_criteria_append BEFORE UPDATE OR DELETE ON hr_appraisal_criteria_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_appraisal_criteria_truncate BEFORE TRUNCATE ON hr_appraisal_criteria_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_appraisal_cycles_no_delete BEFORE DELETE ON hr_appraisal_cycles FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_appraisal_cycles_truncate BEFORE TRUNCATE ON hr_appraisal_cycles FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_appraisals_no_delete BEFORE DELETE ON hr_appraisals FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_appraisals_truncate BEFORE TRUNCATE ON hr_appraisals FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A cycle keeps its name, dates and criteria; it closes once and never opens again.
CREATE OR REPLACE FUNCTION guard_hr_appraisal_cycle() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.name <> OLD.name
   OR NEW.period_start <> OLD.period_start OR NEW.period_end <> OLD.period_end OR NEW.criteria_version_id <> OLD.criteria_version_id
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR OLD.status = 'closed' OR NEW.lock_version <> OLD.lock_version + 1 THEN
  RAISE EXCEPTION 'An appraisal cycle keeps its name, dates and criteria' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_appraisal_cycles_guard BEFORE UPDATE ON hr_appraisal_cycles FOR EACH ROW EXECUTE FUNCTION guard_hr_appraisal_cycle();

-- An appraisal starts as a draft in an open cycle, for the cycle's own organisation and a file of that person.
CREATE OR REPLACE FUNCTION guard_hr_appraisal_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> 'draft' OR NEW.ratings_encrypted IS NOT NULL OR NEW.lock_version <> 1 THEN
  RAISE EXCEPTION 'An appraisal starts as an empty draft' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_appraisal_cycles c WHERE c.id = NEW.cycle_id AND c.organisation_id = NEW.organisation_id AND c.status = 'open')
   OR NOT EXISTS (SELECT 1 FROM hr_workers w WHERE w.id = NEW.worker_id AND w.organisation_id = NEW.organisation_id AND w.user_id = NEW.user_id) THEN
  RAISE EXCEPTION 'An appraisal belongs to an open cycle and the person''s own staff file' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_appraisals_insert_guard BEFORE INSERT ON hr_appraisals FOR EACH ROW EXECUTE FUNCTION guard_hr_appraisal_insert();

-- Draft (ratings change, cycle open) -> sent (ratings, appraiser and summary fixed) -> signed off (nothing changes).
-- The staff comment is added once, while sent.
CREATE OR REPLACE FUNCTION guard_hr_appraisal() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.cycle_id <> OLD.cycle_id
   OR NEW.worker_id <> OLD.worker_id OR NEW.user_id <> OLD.user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.lock_version <> OLD.lock_version + 1 OR OLD.status = 'signed_off' THEN
  RAISE EXCEPTION 'An appraisal keeps its history' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'draft' AND NOT EXISTS (SELECT 1 FROM hr_appraisal_cycles c WHERE c.id = OLD.cycle_id AND c.status = 'open') THEN
  RAISE EXCEPTION 'A closed cycle takes no more appraisal changes' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'draft' AND NEW.status NOT IN ('draft', 'sent') THEN
  RAISE EXCEPTION 'A draft appraisal is sent before it is signed off' USING ERRCODE = 'P0001';
 END IF;
 -- A Director's or HR Manager's appraisal is written only by a Director.
 IF OLD.status = 'draft' AND NEW.status = 'sent' AND EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = NEW.user_id AND mr.model_type = 'App\Models\User' AND r.name IN ('director', 'hr_manager'))
   AND NOT EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = NEW.appraiser_user_id AND mr.model_type = 'App\Models\User' AND r.name = 'director') THEN
  RAISE EXCEPTION 'A Director''s or HR Manager''s appraisal is written only by a Director' USING ERRCODE = '23514';
 END IF;
 -- Only a Director signs off, and only once the person has commented or 7 days have passed since it was sent.
 IF OLD.status = 'sent' AND NEW.status = 'signed_off' THEN
  IF NOT EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = NEW.signed_off_by_user_id AND mr.model_type = 'App\Models\User' AND r.name = 'director') THEN
   RAISE EXCEPTION 'Only a Director signs an appraisal off' USING ERRCODE = '23514';
  END IF;
  IF NEW.signed_off_at < OLD.sent_at OR (OLD.staff_commented_at IS NULL AND NEW.staff_commented_at IS NULL AND NEW.signed_off_at < OLD.sent_at + interval '7 days') THEN
   RAISE EXCEPTION 'The person has 7 days to comment before sign-off' USING ERRCODE = '23514';
  END IF;
 END IF;
 IF OLD.status = 'sent' AND (NEW.ratings_encrypted IS DISTINCT FROM OLD.ratings_encrypted OR NEW.appraiser_user_id IS DISTINCT FROM OLD.appraiser_user_id
   OR NEW.sent_at IS DISTINCT FROM OLD.sent_at OR NEW.summary_encrypted IS DISTINCT FROM OLD.summary_encrypted OR NEW.status NOT IN ('sent', 'signed_off')
   OR (OLD.staff_commented_at IS NOT NULL AND (NEW.staff_comment_encrypted IS DISTINCT FROM OLD.staff_comment_encrypted
     OR NEW.staff_commented_at IS DISTINCT FROM OLD.staff_commented_at))) THEN
  RAISE EXCEPTION 'A sent appraisal keeps its ratings; the staff comment is added once' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_appraisals_guard BEFORE UPDATE ON hr_appraisals FOR EACH ROW EXECUTE FUNCTION guard_hr_appraisal();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
