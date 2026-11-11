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
    }

    public function down(): void
    {
        if (DB::table('hr_job_application_events')->where('action', 'deadline_extended')->exists()) {
            throw new RuntimeException('Deadline extensions are recorded; this migration cannot be rolled back without losing them.');
        }
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
