<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-16a location check at clock-in (HR-PLAN 22; B.E.R.N.I.S.S for the owner, 2026-11-10: location check only, at one
 * branch, off by default, coordinates never stored). A Director turns it on for one branch with a geofence (the
 * branch's own point and a radius) and a consent text. Staff acknowledge the text once before their first press there.
 * At the press the browser sends the person's position once; the server compares it with the geofence and keeps only
 * "inside", "outside" or "not available" and an accuracy band. No column here, or anywhere in HR, holds a person's
 * coordinates. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Versioned per branch; the latest row for a branch is in force. The centre is the branch's own location, not a person's.
        Schema::create('hr_location_check_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->boolean('enabled');
            $t->decimal('geofence_centre_lat', 9, 6)->nullable();
            $t->decimal('geofence_centre_lng', 9, 6)->nullable();
            $t->unsignedSmallInteger('radius_metres')->nullable();
            $t->text('consent_en')->nullable();
            $t->text('consent_bm')->nullable();
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->unique(['id', 'organisation_id'], 'hr_location_check_settings_scope_unique');
            $t->foreign(['branch_id', 'organisation_id'], 'hr_location_check_settings_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_location_check_settings_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['branch_id', 'id'], 'hr_location_check_settings_latest');
        });
        DB::statement('ALTER TABLE hr_location_check_settings ADD CONSTRAINT hr_location_check_settings_shape CHECK (
            (NOT enabled OR (geofence_centre_lat IS NOT NULL AND geofence_centre_lng IS NOT NULL AND radius_metres IS NOT NULL
              AND consent_en IS NOT NULL AND consent_bm IS NOT NULL))
            AND (geofence_centre_lat IS NULL OR geofence_centre_lat BETWEEN -90 AND 90)
            AND (geofence_centre_lng IS NULL OR geofence_centre_lng BETWEEN -180 AND 180)
            AND (radius_metres IS NULL OR radius_metres BETWEEN 25 AND 2000)
            AND (consent_en IS NULL OR length(btrim(consent_en)) BETWEEN 1 AND 2000)
            AND (consent_bm IS NULL OR length(btrim(consent_bm)) BETWEEN 1 AND 2000))');

        // A person's acknowledgement of one settings version's consent text, before their first press under it.
        Schema::create('hr_location_consents', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('setting_id');
            $t->timestampTz('acknowledged_at');
            $t->unique(['user_id', 'setting_id'], 'hr_location_consents_once');
            $t->foreign(['user_id', 'organisation_id'], 'hr_location_consents_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['setting_id', 'organisation_id'], 'hr_location_consents_setting_fk')->references(['id', 'organisation_id'])->on('hr_location_check_settings')->restrictOnDelete();
        });

        // One result per press: never coordinates, never a distance.
        Schema::create('hr_clock_location_checks', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('clock_session_id');
            $t->string('kind', 3); // in | out
            $t->unsignedBigInteger('setting_id');
            $t->string('result', 12); // inside | outside | unavailable
            $t->string('accuracy_band', 4)->nullable(); // good (within 50 m) | fair (within 200 m) | poor
            $t->string('unavailable_reason', 10)->nullable(); // declined | denied | failed | imprecise
            $t->timestampTz('created_at');
            $t->unique(['clock_session_id', 'kind'], 'hr_clock_location_checks_once');
            $t->foreign(['clock_session_id', 'organisation_id'], 'hr_clock_location_checks_session_fk')->references(['id', 'organisation_id'])->on('hr_clock_sessions')->restrictOnDelete();
            $t->foreign(['setting_id', 'organisation_id'], 'hr_clock_location_checks_setting_fk')->references(['id', 'organisation_id'])->on('hr_location_check_settings')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_clock_location_checks ADD CONSTRAINT hr_clock_location_checks_shape CHECK (
            kind IN ('in', 'out') AND result IN ('inside', 'outside', 'unavailable')
            AND ((result = 'unavailable') = (unavailable_reason IS NOT NULL))
            AND ((result = 'unavailable') = (accuracy_band IS NULL))
            AND (accuracy_band IS NULL OR accuracy_band IN ('good', 'fair', 'poor'))
            AND (unavailable_reason IS NULL OR unavailable_reason IN ('declined', 'denied', 'failed', 'imprecise')))");

        DB::unprepared(<<<'SQL'
-- A settings version is written by someone who manages the location check; while it is on, it is on at one branch only
-- (v1: start at one branch, HR-PLAN 22.1).
CREATE OR REPLACE FUNCTION guard_hr_location_check_setting() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT hr_user_holds(NEW.created_by_user_id, 'hr.attendance.location.manage.organisation') THEN
  RAISE EXCEPTION 'Only someone who manages the location check changes it' USING ERRCODE = '23514';
 END IF;
 IF NEW.enabled AND EXISTS (
   SELECT 1 FROM hr_location_check_settings s
   WHERE s.organisation_id = NEW.organisation_id AND s.branch_id <> NEW.branch_id AND s.enabled
     AND s.id = (SELECT max(l.id) FROM hr_location_check_settings l WHERE l.branch_id = s.branch_id)) THEN
  RAISE EXCEPTION 'The location check is on at one branch at a time' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_location_check_settings_guard BEFORE INSERT ON hr_location_check_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_location_check_setting();

-- Consent is given to a version that is on, by the person themselves (the service writes it at their own press).
CREATE OR REPLACE FUNCTION guard_hr_location_consent() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_location_check_settings s WHERE s.id = NEW.setting_id AND s.enabled) THEN
  RAISE EXCEPTION 'Consent is given to a location check that is on' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_location_consents_guard BEFORE INSERT ON hr_location_consents FOR EACH ROW EXECUTE FUNCTION guard_hr_location_consent();

-- A result belongs to a version that is on at the session's own branch, and a checked result needs that person's consent.
CREATE OR REPLACE FUNCTION guard_hr_clock_location_check() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_branch bigint; v_user bigint;
BEGIN
 SELECT branch_id, user_id INTO v_branch, v_user FROM hr_clock_sessions WHERE id = NEW.clock_session_id;
 IF NOT EXISTS (SELECT 1 FROM hr_location_check_settings s WHERE s.id = NEW.setting_id AND s.enabled AND s.branch_id = v_branch) THEN
  RAISE EXCEPTION 'A location result belongs to the check that is on at the session''s branch' USING ERRCODE = '23514';
 END IF;
 IF NEW.result <> 'unavailable' AND NOT EXISTS (SELECT 1 FROM hr_location_consents c WHERE c.user_id = v_user AND c.setting_id = NEW.setting_id) THEN
  RAISE EXCEPTION 'A location is checked only after the person acknowledged the consent text' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_clock_location_checks_guard BEFORE INSERT ON hr_clock_location_checks FOR EACH ROW EXECUTE FUNCTION guard_hr_clock_location_check();

CREATE TRIGGER hr_location_check_settings_append BEFORE UPDATE OR DELETE ON hr_location_check_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_location_check_settings_truncate BEFORE TRUNCATE ON hr_location_check_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_location_consents_append BEFORE UPDATE OR DELETE ON hr_location_consents FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_location_consents_truncate BEFORE TRUNCATE ON hr_location_consents FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_location_checks_append BEFORE UPDATE OR DELETE ON hr_clock_location_checks FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_location_checks_truncate BEFORE TRUNCATE ON hr_clock_location_checks FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
SQL);
    }

    public function down(): void {} // HR record evidence is retained, following the evidence convention.
};
