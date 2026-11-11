<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-17c (owner, 2026-11-11, relayed by B.E.R.N.I.S.S): an interview invitation the candidate leaves unanswered is
 * auto-rejected after 3 working days, as New and Phone screening already are (HR-17b). The application guard gains one
 * step, Scheduling to Auto-rejected, and only while the candidate has no picks in the current round. Everything else in
 * the guard is as HR-17e left it. The HR reminder at Scheduling gets its own template, so the messages shape gains it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The HR reminder at Scheduling has its own wording (Suresh, M1); like the other reminder it goes to the mailbox.
        DB::statement('ALTER TABLE hr_job_application_messages DROP CONSTRAINT hr_job_app_msgs_shape');
        DB::statement("ALTER TABLE hr_job_application_messages ADD CONSTRAINT hr_job_app_msgs_shape CHECK (
            template IN ('received', 'screening_passed', 'not_taken_forward', 'auto_rejected', 'withdrawn', 'passed', 'failed', 'auto_reject_reminder',
                'interview_confirmed', 'pick_again', 'interview_cancelled', 'pool_invited', 'pick_reminder')
            AND recipient IN ('candidate', 'mailbox') AND ((template IN ('auto_reject_reminder', 'pick_reminder')) = (recipient = 'mailbox'))
            AND status IN ('sending', 'sent', 'failed', 'off')
            AND stage IN ('new', 'phone_screening', 'scheduling', 'interview', 'passed', 'failed', 'not_taken_forward', 'auto_rejected', 'withdrawn'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_job_application() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.position_id <> OLD.position_id
   OR NEW.cv_bytes <> OLD.cv_bytes OR NEW.cv_sha256 <> OLD.cv_sha256 OR NEW.privacy_notice_version <> OLD.privacy_notice_version
   OR NEW.form_nonce <> OLD.form_nonce OR NEW.submitted_at IS DISTINCT FROM OLD.submitted_at OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.talent_pool_consent <> OLD.talent_pool_consent OR NEW.invited_from_application_id IS DISTINCT FROM OLD.invited_from_application_id
   OR NEW.talent_pool_text_version IS DISTINCT FROM OLD.talent_pool_text_version THEN
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
    OR (OLD.status = 'scheduling' AND NEW.status IN ('interview', 'not_taken_forward', 'auto_rejected', 'withdrawn'))
    OR (OLD.status = 'interview' AND NEW.status IN ('passed', 'failed', 'withdrawn', 'scheduling'))) THEN
   RAISE EXCEPTION 'An application moves only to its next allowed step' USING ERRCODE = 'P0001';
  END IF;
  IF OLD.status = 'phone_screening' AND NEW.status = 'scheduling'
     AND NOT EXISTS (SELECT 1 FROM hr_job_screenings s WHERE s.application_id = NEW.id AND s.outcome = 'pass') THEN
   RAISE EXCEPTION 'Scheduling needs a passed phone screening' USING ERRCODE = 'P0001';
  END IF;
  -- HR-17c: Scheduling is auto-rejected only while the candidate has not picked times in the current round (owner,
  -- 2026-11-11); once they have, it waits for HR.
  IF OLD.status = 'scheduling' AND NEW.status = 'auto_rejected'
     AND EXISTS (SELECT 1 FROM hr_job_interview_picks p WHERE p.application_id = NEW.id AND p.round = OLD.interview_round) THEN
   RAISE EXCEPTION 'An application with interview picks waits for HR' USING ERRCODE = 'P0001';
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

    public function down(): void {} // A later migration redefines the guard; applications closed as Auto-rejected stay closed.
};
