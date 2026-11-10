<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-17a hiring stages (HR-PLAN 20.3; owner, 2026-11-10): New, Phone screening, Scheduling, Interview, then Passed or
 * Failed, with Not taken forward, Auto-rejected and Withdrawn from an open stage. HR-13's Shortlisted and Offered stay
 * readable for older rows (a Shortlisted row may still go on to Interview, an Offered one to Withdrawn) but no new
 * application moves to them. Leaving phone screening for scheduling needs a recorded passing screening, and the
 * daily purge also clears the form's other answers. The HR-13 migration is not edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hr_job_applications DROP CONSTRAINT hr_job_applications_shape');
        DB::statement("ALTER TABLE hr_job_applications ADD CONSTRAINT hr_job_applications_shape CHECK (
            status IN ('new', 'shortlisted', 'phone_screening', 'scheduling', 'interview', 'offered', 'passed', 'failed',
                'not_taken_forward', 'auto_rejected', 'withdrawn')
            AND ((closed_at IS NULL) = (status IN ('new', 'shortlisted', 'phone_screening', 'scheduling', 'interview')))
            AND cv_bytes > 0 AND cv_sha256 ~ '^[0-9a-f]{64}$' AND form_nonce ~ '^[0-9a-f]{32}$'
            AND (purged_at IS NULL OR (name_encrypted IS NULL AND phone_encrypted IS NULL AND email_encrypted IS NULL
                AND message_encrypted IS NULL AND details_encrypted IS NULL AND cv_path IS NULL))
            AND (purged_at IS NOT NULL OR (name_encrypted IS NOT NULL AND phone_encrypted IS NOT NULL AND email_encrypted IS NOT NULL AND cv_path IS NOT NULL)))");
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement("ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('submitted', 'status_changed', 'cv_opened', 'purged', 'screened'))");

        DB::unprepared(<<<'SQL'
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
    OR (OLD.status = 'interview' AND NEW.status IN ('passed', 'failed', 'withdrawn'))) THEN
   RAISE EXCEPTION 'An application moves only to its next allowed step' USING ERRCODE = 'P0001';
  END IF;
  IF OLD.status = 'phone_screening' AND NEW.status = 'scheduling'
     AND NOT EXISTS (SELECT 1 FROM hr_job_screenings s WHERE s.application_id = NEW.id AND s.outcome = 'pass') THEN
   RAISE EXCEPTION 'Scheduling needs a passed phone screening' USING ERRCODE = 'P0001';
  END IF;
 ELSIF NEW.closed_at IS DISTINCT FROM OLD.closed_at THEN
  RAISE EXCEPTION 'A closed application stays closed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void {} // Applications and their history are evidence; they are kept.
};
