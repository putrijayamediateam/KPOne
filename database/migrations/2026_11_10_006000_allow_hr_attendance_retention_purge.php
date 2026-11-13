<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retention (owner, 2026-11-11, relayed by B.E.R.N.I.S.S; the accountant confirms the period before real use): attendance
 * records are kept 7 years, then deleted by the daily `hr-attendance:purge`. Clock sessions, their events, corrections,
 * overtime requests and location-check consents stay append-only otherwise: each table's guard keeps refusing updates as
 * before, and a delete is refused (with the same message) unless the purge has marked its own transaction and the row's
 * own time is older than 7 years by PostgreSQL's clock. TRUNCATE stays refused. Settings versions are kept.
 */
return new class extends Migration
{
    /** @var list<array{table: string, trigger: string, function: string, events: string, column: string, message: string}> */
    private const TABLES = [
        ['table' => 'hr_clock_sessions', 'trigger' => 'hr_clock_sessions_guard', 'function' => 'guard_hr_clock_session_write', 'events' => 'INSERT OR UPDATE', 'column' => 'clock_in_at', 'message' => 'HR clock sessions are never deleted'],
        ['table' => 'hr_attendance_corrections', 'trigger' => 'hr_attendance_corrections_guard', 'function' => 'guard_hr_attendance_correction', 'events' => 'INSERT OR UPDATE', 'column' => 'created_at', 'message' => 'HR attendance corrections are never deleted'],
        ['table' => 'hr_overtime_requests', 'trigger' => 'hr_overtime_requests_guard', 'function' => 'guard_hr_overtime_request', 'events' => 'INSERT OR UPDATE', 'column' => 'created_at', 'message' => 'HR overtime requests are never deleted'],
        ['table' => 'hr_clock_events', 'trigger' => 'hr_clock_events_append', 'function' => 'guard_hr_attendance_append_only', 'events' => 'UPDATE', 'column' => 'created_at', 'message' => 'HR attendance evidence is append-only'],
        ['table' => 'hr_location_consents', 'trigger' => 'hr_location_consents_append', 'function' => 'guard_hr_attendance_append_only', 'events' => 'UPDATE', 'column' => 'acknowledged_at', 'message' => 'HR attendance evidence is append-only'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- TG_ARGV[0] is the row's own time column, TG_ARGV[1] the refusal message the table always used.
CREATE OR REPLACE FUNCTION guard_hr_attendance_retention_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF current_setting('kpone.hr_attendance_purge', true) = 'on' AND (to_jsonb(OLD) ->> TG_ARGV[0])::timestamptz < now() - interval '7 years' THEN
  RETURN OLD;
 END IF;
 RAISE EXCEPTION '%', TG_ARGV[1] USING ERRCODE = 'P0001';
END $$;
SQL);
        foreach (self::TABLES as $t) {
            // The table's own guard keeps its insert and update rules; only its delete moves to the retention guard.
            DB::unprepared("DROP TRIGGER {$t['trigger']} ON {$t['table']};
CREATE TRIGGER {$t['trigger']} BEFORE {$t['events']} ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION {$t['function']}();
CREATE TRIGGER {$t['table']}_retention BEFORE DELETE ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_retention_delete('{$t['column']}', '{$t['message']}');");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            $events = $t['events'] === 'UPDATE' ? 'UPDATE OR DELETE' : 'INSERT OR UPDATE OR DELETE';
            DB::unprepared("DROP TRIGGER IF EXISTS {$t['table']}_retention ON {$t['table']};
DROP TRIGGER {$t['trigger']} ON {$t['table']};
CREATE TRIGGER {$t['trigger']} BEFORE {$events} ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION {$t['function']}();");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS guard_hr_attendance_retention_delete();');
    }
};
