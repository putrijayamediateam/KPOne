<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDPA 15 (owner, 2026-11-11, relayed by B.E.R.N.I.S.S; the accountant confirms the 7 years before real use): leave
 * records are kept 7 years after their leave year, then deleted by the daily `hr-leave:purge`. A leave year Y goes once
 * PostgreSQL's own clock is in year Y + 8 or later (in 2034, years up to 2026). Each table keeps its insert and update
 * rules; only its delete moves to one retention guard, which refuses (with the table's old message) unless the purge has
 * marked its own transaction and the row's leave year (for actions and evidence, the year of their request; for retry
 * records, the time they were written) is past the 7 years. TRUNCATE stays refused. Leave types, working days and
 * public holidays are clinic settings and are kept.
 */
return new class extends Migration
{
    /** @var list<array{table: string, trigger: string, function: string, events: string, mode: string, message: string}> */
    private const TABLES = [
        ['table' => 'hr_leave_requests', 'trigger' => 'hr_leave_requests_guard', 'function' => 'guard_hr_leave_decided', 'events' => 'UPDATE', 'mode' => 'year', 'message' => 'HR leave records are never deleted'],
        ['table' => 'hr_leave_openings', 'trigger' => 'hr_leave_openings_guard', 'function' => 'guard_hr_leave_decided', 'events' => 'UPDATE', 'mode' => 'year', 'message' => 'HR leave records are never deleted'],
        ['table' => 'hr_leave_adjustments', 'trigger' => 'hr_leave_adjustments_guard', 'function' => 'guard_hr_leave_decided', 'events' => 'UPDATE', 'mode' => 'year', 'message' => 'HR leave records are never deleted'],
        ['table' => 'hr_leave_ledger', 'trigger' => 'hr_leave_ledger_append', 'function' => 'guard_hr_leave_append_only', 'events' => 'UPDATE', 'mode' => 'year', 'message' => 'HR leave evidence is append-only'],
        ['table' => 'hr_leave_entitlements', 'trigger' => 'hr_leave_entitlements_append', 'function' => 'guard_hr_leave_append_only', 'events' => 'UPDATE', 'mode' => 'year', 'message' => 'HR leave evidence is append-only'],
        ['table' => 'hr_leave_request_actions', 'trigger' => 'hr_leave_request_actions_append', 'function' => 'guard_hr_leave_append_only', 'events' => 'UPDATE', 'mode' => 'request', 'message' => 'HR leave evidence is append-only'],
        ['table' => 'hr_leave_evidence_files', 'trigger' => 'hr_leave_evidence_files_guard', 'function' => 'guard_hr_leave_evidence_update', 'events' => 'UPDATE', 'mode' => 'request', 'message' => 'HR leave evidence records are never deleted'],
        ['table' => 'hr_leave_evidence_events', 'trigger' => 'hr_leave_evidence_events_append', 'function' => 'guard_hr_leave_append_only', 'events' => 'UPDATE', 'mode' => 'file', 'message' => 'HR leave evidence is append-only'],
        ['table' => 'hr_leave_retries', 'trigger' => 'hr_leave_retries_append', 'function' => 'guard_hr_leave_append_only', 'events' => 'UPDATE', 'mode' => 'created', 'message' => 'HR leave evidence is append-only'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- TG_ARGV[0] says how the row's age is found, TG_ARGV[1] is the refusal message the table always used.
CREATE OR REPLACE FUNCTION guard_hr_leave_retention_delete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 leave_year integer;
BEGIN
 IF current_setting('kpone.hr_leave_purge', true) = 'on' THEN
  IF TG_ARGV[0] = 'created' THEN
   IF (to_jsonb(OLD) ->> 'created_at')::timestamptz < now() - interval '7 years' THEN
    RETURN OLD;
   END IF;
  ELSE
   IF TG_ARGV[0] = 'year' THEN
    leave_year := (to_jsonb(OLD) ->> 'year')::integer;
   ELSIF TG_ARGV[0] = 'request' THEN
    SELECT r.year INTO leave_year FROM hr_leave_requests r WHERE r.id = (to_jsonb(OLD) ->> 'leave_request_id')::bigint;
   ELSIF TG_ARGV[0] = 'file' THEN
    SELECT r.year INTO leave_year FROM hr_leave_evidence_files f JOIN hr_leave_requests r ON r.id = f.leave_request_id
     WHERE f.id = (to_jsonb(OLD) ->> 'evidence_file_id')::bigint;
   END IF;
   IF leave_year IS NOT NULL AND leave_year < extract(year FROM now() AT TIME ZONE 'UTC') - 7 THEN
    RETURN OLD;
   END IF;
  END IF;
 END IF;
 RAISE EXCEPTION '%', TG_ARGV[1] USING ERRCODE = 'P0001';
END $$;
SQL);
        foreach (self::TABLES as $t) {
            DB::unprepared("DROP TRIGGER {$t['trigger']} ON {$t['table']};
CREATE TRIGGER {$t['trigger']} BEFORE {$t['events']} ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION {$t['function']}();
CREATE TRIGGER {$t['table']}_retention BEFORE DELETE ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_retention_delete('{$t['mode']}', '{$t['message']}');");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t['table']}_retention ON {$t['table']};
DROP TRIGGER {$t['trigger']} ON {$t['table']};
CREATE TRIGGER {$t['trigger']} BEFORE UPDATE OR DELETE ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION {$t['function']}();");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS guard_hr_leave_retention_delete();');
    }
};
