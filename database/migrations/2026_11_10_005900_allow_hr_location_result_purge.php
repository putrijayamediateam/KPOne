<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retention (owner, 2026-11-11, relayed by B.E.R.N.I.S.S): a location-check result is kept 90 days, then purged by the
 * daily `hr-location-checks:purge`. Results stay append-only otherwise: an update is still refused, and a delete is
 * refused unless the purge has marked its own transaction and the row is older than 90 days. TRUNCATE stays refused.
 * Consent acknowledgements and the settings versions are kept (they are not results).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_location_result_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF current_setting('kpone.hr_location_purge', true) = 'on' AND OLD.created_at < now() - interval '90 days' THEN
  RETURN OLD;
 END IF;
 RAISE EXCEPTION 'HR attendance evidence is append-only' USING ERRCODE = 'P0001';
END $$;
DROP TRIGGER IF EXISTS hr_clock_location_checks_append ON hr_clock_location_checks;
CREATE TRIGGER hr_clock_location_checks_append BEFORE UPDATE ON hr_clock_location_checks FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_clock_location_checks_purge BEFORE DELETE ON hr_clock_location_checks FOR EACH ROW EXECUTE FUNCTION guard_hr_location_result_delete();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS hr_clock_location_checks_purge ON hr_clock_location_checks;
DROP TRIGGER IF EXISTS hr_clock_location_checks_append ON hr_clock_location_checks;
CREATE TRIGGER hr_clock_location_checks_append BEFORE UPDATE OR DELETE ON hr_clock_location_checks FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
DROP FUNCTION IF EXISTS guard_hr_location_result_delete();
SQL);
    }
};
