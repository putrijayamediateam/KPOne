<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-PHOTO (owner, 2026-11-12, relayed by B.E.R.N.I.S.S; option 2 "Camera app"): every button clock-in and clock-out
 * carries a live photo of the person. The photo is re-encoded (no EXIF, no camera or GPS data), encrypted on the private
 * disk under a generated name, and kept with its attendance record for 7 years, then deleted by `hr-attendance:purge`.
 * A capture token, taken when the camera opens, is good for one press of one kind within a few minutes, so a saved
 * photo cannot be sent later. A photo is never changed; PostgreSQL refuses an update, a truncate and any delete the
 * purge did not mark. A button press with no photo is refused; a correction never has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The one-time "About clock-in photos" notice: who saw which wording version, and when (owner, 2026-11-12). A
        // person sees it again when the version changes. Append-only; deleted by the 7-year attendance purge.
        Schema::create('hr_clock_photo_notices', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->string('version', 40);
            $t->timestampTz('seen_at');
            $t->unique(['user_id', 'version'], 'hr_clock_photo_notices_once');
            $t->foreign(['user_id', 'organisation_id'], 'hr_clock_photo_notices_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_clock_photo_notices ADD CONSTRAINT hr_clock_photo_notices_shape CHECK (version ~ '^[A-Za-z0-9._-]{1,40}$')");

        Schema::create('hr_clock_capture_tokens', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->string('kind', 3); // in | out
            $t->char('token_hash', 64);
            $t->timestampTz('issued_at');
            $t->timestampTz('expires_at');
            $t->timestampTz('used_at')->nullable();
            $t->unique(['id', 'organisation_id'], 'hr_clock_capture_tokens_scope_unique');
            $t->unique('token_hash', 'hr_clock_capture_tokens_hash_unique');
            $t->foreign(['user_id', 'organisation_id'], 'hr_clock_capture_tokens_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['user_id', 'issued_at'], 'hr_clock_capture_tokens_recent');
        });
        DB::statement("ALTER TABLE hr_clock_capture_tokens ADD CONSTRAINT hr_clock_capture_tokens_shape CHECK (
            kind IN ('in', 'out') AND expires_at > issued_at AND expires_at <= issued_at + interval '10 minutes'
            AND (used_at IS NULL OR used_at >= issued_at))");

        Schema::create('hr_clock_photos', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('clock_session_id');
            $t->string('kind', 3); // in | out
            $t->unsignedBigInteger('capture_token_id');
            $t->string('stored_path', 200);
            $t->unsignedInteger('bytes');
            $t->string('thumb_path', 200);
            $t->unsignedInteger('thumb_bytes');
            $t->unsignedSmallInteger('width');
            $t->unsignedSmallInteger('height');
            $t->char('sha256', 64);
            $t->timestampTz('created_at');
            $t->unique(['clock_session_id', 'kind'], 'hr_clock_photos_once');
            $t->unique('capture_token_id', 'hr_clock_photos_token_once');
            // The same picture never counts twice for one person (a saved photo sent again).
            $t->unique(['user_id', 'sha256'], 'hr_clock_photos_not_repeated');
            $t->foreign(['clock_session_id', 'organisation_id'], 'hr_clock_photos_session_fk')->references(['id', 'organisation_id'])->on('hr_clock_sessions')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_clock_photos_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['capture_token_id', 'organisation_id'], 'hr_clock_photos_token_fk')->references(['id', 'organisation_id'])->on('hr_clock_capture_tokens')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_clock_photos ADD CONSTRAINT hr_clock_photos_shape CHECK (
            kind IN ('in', 'out') AND bytes BETWEEN 1 AND 2097152 AND thumb_bytes BETWEEN 1 AND 2097152
            AND width BETWEEN 1 AND 4000 AND height BETWEEN 1 AND 4000 AND sha256 ~ '^[0-9a-f]{64}$')");

        DB::unprepared(<<<'SQL'
-- A photo belongs to its own person's session, and uses a token of that person and kind whose window covers the photo's
-- time. Single use is the unique index hr_clock_photos_token_once; the service also marks the token used.
CREATE OR REPLACE FUNCTION guard_hr_clock_photo() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_user bigint; v_token record;
BEGIN
 SELECT user_id INTO v_user FROM hr_clock_sessions WHERE id = NEW.clock_session_id;
 IF v_user IS DISTINCT FROM NEW.user_id THEN
  RAISE EXCEPTION 'A clock photo belongs to the person''s own session' USING ERRCODE = '23514';
 END IF;
 SELECT user_id, kind, issued_at, expires_at INTO v_token FROM hr_clock_capture_tokens WHERE id = NEW.capture_token_id;
 IF v_token.user_id IS DISTINCT FROM NEW.user_id OR v_token.kind IS DISTINCT FROM NEW.kind OR v_token.expires_at < NEW.created_at
   OR v_token.issued_at > NEW.created_at THEN
  RAISE EXCEPTION 'A clock photo needs a fresh capture of the same person and kind' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_clock_photos_guard BEFORE INSERT ON hr_clock_photos FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_photo();
CREATE TRIGGER hr_clock_photos_append BEFORE UPDATE ON hr_clock_photos FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_photos_retention BEFORE DELETE ON hr_clock_photos FOR EACH ROW
 EXECUTE FUNCTION guard_hr_attendance_retention_delete('created_at', 'HR clock photos are never deleted');
CREATE TRIGGER hr_clock_photos_no_truncate BEFORE TRUNCATE ON hr_clock_photos FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_photo_notices_append BEFORE UPDATE ON hr_clock_photo_notices FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_photo_notices_retention BEFORE DELETE ON hr_clock_photo_notices FOR EACH ROW
 EXECUTE FUNCTION guard_hr_attendance_retention_delete('seen_at', 'HR attendance evidence is append-only');
CREATE TRIGGER hr_clock_photo_notices_no_truncate BEFORE TRUNCATE ON hr_clock_photo_notices FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();

-- A token is only ever marked used, once; it is deleted only a day after it ran out.
CREATE OR REPLACE FUNCTION guard_hr_clock_capture_token() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN
  IF OLD.expires_at < now() - interval '1 day' THEN
   RETURN OLD;
  END IF;
  RAISE EXCEPTION 'A capture token is kept until a day after it runs out' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.used_at IS NOT NULL OR NEW.used_at IS NULL OR NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.user_id <> OLD.user_id
   OR NEW.kind <> OLD.kind OR NEW.token_hash <> OLD.token_hash OR NEW.issued_at <> OLD.issued_at OR NEW.expires_at <> OLD.expires_at THEN
  RAISE EXCEPTION 'A capture token is only marked used, once' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_clock_capture_tokens_guard BEFORE UPDATE OR DELETE ON hr_clock_capture_tokens FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_capture_token();

-- From now on a button press is written after its photo (a correction never has one). Earlier presses are untouched.
CREATE OR REPLACE FUNCTION guard_hr_clock_event_photo() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.method = 'button' AND NOT EXISTS (SELECT 1 FROM hr_clock_photos p WHERE p.clock_session_id = NEW.clock_session_id AND p.kind = NEW.kind) THEN
  RAISE EXCEPTION 'A clock-in or clock-out needs a photo' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_clock_events_photo BEFORE INSERT ON hr_clock_events FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_event_photo();
SQL);
    }

    public function down(): void
    {
        // Photos are attendance evidence kept 7 years; rolling back would drop them and leave their files behind (Suresh m5).
        if (Schema::hasTable('hr_clock_photos') && DB::table('hr_clock_photos')->exists()) {
            throw new RuntimeException('HR clock photos exist; this migration cannot be rolled back without losing attendance evidence.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS hr_clock_events_photo ON hr_clock_events;
DROP FUNCTION IF EXISTS guard_hr_clock_event_photo();');
        Schema::dropIfExists('hr_clock_photos');
        Schema::dropIfExists('hr_clock_capture_tokens');
        Schema::dropIfExists('hr_clock_photo_notices');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_hr_clock_photo();
DROP FUNCTION IF EXISTS guard_hr_clock_capture_token();');
    }
};
