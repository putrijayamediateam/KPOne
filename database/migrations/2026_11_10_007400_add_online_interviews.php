<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-15e (owner, via B.E.R.N.I.S.S, 18 Nov 2026): an interview is in person or online. For online, HR types the video
 * link when confirming; it shows on the candidate's private page and in the confirmation email. KPOne makes no video or
 * calendar integration. Like the rest of a confirmed interview, the way it happens is fixed once confirmed; to change
 * it, HR cancels and the candidate picks new times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_interviews', function (Blueprint $t): void {
            $t->string('mode', 16)->default('in_person');
            $t->text('video_link')->nullable();
        });
        DB::statement("ALTER TABLE hr_job_interviews ADD CONSTRAINT hr_job_interviews_mode_shape CHECK (
            mode IN ('in_person', 'online')
            AND ((mode = 'online') = (video_link IS NOT NULL))
            AND (video_link IS NULL OR (char_length(video_link) <= 500 AND video_link ~ '^https://[^[:space:]<>{}\"]+\$')))");

        DB::unprepared(<<<'SQL'
-- An interview keeps what was confirmed, now including how it happens; it is cancelled once and never comes back.
CREATE OR REPLACE FUNCTION guard_hr_job_interview() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.application_id <> OLD.application_id OR NEW.pick_id <> OLD.pick_id
   OR NEW.branch_id <> OLD.branch_id OR NEW.round <> OLD.round OR NEW.starts_at <> OLD.starts_at OR NEW.confirmed_by_user_id <> OLD.confirmed_by_user_id
   OR NEW.confirmed_at <> OLD.confirmed_at OR NEW.mode IS DISTINCT FROM OLD.mode OR NEW.video_link IS DISTINCT FROM OLD.video_link
   OR OLD.status <> 'confirmed' THEN
  RAISE EXCEPTION 'An interview keeps what was confirmed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_job_interview() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.application_id <> OLD.application_id OR NEW.pick_id <> OLD.pick_id
   OR NEW.branch_id <> OLD.branch_id OR NEW.round <> OLD.round OR NEW.starts_at <> OLD.starts_at OR NEW.confirmed_by_user_id <> OLD.confirmed_by_user_id
   OR NEW.confirmed_at <> OLD.confirmed_at OR OLD.status <> 'confirmed' THEN
  RAISE EXCEPTION 'An interview keeps what was confirmed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
SQL);
        DB::statement('ALTER TABLE hr_job_interviews DROP CONSTRAINT hr_job_interviews_mode_shape');
        Schema::table('hr_job_interviews', function (Blueprint $t): void {
            $t->dropColumn(['mode', 'video_link']);
        });
    }
};
