<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-17c interview slots (HR-PLAN 19.6; owner, 2026-11-10: slots up to "10 working days" ahead, one confirmed
 * interview per slot for the "Whole group"). At Scheduling the candidate picks exactly three one-hour slots on their
 * private page; HR confirms one, which moves the application to Interview, or asks for new picks (a new round). HR can
 * cancel a confirmed interview with a fixed reason, which sends the application back to Scheduling for new picks.
 * PostgreSQL holds one confirmed interview per organisation and start time, and one per application. Picks and
 * interviews are kept; nothing is deleted. A message may now be sent again in a later round of the same stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_applications', function (Blueprint $t): void {
            // Which round of picks the candidate is on; it goes up when HR asks for new picks or cancels an interview.
            $t->unsignedSmallInteger('interview_round')->default(1);
        });

        Schema::create('hr_job_interview_picks', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('application_id');
            $t->unsignedSmallInteger('round');
            $t->jsonb('starts'); // three slot start times, UTC ISO 8601, in time order
            $t->unsignedBigInteger('branch_id'); // where the interview is held
            $t->timestampTz('created_at');
            $t->foreign(['application_id', 'organisation_id'], 'hr_job_picks_app_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_job_picks_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['application_id', 'round'], 'hr_job_picks_once_a_round');
        });

        Schema::create('hr_job_interviews', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('application_id');
            $t->unsignedBigInteger('pick_id');
            $t->unsignedSmallInteger('round');
            $t->unsignedBigInteger('branch_id');
            $t->timestampTz('starts_at');
            $t->string('status', 12); // confirmed | cancelled
            $t->unsignedBigInteger('confirmed_by_user_id');
            $t->timestampTz('confirmed_at');
            $t->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->string('cancel_reason', 30)->nullable();
            $t->foreign(['application_id', 'organisation_id'], 'hr_job_interviews_app_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->foreign('pick_id', 'hr_job_interviews_pick_fk')->references('id')->on('hr_job_interview_picks')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_job_interviews_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['confirmed_by_user_id', 'organisation_id'], 'hr_job_interviews_confirmer_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['cancelled_by_user_id', 'organisation_id'], 'hr_job_interviews_canceller_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'starts_at'], 'hr_job_interviews_day');
        });

        DB::statement("ALTER TABLE hr_job_interview_picks ADD CONSTRAINT hr_job_picks_shape CHECK (
            round >= 1 AND jsonb_typeof(starts) = 'array' AND jsonb_array_length(starts) = 3)");
        DB::statement("ALTER TABLE hr_job_interviews ADD CONSTRAINT hr_job_interviews_shape CHECK (
            status IN ('confirmed', 'cancelled')
            AND ((status = 'confirmed') = (cancelled_at IS NULL AND cancelled_by_user_id IS NULL AND cancel_reason IS NULL))
            AND (cancel_reason IS NULL OR cancel_reason IN ('candidate_asked', 'clinic_cannot', 'other', 'withdrawn')))");
        DB::statement('ALTER TABLE hr_job_applications ADD CONSTRAINT hr_job_apps_round CHECK (interview_round >= 1)');
        // The whole group: one confirmed interview per start time, and one per application.
        DB::statement("CREATE UNIQUE INDEX hr_job_interviews_one_per_slot ON hr_job_interviews (organisation_id, starts_at) WHERE status = 'confirmed'");
        DB::statement("CREATE UNIQUE INDEX hr_job_interviews_one_per_app ON hr_job_interviews (application_id) WHERE status = 'confirmed'");

        // A message can come again in a later round of the same stage (ask for new picks, a cancelled interview).
        Schema::table('hr_job_application_messages', function (Blueprint $t): void {
            $t->unsignedSmallInteger('round')->default(0);
        });
        DB::statement('ALTER TABLE hr_job_application_messages DROP CONSTRAINT hr_job_app_msgs_once');
        DB::statement('ALTER TABLE hr_job_application_messages ADD CONSTRAINT hr_job_app_msgs_once UNIQUE (application_id, template, stage, round)');
        DB::statement('ALTER TABLE hr_job_application_messages DROP CONSTRAINT hr_job_app_msgs_shape');
        DB::statement("ALTER TABLE hr_job_application_messages ADD CONSTRAINT hr_job_app_msgs_shape CHECK (
            template IN ('received', 'screening_passed', 'not_taken_forward', 'auto_rejected', 'withdrawn', 'passed', 'failed', 'auto_reject_reminder',
                'interview_confirmed', 'pick_again', 'interview_cancelled')
            AND recipient IN ('candidate', 'mailbox') AND ((template = 'auto_reject_reminder') = (recipient = 'mailbox'))
            AND status IN ('sending', 'sent', 'failed', 'off')
            AND stage IN ('new', 'phone_screening', 'scheduling', 'interview', 'passed', 'failed', 'not_taken_forward', 'auto_rejected', 'withdrawn'))");
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement("ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('submitted', 'status_changed', 'cv_opened', 'purged', 'screened', 'auto_rejected', 'candidate_withdrew',
                'slots_picked', 'picks_declined', 'interview_confirmed', 'interview_cancelled'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_job_picks_append BEFORE UPDATE OR DELETE ON hr_job_interview_picks FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_picks_truncate BEFORE TRUNCATE ON hr_job_interview_picks FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_interviews_no_delete BEFORE DELETE ON hr_job_interviews FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_job_interviews_truncate BEFORE TRUNCATE ON hr_job_interviews FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- An interview keeps what was confirmed; it is cancelled once and never comes back.
CREATE OR REPLACE FUNCTION guard_hr_job_interview() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.application_id <> OLD.application_id OR NEW.pick_id <> OLD.pick_id
   OR NEW.branch_id <> OLD.branch_id OR NEW.round <> OLD.round OR NEW.starts_at <> OLD.starts_at OR NEW.confirmed_by_user_id <> OLD.confirmed_by_user_id
   OR NEW.confirmed_at <> OLD.confirmed_at OR OLD.status <> 'confirmed' THEN
  RAISE EXCEPTION 'An interview keeps what was confirmed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_job_interviews_guard BEFORE UPDATE ON hr_job_interviews FOR EACH ROW EXECUTE FUNCTION guard_hr_job_interview();

-- A confirmed interview must be one of its round's three picks, for the application's current round.
CREATE OR REPLACE FUNCTION guard_hr_job_interview_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> 'confirmed' OR NOT EXISTS (
     SELECT 1 FROM hr_job_interview_picks p WHERE p.id = NEW.pick_id AND p.application_id = NEW.application_id AND p.branch_id = NEW.branch_id
       AND p.round = NEW.round
       AND EXISTS (SELECT 1 FROM hr_job_applications a WHERE a.id = NEW.application_id AND a.interview_round = NEW.round)
       AND p.starts @> to_jsonb(to_char(NEW.starts_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"'))) THEN
  RAISE EXCEPTION 'An interview is one of the candidate''s picks' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_job_interviews_insert_guard BEFORE INSERT ON hr_job_interviews FOR EACH ROW EXECUTE FUNCTION guard_hr_job_interview_insert();

-- HR-17c: a cancelled interview sends the application back from Interview to Scheduling.
CREATE OR REPLACE FUNCTION guard_hr_job_application() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.position_id <> OLD.position_id
   OR NEW.cv_bytes <> OLD.cv_bytes OR NEW.cv_sha256 <> OLD.cv_sha256 OR NEW.privacy_notice_version <> OLD.privacy_notice_version
   OR NEW.form_nonce <> OLD.form_nonce OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.talent_pool_consent <> OLD.talent_pool_consent THEN
  RAISE EXCEPTION 'An application keeps what was sent' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.purged_at IS NOT NULL AND (NEW.purged_at IS DISTINCT FROM OLD.purged_at OR NEW.status <> OLD.status) THEN
  RAISE EXCEPTION 'A purged application stays purged' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.purged_at IS NULL AND (NEW.name_encrypted IS DISTINCT FROM OLD.name_encrypted OR NEW.phone_encrypted IS DISTINCT FROM OLD.phone_encrypted
   OR NEW.email_encrypted IS DISTINCT FROM OLD.email_encrypted OR NEW.message_encrypted IS DISTINCT FROM OLD.message_encrypted
   OR NEW.details_encrypted IS DISTINCT FROM OLD.details_encrypted OR NEW.cv_path IS DISTINCT FROM OLD.cv_path) THEN
  RAISE EXCEPTION 'An application keeps what was sent' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.interview_round < OLD.interview_round THEN
  RAISE EXCEPTION 'The interview round only goes up' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status THEN
  -- A declined offer from HR-13 is recorded as Withdrawn (Suresh, HR-13 M5); every other closed stage is final.
  IF OLD.status = 'offered' AND NEW.status = 'withdrawn' AND OLD.purged_at IS NULL THEN
   RETURN NEW;
  END IF;
  IF OLD.closed_at IS NOT NULL OR NOT (
       (OLD.status = 'new' AND NEW.status IN ('phone_screening', 'not_taken_forward', 'auto_rejected', 'withdrawn'))
    OR (OLD.status = 'shortlisted' AND NEW.status IN ('interview', 'not_taken_forward', 'withdrawn'))
    OR (OLD.status = 'phone_screening' AND NEW.status IN ('scheduling', 'not_taken_forward', 'auto_rejected', 'withdrawn'))
    OR (OLD.status = 'scheduling' AND NEW.status IN ('interview', 'not_taken_forward', 'withdrawn'))
    OR (OLD.status = 'interview' AND NEW.status IN ('passed', 'failed', 'withdrawn', 'scheduling'))) THEN
   RAISE EXCEPTION 'An application moves only to its next allowed step' USING ERRCODE = 'P0001';
  END IF;
  IF OLD.status = 'phone_screening' AND NEW.status = 'scheduling'
     AND NOT EXISTS (SELECT 1 FROM hr_job_screenings s WHERE s.application_id = NEW.id AND s.outcome = 'pass') THEN
   RAISE EXCEPTION 'Scheduling needs a passed phone screening' USING ERRCODE = 'P0001';
  END IF;
  -- Back to Scheduling only when the confirmed interview was cancelled, and never while one stays confirmed.
  IF OLD.status = 'interview' AND NEW.status = 'scheduling'
     AND (EXISTS (SELECT 1 FROM hr_job_interviews i WHERE i.application_id = NEW.id AND i.status = 'confirmed')
       OR NOT EXISTS (SELECT 1 FROM hr_job_interviews i WHERE i.application_id = NEW.id AND i.status = 'cancelled')) THEN
   RAISE EXCEPTION 'Back to scheduling needs a cancelled interview' USING ERRCODE = 'P0001';
  END IF;
 ELSIF NEW.closed_at IS DISTINCT FROM OLD.closed_at THEN
  RAISE EXCEPTION 'A closed application stays closed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void {} // Picks and interviews are evidence; they are kept.
};
