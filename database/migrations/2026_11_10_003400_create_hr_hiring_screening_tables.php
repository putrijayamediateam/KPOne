<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-17a hiring (HR-PLAN 20; owner, 2026-11-10, relayed by B.E.R.N.I.S.S): a position has a type (CA, intern, locum,
 * Resident Doctor) that decides the form's extra questions; an application keeps those answers encrypted with its other
 * values, and whether the candidate agreed to be kept in the talent pool. HR keeps a phone-screening call checklist
 * (KPOne ships none; versions are append-only) and records one screening per application: the ticks, a 1 to 5 rating,
 * an optional encrypted note and pass or reject. No wage, salary, IC number, date of birth or photo is asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_positions', function (Blueprint $t): void {
            $t->string('position_type', 20)->default('ca'); // ca | intern | locum | resident_doctor
        });
        Schema::table('hr_job_applications', function (Blueprint $t): void {
            // The form's other answers (start date, education, languages, the type's own questions), encrypted JSON.
            $t->text('details_encrypted')->nullable();
            $t->boolean('talent_pool_consent')->default(false);
        });

        Schema::create('hr_job_screening_checklists', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            $t->jsonb('items'); // list of short questions HR asks on the call
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_job_screen_lists_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_job_screen_lists_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'hr_job_screen_lists_once');
        });

        Schema::create('hr_job_screenings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('application_id');
            $t->unsignedInteger('checklist_version')->nullable(); // null: no checklist was saved yet
            $t->jsonb('ticked'); // indexes of the checklist items HR ticked
            $t->unsignedSmallInteger('rating');
            $t->text('note_encrypted')->nullable();
            $t->string('outcome', 10); // pass | reject
            $t->unsignedBigInteger('actor_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['application_id', 'organisation_id'], 'hr_job_screenings_app_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_job_screenings_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique('application_id', 'hr_job_screenings_once');
        });

        DB::statement("ALTER TABLE hr_job_positions ADD CONSTRAINT hr_job_positions_type CHECK (position_type IN ('ca', 'intern', 'locum', 'resident_doctor'))");
        DB::statement("ALTER TABLE hr_job_screening_checklists ADD CONSTRAINT hr_job_screen_lists_shape CHECK (
            version >= 1 AND jsonb_typeof(items) = 'array' AND jsonb_array_length(items) BETWEEN 1 AND 15)");
        DB::statement("ALTER TABLE hr_job_screenings ADD CONSTRAINT hr_job_screenings_shape CHECK (
            rating BETWEEN 1 AND 5 AND outcome IN ('pass', 'reject') AND jsonb_typeof(ticked) = 'array')");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_job_screen_lists_append BEFORE UPDATE OR DELETE ON hr_job_screening_checklists FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_screen_lists_truncate BEFORE TRUNCATE ON hr_job_screening_checklists FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_screenings_no_delete BEFORE DELETE ON hr_job_screenings FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_screenings_truncate BEFORE TRUNCATE ON hr_job_screenings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A screening keeps what HR recorded; only the purge may clear its note, once.
CREATE OR REPLACE FUNCTION guard_hr_job_screening() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.application_id <> OLD.application_id
   OR NEW.checklist_version IS DISTINCT FROM OLD.checklist_version OR NEW.ticked <> OLD.ticked OR NEW.rating <> OLD.rating
   OR NEW.outcome <> OLD.outcome OR NEW.actor_user_id <> OLD.actor_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR (NEW.note_encrypted IS NOT NULL AND NEW.note_encrypted IS DISTINCT FROM OLD.note_encrypted) THEN
  RAISE EXCEPTION 'A phone screening keeps what was recorded' USING ERRCODE = 'P0001';
 END IF;
 -- The note is cleared only once its application has been purged (Suresh, HR-17a).
 IF NEW.note_encrypted IS NULL AND OLD.note_encrypted IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM hr_job_applications a WHERE a.id = NEW.application_id AND a.purged_at IS NOT NULL) THEN
  RAISE EXCEPTION 'A phone screening note is cleared only by the purge' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_job_screenings_guard BEFORE UPDATE ON hr_job_screenings FOR EACH ROW EXECUTE FUNCTION guard_hr_job_screening();
SQL);
    }

    public function down(): void {} // Screenings and checklists are evidence; they are kept.
};
