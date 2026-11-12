<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDPA 15 (owner, 2026-11-11, relayed by B.E.R.N.I.S.S): an unsuccessful candidate's interview records (their time
 * choices, their interview bookings and their interview-document download records) are deleted 6 months after the
 * application closed as Failed, Not taken forward, Auto-rejected or Withdrawn, by the daily `hr-interview-records:purge`.
 * The clinic's own interview documents are not personal data and stay until HR removes them. Each table keeps its insert
 * and update rules; only its delete moves to one retention guard, which refuses unless the purge marked its transaction
 * and the row's application closed unsuccessfully more than 6 months ago by PostgreSQL's clock. TRUNCATE stays refused.
 */
return new class extends Migration
{
    /** @var list<array{table: string, trigger: string, events: string}> */
    private const TABLES = [
        ['table' => 'hr_job_interviews', 'trigger' => 'hr_job_interviews_no_delete', 'events' => ''],
        ['table' => 'hr_job_interview_picks', 'trigger' => 'hr_job_picks_append', 'events' => 'UPDATE'],
        ['table' => 'hr_interview_document_events', 'trigger' => 'hr_interview_doc_events_append', 'events' => 'UPDATE'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_interview_records_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF current_setting('kpone.hr_interview_purge', true) = 'on' AND EXISTS (
   SELECT 1 FROM hr_job_applications a WHERE a.id = (to_jsonb(OLD) ->> 'application_id')::bigint
     AND a.status IN ('failed', 'not_taken_forward', 'auto_rejected', 'withdrawn') AND a.closed_at < now() - interval '6 months') THEN
  RETURN OLD;
 END IF;
 RAISE EXCEPTION 'HR record evidence is append-only' USING ERRCODE = 'P0001';
END $$;
SQL);
        foreach (self::TABLES as $t) {
            $keep = $t['events'] === '' ? '' : "CREATE TRIGGER {$t['trigger']} BEFORE {$t['events']} ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();\n";
            DB::unprepared("DROP TRIGGER {$t['trigger']} ON {$t['table']};
{$keep}CREATE TRIGGER {$t['table']}_retention BEFORE DELETE ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION guard_hr_interview_records_delete();");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            $events = $t['events'] === '' ? 'DELETE' : 'UPDATE OR DELETE';
            DB::unprepared("DROP TRIGGER IF EXISTS {$t['table']}_retention ON {$t['table']};
DROP TRIGGER IF EXISTS {$t['trigger']} ON {$t['table']};
CREATE TRIGGER {$t['trigger']} BEFORE {$events} ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS guard_hr_interview_records_delete();');
    }
};
