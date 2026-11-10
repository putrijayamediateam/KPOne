<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-17b (HR-PLAN 19.2, 19.3, 19.5 and 19.9; owner, 2026-11-10): each application gets a private link (only a keyed
 * hash of its token is kept, and the purge clears it) and the time it entered its current stage, so the daily check can
 * count three working days at New and at Phone screening. Every fixed message the clinic sends a candidate, and every
 * reminder to the hiring mailbox, is one row here, whether or not email is switched on: the candidate's page and the
 * HR view list them, the text is rebuilt from the fixed template and never stored, and a message or a reminder is
 * written at most once for each application and stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_applications', function (Blueprint $t): void {
            $t->char('status_token_hash', 64)->nullable()->unique('hr_job_apps_status_token');
            $t->timestampTz('stage_entered_at')->nullable();
        });
        // Every open application starts its clock now; a closed one keeps none.
        DB::statement('UPDATE hr_job_applications SET stage_entered_at = coalesce(closed_at, updated_at, submitted_at)');
        DB::statement('ALTER TABLE hr_job_applications ALTER COLUMN stage_entered_at SET NOT NULL');

        Schema::create('hr_job_application_messages', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('application_id');
            $t->string('template', 30);
            $t->string('recipient', 10); // candidate | mailbox
            $t->string('stage', 20); // the application's stage the message belongs to
            // off: email is switched off or no hiring mailbox is set, so the message is shown in KPOne only.
            $t->string('status', 10); // sending | sent | failed | off
            $t->string('error_code', 40)->nullable();
            $t->string('provider_message_id', 200)->nullable();
            $t->timestampsTz();
            $t->foreign(['application_id', 'organisation_id'], 'hr_job_app_msgs_app_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->unique(['application_id', 'template', 'stage'], 'hr_job_app_msgs_once');
            $t->index(['organisation_id', 'created_at'], 'hr_job_app_msgs_list');
        });

        DB::statement("ALTER TABLE hr_job_application_messages ADD CONSTRAINT hr_job_app_msgs_shape CHECK (
            template IN ('received', 'screening_passed', 'not_taken_forward', 'auto_rejected', 'withdrawn', 'passed', 'failed', 'auto_reject_reminder')
            AND recipient IN ('candidate', 'mailbox') AND ((template = 'auto_reject_reminder') = (recipient = 'mailbox'))
            AND status IN ('sending', 'sent', 'failed', 'off')
            AND stage IN ('new', 'phone_screening', 'scheduling', 'interview', 'passed', 'failed', 'not_taken_forward', 'auto_rejected', 'withdrawn'))");
        DB::statement('ALTER TABLE hr_job_applications ADD CONSTRAINT hr_job_apps_token_purged CHECK (purged_at IS NULL OR status_token_hash IS NULL)');
        DB::statement("ALTER TABLE hr_job_applications ADD CONSTRAINT hr_job_apps_token_shape CHECK (status_token_hash IS NULL OR status_token_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement("ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('submitted', 'status_changed', 'cv_opened', 'purged', 'screened', 'auto_rejected', 'candidate_withdrew'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_job_app_msgs_no_delete BEFORE DELETE ON hr_job_application_messages FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_app_msgs_truncate BEFORE TRUNCATE ON hr_job_application_messages FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A message keeps what it refers to; only one still sending gets its outcome, once.
CREATE OR REPLACE FUNCTION guard_hr_job_application_message() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.application_id <> OLD.application_id
   OR NEW.template <> OLD.template OR NEW.recipient <> OLD.recipient OR NEW.stage <> OLD.stage OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A hiring message keeps what it refers to' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status IS DISTINCT FROM OLD.status AND NOT (OLD.status = 'sending' AND NEW.status IN ('sent', 'failed')) THEN
  RAISE EXCEPTION 'Only a hiring message still sending gets its outcome' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status <> 'sending' AND (NEW.provider_message_id IS DISTINCT FROM OLD.provider_message_id OR NEW.error_code IS DISTINCT FROM OLD.error_code) THEN
  RAISE EXCEPTION 'A finished hiring message does not change' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_job_app_msgs_guard BEFORE UPDATE ON hr_job_application_messages FOR EACH ROW EXECUTE FUNCTION guard_hr_job_application_message();

-- The private link is set once at sending and only the purge clears it; the stage clock moves only with the stage.
CREATE OR REPLACE FUNCTION guard_hr_job_application_link() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status_token_hash IS DISTINCT FROM OLD.status_token_hash
    AND NOT (OLD.status_token_hash IS NULL AND NEW.status_token_hash IS NOT NULL AND OLD.purged_at IS NULL)
    AND NOT (NEW.status_token_hash IS NULL AND NEW.purged_at IS NOT NULL) THEN
  RAISE EXCEPTION 'A private link is cleared only by the purge' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.stage_entered_at IS DISTINCT FROM OLD.stage_entered_at AND NEW.status = OLD.status THEN
  RAISE EXCEPTION 'The stage clock moves only with the stage' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_job_applications_link_guard BEFORE UPDATE ON hr_job_applications FOR EACH ROW EXECUTE FUNCTION guard_hr_job_application_link();
SQL);
    }

    public function down(): void {} // Messages and their history are evidence; they are kept.
};
