<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-3a attendance (owner, 2026-11-06: "approve the HR-3 note with the defaults"). A Clock in and Clock out button on
 * the staff member's own account records server times only: no location, IP address, photo or device data. Settings
 * are clinic-entered versions (nothing is seeded). Corrections are decided by someone other than the subject and the
 * proposer; the original presses stay as append-only events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_attendance_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->date('effective_from');
            $t->unsignedSmallInteger('grace_minutes');
            $t->unsignedSmallInteger('forgotten_window_minutes');
            $t->unsignedSmallInteger('correction_days');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_attendance_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_attendance_settings_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'effective_from'], 'hr_attendance_settings_lookup');
        });

        Schema::create('hr_clock_sessions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('local_date'); // the branch-local date of the clock-in
            $t->string('timezone', 64);
            $t->timestampTz('clock_in_at');
            $t->timestampTz('clock_out_at')->nullable();
            $t->string('status', 12); // open | closed | missing_out
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['user_id', 'organisation_id'], 'hr_clock_sessions_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_clock_sessions_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_clock_sessions_scope_unique');
            $t->index(['user_id', 'clock_in_at'], 'hr_clock_sessions_user_time');
            $t->index(['branch_id', 'local_date'], 'hr_clock_sessions_branch_day');
        });

        Schema::create('hr_attendance_corrections', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id'); // the subject
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('clock_session_id')->nullable(); // null only for a forgotten day
            $t->unsignedBigInteger('created_session_id')->nullable(); // the session an approved forgotten day made
            $t->string('kind', 12); // change_in | set_out | add_day
            $t->timestampTz('proposed_in_at')->nullable();
            $t->timestampTz('proposed_out_at')->nullable();
            $t->string('reason_code', 16);
            $t->text('note_encrypted')->nullable();
            $t->string('status', 12)->default('pending');
            $t->unsignedBigInteger('proposed_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_attendance_corrections_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['clock_session_id', 'organisation_id'], 'hr_attendance_corrections_session_fk')->references(['id', 'organisation_id'])->on('hr_clock_sessions')->restrictOnDelete();
            $t->foreign(['created_session_id', 'organisation_id'], 'hr_attendance_corrections_created_fk')->references(['id', 'organisation_id'])->on('hr_clock_sessions')->restrictOnDelete();
            foreach (['user_id', 'proposed_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_attendance_corrections_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['organisation_id', 'status'], 'hr_attendance_corrections_status');
            $t->index(['user_id', 'status'], 'hr_attendance_corrections_user');
        });

        Schema::create('hr_clock_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('clock_session_id');
            $t->string('kind', 4); // in | out
            $t->timestampTz('occurred_at'); // the time this event says the person clocked
            $t->string('method', 12); // button | correction
            $t->unsignedBigInteger('correction_id')->nullable();
            $t->unsignedBigInteger('recorded_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['clock_session_id', 'organisation_id'], 'hr_clock_events_session_fk')->references(['id', 'organisation_id'])->on('hr_clock_sessions')->restrictOnDelete();
            $t->foreign('correction_id', 'hr_clock_events_correction_fk')->references('id')->on('hr_attendance_corrections')->restrictOnDelete();
            $t->foreign(['recorded_by_user_id', 'organisation_id'], 'hr_clock_events_recorder_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['clock_session_id', 'id'], 'hr_clock_events_session_order');
        });

        Schema::create('hr_attendance_retries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->uuid('idempotency_key');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('action', 24);
            $t->string('payload_hash', 64);
            $t->string('result', 64);
            $t->timestampTz('created_at');
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_attendance_retries_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'hr_attendance_retries_key_unique');
        });

        DB::statement('ALTER TABLE hr_attendance_settings ADD CONSTRAINT hr_attendance_settings_shape CHECK (grace_minutes <= 120 AND forgotten_window_minutes BETWEEN 15 AND 720 AND correction_days BETWEEN 1 AND 31)');
        DB::statement(<<<'SQL'
ALTER TABLE hr_clock_sessions ADD CONSTRAINT hr_clock_sessions_shape CHECK (
 status IN ('open', 'closed', 'missing_out')
 AND (status = 'closed') = (clock_out_at IS NOT NULL)
 AND (clock_out_at IS NULL OR clock_out_at > clock_in_at)
 AND (clock_out_at IS NULL OR clock_out_at - clock_in_at <= interval '24 hours')
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_clock_sessions_one_open ON hr_clock_sessions (user_id) WHERE status = 'open'");
        DB::statement(<<<'SQL'
ALTER TABLE hr_attendance_corrections ADD CONSTRAINT hr_attendance_corrections_shape CHECK (
 kind IN ('change_in', 'set_out', 'add_day')
 AND reason_code IN ('forgot', 'system_problem', 'wrong_time', 'away_from_branch', 'other')
 AND status IN ('pending', 'approved', 'rejected', 'cancelled')
 AND (kind <> 'change_in' OR (clock_session_id IS NOT NULL AND proposed_in_at IS NOT NULL AND proposed_out_at IS NULL))
 AND (kind <> 'set_out' OR (clock_session_id IS NOT NULL AND proposed_out_at IS NOT NULL AND proposed_in_at IS NULL))
 AND (kind <> 'add_day' OR (clock_session_id IS NULL AND proposed_in_at IS NOT NULL AND proposed_out_at IS NOT NULL AND proposed_out_at > proposed_in_at))
 AND (status IN ('pending', 'cancelled')) = (decided_by_user_id IS NULL)
 AND (decided_by_user_id IS NULL OR (decided_by_user_id <> user_id AND decided_by_user_id <> proposed_by_user_id))
 AND (status <> 'rejected' OR decision_reason IN ('not_confirmed', 'wrong_kind', 'other'))
 AND (created_session_id IS NULL OR (kind = 'add_day' AND status = 'approved'))
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_attendance_corrections_one_pending ON hr_attendance_corrections (clock_session_id) WHERE status = 'pending' AND clock_session_id IS NOT NULL");
        DB::statement("ALTER TABLE hr_clock_events ADD CONSTRAINT hr_clock_events_shape CHECK (kind IN ('in', 'out') AND method IN ('button', 'correction') AND (method = 'correction') = (correction_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_attendance_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'HR attendance evidence is append-only' USING ERRCODE = 'P0001';
END $$;
CREATE TRIGGER hr_attendance_settings_append BEFORE UPDATE OR DELETE ON hr_attendance_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_attendance_settings_truncate BEFORE TRUNCATE ON hr_attendance_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_events_append BEFORE UPDATE OR DELETE ON hr_clock_events FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_events_truncate BEFORE TRUNCATE ON hr_clock_events FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_attendance_retries_append BEFORE UPDATE OR DELETE ON hr_attendance_retries FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_attendance_retries_truncate BEFORE TRUNCATE ON hr_attendance_retries FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_sessions_truncate BEFORE TRUNCATE ON hr_clock_sessions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_attendance_corrections_truncate BEFORE TRUNCATE ON hr_attendance_corrections FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();

-- A session is clocked in at a branch the person is assigned to on its local date, and never overlaps another of theirs.
CREATE OR REPLACE FUNCTION guard_hr_clock_session_write() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'HR clock sessions are never deleted' USING ERRCODE = 'P0001'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NOT EXISTS (
    SELECT 1 FROM staff_profiles p JOIN staff_branch_assignments a ON a.staff_profile_id = p.id
    WHERE p.user_id = NEW.user_id AND a.branch_id = NEW.branch_id AND a.valid_from <= NEW.local_date
      AND (a.valid_until IS NULL OR a.valid_until >= NEW.local_date)) THEN
   RAISE EXCEPTION 'A clock session needs a branch assignment on its date' USING ERRCODE = '23514';
  END IF;
 ELSE
  IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.user_id <> OLD.user_id
    OR NEW.branch_id <> OLD.branch_id OR NEW.local_date <> OLD.local_date OR NEW.timezone <> OLD.timezone OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
   RAISE EXCEPTION 'HR clock session identity is immutable' USING ERRCODE = 'P0001';
  END IF;
  IF OLD.status = 'closed' AND NEW.status <> 'closed' THEN
   RAISE EXCEPTION 'A closed clock session never reopens' USING ERRCODE = 'P0001';
  END IF;
  IF OLD.status = 'missing_out' AND NEW.status = 'open' THEN
   RAISE EXCEPTION 'A clock session missing its clock-out never reopens' USING ERRCODE = 'P0001';
  END IF;
 END IF;
 IF EXISTS (SELECT 1 FROM hr_clock_sessions s WHERE s.user_id = NEW.user_id AND s.id <> NEW.id
   AND s.clock_in_at < COALESCE(NEW.clock_out_at, NEW.clock_in_at + interval '1 second')
   AND COALESCE(s.clock_out_at, s.clock_in_at + interval '1 second') > NEW.clock_in_at) THEN
  RAISE EXCEPTION 'Overlapping clock sessions' USING ERRCODE = '23P01';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_clock_sessions_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_clock_sessions FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_session_write();

-- An event belongs to a session of its own organisation.
CREATE OR REPLACE FUNCTION guard_hr_clock_event_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (SELECT organisation_id FROM hr_clock_sessions WHERE id = NEW.clock_session_id) IS DISTINCT FROM NEW.organisation_id THEN
  RAISE EXCEPTION 'A clock event must belong to a session of the same organisation' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_clock_events_insert BEFORE INSERT ON hr_clock_events FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_event_insert();

-- Corrections are never deleted; a decided or cancelled one never changes, and what was proposed is fixed.
CREATE OR REPLACE FUNCTION guard_hr_attendance_correction() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'HR attendance corrections are never deleted' USING ERRCODE = 'P0001'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'pending' THEN RAISE EXCEPTION 'A correction starts as pending' USING ERRCODE = '23514'; END IF;
  IF NEW.clock_session_id IS NOT NULL AND (SELECT user_id FROM hr_clock_sessions WHERE id = NEW.clock_session_id) IS DISTINCT FROM NEW.user_id THEN
   RAISE EXCEPTION 'A correction belongs to a session of the same person' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'A decided attendance correction never changes' USING ERRCODE = 'P0001'; END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.user_id <> OLD.user_id
   OR NEW.branch_id <> OLD.branch_id OR NEW.clock_session_id IS DISTINCT FROM OLD.clock_session_id OR NEW.kind <> OLD.kind
   OR NEW.proposed_in_at IS DISTINCT FROM OLD.proposed_in_at OR NEW.proposed_out_at IS DISTINCT FROM OLD.proposed_out_at
   OR NEW.reason_code <> OLD.reason_code OR NEW.note_encrypted IS DISTINCT FROM OLD.note_encrypted OR NEW.proposed_by_user_id <> OLD.proposed_by_user_id THEN
  RAISE EXCEPTION 'A correction is decided as proposed; cancel it and propose again' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_attendance_corrections_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_attendance_corrections FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_correction();
SQL);
    }

    public function down(): void
    {
        // Attendance evidence is retained, following the evidence convention.
    }
};
