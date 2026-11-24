<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Undo clock-out (owner, 16 Nov 2026: a 15-minute window, staff only; HR uses the correction). Someone who pressed Clock
 * out by mistake reopens the same session. Nothing is deleted or rewritten: the undone press stays as its event and
 * photo, an `out_undone` event records the undo, and the next clock-out is a new attempt with its own photo.
 *
 * - Photos and location results gain an `attempt` (1 for every row so far); one per session, kind and attempt.
 * - Clock events allow the kind `out_undone` (a button press with no photo of its own).
 * - The one reopen path: a closed session goes back to open only inside the undo's own transaction (a local flag naming
 *   the session), within 15 minutes of its clock-out, and only once its `out_undone` event for that clock-out exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['hr_clock_photos' => 'hr_clock_photos_once', 'hr_clock_location_checks' => 'hr_clock_location_checks_once'] as $table => $index) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unsignedSmallInteger('attempt')->default(1);
            });
            Schema::table($table, function (Blueprint $t) use ($index): void {
                $t->dropUnique($index);
            });
            Schema::table($table, function (Blueprint $t) use ($index): void {
                $t->unique(['clock_session_id', 'kind', 'attempt'], $index);
            });
        }
        DB::statement('ALTER TABLE hr_clock_events DROP CONSTRAINT hr_clock_events_shape');
        DB::statement('ALTER TABLE hr_clock_events ALTER COLUMN kind TYPE varchar(12)');
        DB::statement("ALTER TABLE hr_clock_events ADD CONSTRAINT hr_clock_events_shape CHECK (kind IN ('in', 'out', 'out_undone') AND method IN ('button', 'correction') AND (method = 'correction') = (correction_id IS NOT NULL) AND (kind <> 'out_undone' OR method = 'button'))");

        DB::unprepared(<<<'SQL'
-- An out photo or location result is the attempt after the undone clock-outs of its session; an in is always attempt 1.
CREATE OR REPLACE FUNCTION guard_hr_clock_attempt() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_expected integer := 1;
BEGIN
 IF NEW.kind = 'out' THEN
  v_expected := 1 + (SELECT count(*) FROM hr_clock_events e WHERE e.clock_session_id = NEW.clock_session_id AND e.kind = 'out_undone');
 END IF;
 IF NEW.attempt <> v_expected THEN
  RAISE EXCEPTION 'A clock photo or location result belongs to the current attempt' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_clock_photos_attempt BEFORE INSERT ON hr_clock_photos FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_attempt();
CREATE TRIGGER hr_clock_location_checks_attempt BEFORE INSERT ON hr_clock_location_checks FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_attempt();

-- A button press needs its own photo: for a clock-out, the photo of the current attempt. An undo has no photo.
CREATE OR REPLACE FUNCTION guard_hr_clock_event_photo() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.method = 'button' AND NEW.kind IN ('in', 'out') AND NOT EXISTS (SELECT 1 FROM hr_clock_photos p WHERE p.clock_session_id = NEW.clock_session_id
   AND p.kind = NEW.kind AND p.attempt = CASE WHEN NEW.kind = 'out'
     THEN 1 + (SELECT count(*) FROM hr_clock_events e WHERE e.clock_session_id = NEW.clock_session_id AND e.kind = 'out_undone') ELSE 1 END) THEN
  RAISE EXCEPTION 'A clock-in or clock-out needs a photo' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

-- The session guard as before, with the one reopen path for an undo.
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
   IF NOT (NEW.status = 'open' AND NEW.clock_out_at IS NULL AND NEW.clock_in_at = OLD.clock_in_at
     AND current_setting('kpone.hr_undo_clock_out', true) = OLD.id::text
     AND OLD.clock_out_at >= now() - interval '15 minutes'
     AND EXISTS (SELECT 1 FROM hr_clock_events e WHERE e.clock_session_id = OLD.id AND e.kind = 'out_undone' AND e.occurred_at = OLD.clock_out_at)) THEN
    RAISE EXCEPTION 'A closed clock session never reopens' USING ERRCODE = 'P0001';
   END IF;
  END IF;
  IF OLD.status = 'missing_out' AND NEW.status = 'open' THEN
   RAISE EXCEPTION 'A clock session missing its clock-out never reopens' USING ERRCODE = 'P0001';
  END IF;
 END IF;
 IF EXISTS (SELECT 1 FROM hr_clock_sessions s WHERE s.user_id = NEW.user_id AND s.id <> NEW.id
   AND s.clock_in_at < COALESCE(NEW.clock_out_at, CASE WHEN NEW.status = 'open' THEN GREATEST(now(), NEW.clock_in_at + interval '1 second') ELSE NEW.clock_in_at + interval '1 second' END)
   AND COALESCE(s.clock_out_at, CASE WHEN s.status = 'open' THEN GREATEST(now(), s.clock_in_at + interval '1 second') ELSE s.clock_in_at + interval '1 second' END) > NEW.clock_in_at) THEN
  RAISE EXCEPTION 'Overlapping clock sessions' USING ERRCODE = '23P01';
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        if (DB::table('hr_clock_events')->where('kind', 'out_undone')->exists()) {
            throw new RuntimeException('Undone clock-outs exist; this migration cannot be rolled back without losing attendance evidence.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS hr_clock_photos_attempt ON hr_clock_photos; DROP TRIGGER IF EXISTS hr_clock_location_checks_attempt ON hr_clock_location_checks; DROP FUNCTION IF EXISTS guard_hr_clock_attempt();');
        foreach (['hr_clock_photos' => 'hr_clock_photos_once', 'hr_clock_location_checks' => 'hr_clock_location_checks_once'] as $table => $index) {
            Schema::table($table, function (Blueprint $t) use ($index): void {
                $t->dropUnique($index);
            });
            Schema::table($table, function (Blueprint $t) use ($index): void {
                $t->dropColumn('attempt');
                $t->unique(['clock_session_id', 'kind'], $index);
            });
        }
        DB::statement('ALTER TABLE hr_clock_events DROP CONSTRAINT hr_clock_events_shape');
        DB::statement('ALTER TABLE hr_clock_events ALTER COLUMN kind TYPE varchar(4)');
        DB::statement("ALTER TABLE hr_clock_events ADD CONSTRAINT hr_clock_events_shape CHECK (kind IN ('in', 'out') AND method IN ('button', 'correction') AND (method = 'correction') = (correction_id IS NOT NULL))");
        // The two replaced guard functions stay: without any out_undone event they behave exactly as the originals.
    }
};
