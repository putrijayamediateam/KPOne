<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-17c follow-up (b) (owner, 2026-11-11, "go with your suggestions for both"): HR may give a waiting application 3
 * more working days before the daily check closes it. Each press is one append-only `deadline_extended` event; the
 * count restarts from it. The action list is copied from 003900 with that one action added.
 */
return new class extends Migration
{
    private const ACTIONS = "'submitted', 'status_changed', 'cv_opened', 'purged', 'screened', 'auto_rejected', 'candidate_withdrew',
                'slots_picked', 'picks_declined', 'interview_confirmed', 'interview_cancelled',
                'pool_left', 'invited_from_pool', 'invited_to_position', 'account_requested', 'account_done', 'account_cancelled'";

    public function up(): void
    {
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement('ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('.self::ACTIONS.", 'deadline_extended'))");
    }

    public function down(): void
    {
        if (DB::table('hr_job_application_events')->where('action', 'deadline_extended')->exists()) {
            throw new RuntimeException('Deadline extensions are recorded; this migration cannot be rolled back without losing them.');
        }
        DB::statement('ALTER TABLE hr_job_application_events DROP CONSTRAINT hr_job_app_events_shape');
        DB::statement('ALTER TABLE hr_job_application_events ADD CONSTRAINT hr_job_app_events_shape CHECK (
            action IN ('.self::ACTIONS.'))');
    }
};
