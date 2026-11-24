<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-15d (owner, via B.E.R.N.I.S.S, 18 Nov 2026): a passed candidate for a Locum position becomes a locum file in one
 * step, and the sign-in request opens with the email they applied with. This row links the application to the file it
 * made, once each way. It is append-only; the staff admin still creates the account through StaffProvisioningService.
 */
return new class extends Migration
{
    private const ACTIONS = "'submitted', 'status_changed', 'cv_opened', 'purged', 'screened', 'auto_rejected', 'candidate_withdrew',
                'slots_picked', 'picks_declined', 'interview_confirmed', 'interview_cancelled',
                'pool_left', 'invited_from_pool', 'invited_to_position', 'account_requested', 'account_done', 'account_cancelled', 'deadline_extended'";

    public function up(): void
    {
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement('ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('.self::ACTIONS.", 'locum_file_made'))");

        Schema::create('hr_locum_hires', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('application_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('sign_in_request_id');
            $t->unsignedBigInteger('made_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['application_id', 'organisation_id'], 'hr_locum_hires_application_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_locum_hires_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign('sign_in_request_id', 'hr_locum_hires_sign_in_fk')->references('id')->on('hr_locum_sign_ins')->restrictOnDelete();
            $t->foreign(['made_by_user_id', 'organisation_id'], 'hr_locum_hires_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique('application_id', 'hr_locum_hires_application_once');
            $t->unique('worker_id', 'hr_locum_hires_worker_once');
        });

        DB::unprepared(<<<'SQL'
-- A hire links a passed Locum-position application to a locum file of the same organisation. Rows are never changed
-- or removed.
CREATE OR REPLACE FUNCTION guard_hr_locum_hire() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN
  RAISE EXCEPTION 'Locum hires are never changed or removed' USING ERRCODE = 'P0001';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_job_applications a JOIN hr_job_positions p ON p.id = a.position_id
   WHERE a.id = NEW.application_id AND a.organisation_id = NEW.organisation_id AND a.status = 'passed' AND p.position_type = 'locum') THEN
  RAISE EXCEPTION 'A locum hire needs a passed application for a Locum position' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_workers w WHERE w.id = NEW.worker_id AND w.organisation_id = NEW.organisation_id AND w.kind = 'locum') THEN
  RAISE EXCEPTION 'A locum hire links to a locum file' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_locum_sign_ins s WHERE s.id = NEW.sign_in_request_id AND s.worker_id = NEW.worker_id) THEN
  RAISE EXCEPTION 'A locum hire links to that file''s sign-in request' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_locum_hires_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_locum_hires FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_hire();
CREATE TRIGGER hr_locum_hires_no_truncate BEFORE TRUNCATE ON hr_locum_hires FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_locum_hire();
SQL);
    }

    public function down(): void
    {
        if (DB::table('hr_locum_hires')->exists()) {
            throw new RuntimeException('Locum hires are recorded; this migration cannot be rolled back without losing them.');
        }
        DB::unprepared('DROP TABLE IF EXISTS hr_locum_hires; DROP FUNCTION IF EXISTS guard_hr_locum_hire();');
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement('ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('.self::ACTIONS.'))');
    }
};
