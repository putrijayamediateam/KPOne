<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDPA 15 (owner, 2026-11-11, relayed by B.E.R.N.I.S.S; the accountant confirms the 7 years before real use): staff claims
 * and their receipts are kept 7 years, then deleted by the daily `hr-claims:purge`. Each table keeps its insert and update
 * rules; only its delete moves to one retention guard, which refuses (with the table's old message) unless the purge has
 * marked its own transaction and the claim is older than 7 years by PostgreSQL's clock, judged by the claim's own last
 * change for every table (a receipt open never restarts the 7 years; Suresh). TRUNCATE stays refused.
 * Claim settings, categories, their versions and the number counter are settings and are kept.
 */
return new class extends Migration
{
    /** @var list<array{table: string, trigger: string, events: string, column: string}> */
    private const TABLES = [
        ['table' => 'hr_staff_claims', 'trigger' => 'hr_staff_claims_no_delete', 'events' => '', 'column' => 'updated_at'],
        ['table' => 'hr_staff_claim_receipts', 'trigger' => 'hr_staff_claim_receipts_append', 'events' => 'UPDATE', 'column' => 'claim'],
        ['table' => 'hr_staff_claim_actions', 'trigger' => 'hr_staff_claim_actions_append', 'events' => 'UPDATE', 'column' => 'claim'],
        ['table' => 'hr_staff_claim_payments', 'trigger' => 'hr_staff_claim_payments_append', 'events' => 'UPDATE', 'column' => 'claim'],
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- TG_ARGV[0] is 'updated_at' for the claim itself, or 'claim' for a row on a claim, judged by that claim's last change.
CREATE OR REPLACE FUNCTION guard_hr_claim_retention_delete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
 changed timestamptz;
BEGIN
 IF current_setting('kpone.hr_claim_purge', true) = 'on' THEN
  IF TG_ARGV[0] = 'claim' THEN
   SELECT c.updated_at INTO changed FROM hr_staff_claims c WHERE c.id = (to_jsonb(OLD) ->> 'claim_id')::bigint;
  ELSE
   changed := (to_jsonb(OLD) ->> TG_ARGV[0])::timestamptz;
  END IF;
  IF changed < now() - interval '7 years' THEN
   RETURN OLD;
  END IF;
 END IF;
 RAISE EXCEPTION 'HR record evidence is append-only' USING ERRCODE = 'P0001';
END $$;
SQL);
        foreach (self::TABLES as $t) {
            // A claim had a delete-only trigger; the others keep refusing updates with their own trigger.
            $keep = $t['events'] === '' ? '' : "CREATE TRIGGER {$t['trigger']} BEFORE {$t['events']} ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();\n";
            DB::unprepared("DROP TRIGGER {$t['trigger']} ON {$t['table']};
{$keep}CREATE TRIGGER {$t['table']}_retention BEFORE DELETE ON {$t['table']} FOR EACH ROW EXECUTE FUNCTION guard_hr_claim_retention_delete('{$t['column']}');");
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
        DB::unprepared('DROP FUNCTION IF EXISTS guard_hr_claim_retention_delete();');
    }
};
