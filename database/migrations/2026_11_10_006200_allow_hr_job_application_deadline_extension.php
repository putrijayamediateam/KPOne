<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-17c follow-up (b) (owner, 2026-11-11, "go with your suggestions for both"): HR may give a waiting application 3
 * more working days before the daily check closes it. Each press is one append-only `deadline_extended` event; the
 * count restarts from it. The action list is copied from 003900 with that one action added. The HR reminder comes again
 * after each extension (Suresh M1): a message gains its extension number, and the once-only key includes it.
 */
return new class extends Migration
{
    /** Copied from 003600, with round, extension and the emailed deadline now fixed too (Suresh, follow-up b). */
    private const MESSAGE_GUARD = <<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_job_application_message() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.application_id <> OLD.application_id
   OR NEW.template <> OLD.template OR NEW.recipient <> OLD.recipient OR NEW.stage <> OLD.stage OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.round <> OLD.round OR NEW.extension <> OLD.extension OR NEW.choose_by IS DISTINCT FROM OLD.choose_by THEN
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
SQL;

    private const MESSAGE_GUARD_BEFORE = <<<'SQL'
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
SQL;

    private const ACTIONS = "'submitted', 'status_changed', 'cv_opened', 'purged', 'screened', 'auto_rejected', 'candidate_withdrew',
                'slots_picked', 'picks_declined', 'interview_confirmed', 'interview_cancelled',
                'pool_left', 'invited_from_pool', 'invited_to_position', 'account_requested', 'account_done', 'account_cancelled'";

    public function up(): void
    {
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement('ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('.self::ACTIONS.", 'deadline_extended'))");
        Schema::table('hr_job_application_messages', function (Blueprint $t): void {
            $t->unsignedSmallInteger('extension')->default(0);
        });
        DB::statement('ALTER TABLE hr_job_application_messages DROP CONSTRAINT hr_job_app_msgs_once');
        DB::statement('ALTER TABLE hr_job_application_messages ADD CONSTRAINT hr_job_app_msgs_once UNIQUE (application_id, template, stage, round, extension)');
        DB::unprepared(self::MESSAGE_GUARD);
    }

    public function down(): void
    {
        if (DB::table('hr_job_application_events')->where('action', 'deadline_extended')->exists()) {
            throw new RuntimeException('Deadline extensions are recorded; this migration cannot be rolled back without losing them.');
        }
        DB::unprepared(self::MESSAGE_GUARD_BEFORE);
        DB::statement('ALTER TABLE hr_job_application_messages DROP CONSTRAINT hr_job_app_msgs_once');
        DB::statement('ALTER TABLE hr_job_application_messages ADD CONSTRAINT hr_job_app_msgs_once UNIQUE (application_id, template, stage, round)');
        Schema::table('hr_job_application_messages', function (Blueprint $t): void {
            $t->dropColumn('extension');
        });
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement('ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('.self::ACTIONS.'))');
    }
};
