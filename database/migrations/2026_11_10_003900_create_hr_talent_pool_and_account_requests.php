<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-17e result, talent pool and staff-account handoff (HR-PLAN 19.8; owner, 2026-11-10: no offer and no onboarding
 * checklist in KPOne). The form gains an optional, separate talent-pool tick; the candidate can leave the pool on their
 * private page at any time, once and for good. A passed or failed candidate who ticked it stays in the pool for a
 * clinic-entered period counted from closing (KPOne ships no number), and HR can invite them to a new open position of
 * the same type, which starts a new application at phone screening. HR can ask the account administrator for a staff
 * account for a passed candidate; the request (name, email, position, branch) is listed on the Staff page for whoever
 * may create staff accounts, who creates the account the usual way and marks the request done. HR creates no account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_applications', function (Blueprint $t): void {
            $t->timestampTz('pool_withdrawn_at')->nullable();
            $t->unsignedBigInteger('invited_from_application_id')->nullable();
            $t->foreign(['invited_from_application_id', 'organisation_id'], 'hr_job_apps_invited_from_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE hr_job_applications ADD CONSTRAINT hr_job_apps_pool CHECK (pool_withdrawn_at IS NULL OR talent_pool_consent)');
        // One invitation per pool candidate and position.
        DB::statement('CREATE UNIQUE INDEX hr_job_apps_invited_once ON hr_job_applications (invited_from_application_id, position_id) WHERE invited_from_application_id IS NOT NULL');

        Schema::create('hr_talent_pool_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            // Days after closing a pool candidate is kept; null keeps nobody beyond the usual retention.
            $t->unsignedInteger('pool_days')->nullable();
            $t->date('effective_from'); // a first or shorter period waits the grace days, as retention does
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_pool_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_pool_settings_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'hr_pool_settings_once');
        });
        DB::statement('ALTER TABLE hr_talent_pool_settings ADD CONSTRAINT hr_pool_settings_shape CHECK (version >= 1 AND (pool_days IS NULL OR pool_days BETWEEN 1 AND 3650))');

        Schema::create('hr_staff_account_requests', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('application_id');
            $t->text('name_encrypted')->nullable(); // cleared with the application's values by the purge
            $t->text('email_encrypted')->nullable();
            $t->string('position_title', 80);
            $t->unsignedBigInteger('branch_id')->nullable(); // the position's branch; empty for "any branch"
            $t->string('status', 10); // open | done | cancelled
            $t->unsignedBigInteger('requested_by_user_id');
            $t->timestampTz('requested_at');
            $t->unsignedBigInteger('closed_by_user_id')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->timestampTz('purged_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['application_id', 'organisation_id'], 'hr_account_requests_app_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_account_requests_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['requested_by_user_id', 'organisation_id'], 'hr_account_requests_by_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['closed_by_user_id', 'organisation_id'], 'hr_account_requests_closer_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'status'], 'hr_account_requests_open');
        });
        DB::statement("ALTER TABLE hr_staff_account_requests ADD CONSTRAINT hr_account_requests_shape CHECK (
            status IN ('open', 'done', 'cancelled') AND lock_version >= 1
            AND ((status = 'open') = (closed_at IS NULL AND closed_by_user_id IS NULL))
            AND (purged_at IS NOT NULL OR (name_encrypted IS NOT NULL AND email_encrypted IS NOT NULL))
            AND (purged_at IS NULL OR (name_encrypted IS NULL AND email_encrypted IS NULL)))");
        // One open request per application (a cancelled one can be asked again).
        DB::statement("CREATE UNIQUE INDEX hr_account_requests_one_open ON hr_staff_account_requests (application_id) WHERE status = 'open'");

        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement("ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('submitted', 'status_changed', 'cv_opened', 'purged', 'screened', 'auto_rejected', 'candidate_withdrew',
                'slots_picked', 'picks_declined', 'interview_confirmed', 'interview_cancelled',
                'pool_left', 'invited_from_pool', 'invited_to_position', 'account_requested', 'account_done', 'account_cancelled'))");
        DB::statement('ALTER TABLE hr_job_application_messages DROP CONSTRAINT hr_job_app_msgs_shape');
        DB::statement("ALTER TABLE hr_job_application_messages ADD CONSTRAINT hr_job_app_msgs_shape CHECK (
            template IN ('received', 'screening_passed', 'not_taken_forward', 'auto_rejected', 'withdrawn', 'passed', 'failed', 'auto_reject_reminder',
                'interview_confirmed', 'pick_again', 'interview_cancelled', 'pool_invited')
            AND recipient IN ('candidate', 'mailbox') AND ((template = 'auto_reject_reminder') = (recipient = 'mailbox'))
            AND status IN ('sending', 'sent', 'failed', 'off')
            AND stage IN ('new', 'phone_screening', 'scheduling', 'interview', 'passed', 'failed', 'not_taken_forward', 'auto_rejected', 'withdrawn'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_pool_settings_append BEFORE UPDATE OR DELETE ON hr_talent_pool_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_pool_settings_truncate BEFORE TRUNCATE ON hr_talent_pool_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_account_requests_no_delete BEFORE DELETE ON hr_staff_account_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_account_requests_truncate BEFORE TRUNCATE ON hr_staff_account_requests FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A staff-account request is for a passed application whose values are still held.
CREATE OR REPLACE FUNCTION guard_hr_staff_account_request_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> 'open' OR NEW.purged_at IS NOT NULL OR NOT EXISTS (SELECT 1 FROM hr_job_applications a
     WHERE a.id = NEW.application_id AND a.organisation_id = NEW.organisation_id AND a.status = 'passed' AND a.purged_at IS NULL) THEN
  RAISE EXCEPTION 'A staff-account request is for a passed candidate' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_account_requests_insert_guard BEFORE INSERT ON hr_staff_account_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_staff_account_request_insert();

-- A request keeps what was asked; it is closed once (done or cancelled), and the purge only clears its values.
CREATE OR REPLACE FUNCTION guard_hr_staff_account_request() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.application_id <> OLD.application_id
   OR NEW.position_title <> OLD.position_title OR NEW.branch_id IS DISTINCT FROM OLD.branch_id OR NEW.requested_by_user_id <> OLD.requested_by_user_id
   OR NEW.requested_at <> OLD.requested_at OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A staff-account request keeps what was asked' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.purged_at IS NOT NULL AND (NEW.purged_at IS DISTINCT FROM OLD.purged_at OR NEW.status <> OLD.status) THEN
  RAISE EXCEPTION 'A purged request stays purged' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status AND (OLD.status <> 'open' OR NEW.purged_at IS DISTINCT FROM OLD.purged_at) THEN
  RAISE EXCEPTION 'A staff-account request is closed once' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status = OLD.status AND OLD.status <> 'open' AND (NEW.closed_at IS DISTINCT FROM OLD.closed_at OR NEW.closed_by_user_id IS DISTINCT FROM OLD.closed_by_user_id) THEN
  RAISE EXCEPTION 'A staff-account request is closed once' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.purged_at IS NULL AND (NEW.name_encrypted IS DISTINCT FROM OLD.name_encrypted OR NEW.email_encrypted IS DISTINCT FROM OLD.email_encrypted) THEN
  RAISE EXCEPTION 'A staff-account request keeps what was asked' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_account_requests_guard BEFORE UPDATE ON hr_staff_account_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_staff_account_request();

-- HR-17e: an application keeps where it was invited from, and leaving the pool is final.
CREATE OR REPLACE FUNCTION guard_hr_job_application() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.position_id <> OLD.position_id
   OR NEW.cv_bytes <> OLD.cv_bytes OR NEW.cv_sha256 <> OLD.cv_sha256 OR NEW.privacy_notice_version <> OLD.privacy_notice_version
   OR NEW.form_nonce <> OLD.form_nonce OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.talent_pool_consent <> OLD.talent_pool_consent OR NEW.invited_from_application_id IS DISTINCT FROM OLD.invited_from_application_id THEN
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
 -- HR-17e: leaving the talent pool is recorded once and never undone.
 IF OLD.pool_withdrawn_at IS NOT NULL AND NEW.pool_withdrawn_at IS DISTINCT FROM OLD.pool_withdrawn_at THEN
  RAISE EXCEPTION 'Leaving the talent pool is final' USING ERRCODE = 'P0001';
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

    public function down(): void {} // Requests, settings and pool choices are evidence; they are kept.
};
