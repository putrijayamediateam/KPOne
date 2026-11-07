<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-13 job applications (HR-PLAN 15.2; owner, 2026-11-07: "start the HR-13 build", positions kept in KPOne, retention
 * entered by HR with no number shipped). HR keeps a list of open positions; the public form (off by default) files an
 * application with a PDF CV; HR moves it through New, Shortlisted, Interview, then Offered, Not taken forward or
 * Withdrawn. Personal values and the CV are encrypted; nothing here creates an account. Events and settings are
 * append-only; an application is never deleted, only purged (values and CV removed) after the clinic's retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_job_positions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('title', 80);
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->boolean('is_open')->default(true);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_job_positions_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_job_positions_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_job_positions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_job_positions_scope_unique');
        });

        Schema::create('hr_job_applications', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('position_id');
            // new | shortlisted | interview | offered | not_taken_forward | withdrawn
            $t->string('status', 20)->default('new');
            $t->text('name_encrypted')->nullable();
            $t->text('phone_encrypted')->nullable();
            $t->text('email_encrypted')->nullable();
            $t->text('message_encrypted')->nullable();
            $t->string('cv_path', 200)->nullable();
            $t->unsignedInteger('cv_bytes');
            $t->char('cv_sha256', 64);
            $t->string('privacy_notice_version', 80);
            // The one-time nonce of the signed form token, so one form can file one application only.
            $t->char('form_nonce', 32)->unique();
            $t->timestampTz('submitted_at');
            $t->timestampTz('closed_at')->nullable();
            $t->timestampTz('purged_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['position_id', 'organisation_id'], 'hr_job_applications_position_fk')->references(['id', 'organisation_id'])->on('hr_job_positions')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_job_applications_scope_unique');
            $t->index(['organisation_id', 'status', 'submitted_at'], 'hr_job_applications_list');
        });

        Schema::create('hr_job_application_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('application_id');
            $t->string('action', 20); // submitted | status_changed | cv_opened | purged
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable(); // null: the applicant, or the daily purge
            $t->timestampTz('created_at');
            $t->foreign(['application_id', 'organisation_id'], 'hr_job_app_events_app_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_job_app_events_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index('application_id', 'hr_job_app_events_lookup');
        });

        Schema::create('hr_job_application_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            // Days after an application closes before its values and CV are deleted; null keeps everything.
            $t->unsignedInteger('retention_days')->nullable();
            // A first or shorter retention takes effect only from this date (7 days on), so a mistake can be undone.
            $t->date('effective_from');
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_job_app_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_job_app_settings_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'hr_job_app_settings_once');
        });

        DB::statement('ALTER TABLE hr_job_positions ADD CONSTRAINT hr_job_positions_shape CHECK (char_length(btrim(title)) BETWEEN 3 AND 80)');
        DB::statement("ALTER TABLE hr_job_applications ADD CONSTRAINT hr_job_applications_shape CHECK (
            status IN ('new', 'shortlisted', 'interview', 'offered', 'not_taken_forward', 'withdrawn')
            AND ((closed_at IS NULL) = (status IN ('new', 'shortlisted', 'interview')))
            AND cv_bytes > 0 AND cv_sha256 ~ '^[0-9a-f]{64}$' AND form_nonce ~ '^[0-9a-f]{32}$'
            AND (purged_at IS NULL OR (closed_at IS NOT NULL AND name_encrypted IS NULL AND phone_encrypted IS NULL AND email_encrypted IS NULL
                AND message_encrypted IS NULL AND cv_path IS NULL))
            AND (purged_at IS NOT NULL OR (name_encrypted IS NOT NULL AND phone_encrypted IS NOT NULL AND email_encrypted IS NOT NULL AND cv_path IS NOT NULL)))");
        DB::statement("ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (action IN ('submitted', 'status_changed', 'cv_opened', 'purged'))");
        DB::statement('ALTER TABLE hr_job_application_settings ADD CONSTRAINT hr_job_app_settings_shape CHECK (version >= 1 AND (retention_days IS NULL OR retention_days BETWEEN 1 AND 3650))');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_job_app_events_append BEFORE UPDATE OR DELETE ON hr_job_application_events FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_app_settings_append BEFORE UPDATE OR DELETE ON hr_job_application_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_applications_no_delete BEFORE DELETE ON hr_job_applications FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_positions_no_delete BEFORE DELETE ON hr_job_positions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_positions_truncate BEFORE TRUNCATE ON hr_job_positions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_applications_truncate BEFORE TRUNCATE ON hr_job_applications FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_app_events_truncate BEFORE TRUNCATE ON hr_job_application_events FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_app_settings_truncate BEFORE TRUNCATE ON hr_job_application_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A position keeps who made it.
CREATE OR REPLACE FUNCTION guard_hr_job_position() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A position keeps who made it' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_job_positions_guard BEFORE UPDATE ON hr_job_positions FOR EACH ROW EXECUTE FUNCTION guard_hr_job_position();

-- What the applicant sent never changes; the status moves only forward along the allowed steps; a closed application
-- stays closed; a purge only removes values and the CV, once.
CREATE OR REPLACE FUNCTION guard_hr_job_application() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.position_id <> OLD.position_id
   OR NEW.cv_bytes <> OLD.cv_bytes OR NEW.cv_sha256 <> OLD.cv_sha256 OR NEW.privacy_notice_version <> OLD.privacy_notice_version
   OR NEW.form_nonce <> OLD.form_nonce OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'An application keeps what was sent' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.purged_at IS NOT NULL AND (NEW.purged_at IS DISTINCT FROM OLD.purged_at OR NEW.status <> OLD.status) THEN
  RAISE EXCEPTION 'A purged application stays purged' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.purged_at IS NULL AND (NEW.name_encrypted IS DISTINCT FROM OLD.name_encrypted OR NEW.phone_encrypted IS DISTINCT FROM OLD.phone_encrypted
   OR NEW.email_encrypted IS DISTINCT FROM OLD.email_encrypted OR NEW.message_encrypted IS DISTINCT FROM OLD.message_encrypted
   OR NEW.cv_path IS DISTINCT FROM OLD.cv_path) THEN
  RAISE EXCEPTION 'An application keeps what was sent' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status THEN
  IF OLD.closed_at IS NOT NULL OR NOT (
       (OLD.status = 'new' AND NEW.status IN ('shortlisted', 'not_taken_forward', 'withdrawn'))
    OR (OLD.status = 'shortlisted' AND NEW.status IN ('interview', 'not_taken_forward', 'withdrawn'))
    OR (OLD.status = 'interview' AND NEW.status IN ('offered', 'not_taken_forward', 'withdrawn'))) THEN
   RAISE EXCEPTION 'An application moves only to its next allowed step' USING ERRCODE = 'P0001';
  END IF;
 ELSIF NEW.closed_at IS DISTINCT FROM OLD.closed_at THEN
  RAISE EXCEPTION 'A closed application stays closed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_job_applications_guard BEFORE UPDATE ON hr_job_applications FOR EACH ROW EXECUTE FUNCTION guard_hr_job_application();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS hr_job_application_settings;
DROP TABLE IF EXISTS hr_job_application_events;
DROP TABLE IF EXISTS hr_job_applications;
DROP TABLE IF EXISTS hr_job_positions;
DROP FUNCTION IF EXISTS guard_hr_job_application();
DROP FUNCTION IF EXISTS guard_hr_job_position();
SQL);
    }
};
