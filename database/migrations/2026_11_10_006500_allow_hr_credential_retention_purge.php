<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PDPA 15 (owner, 2026-11-11, relayed by B.E.R.N.I.S.S; the accountant confirms the 7 years before real use): credential
 * records are kept 7 years, then deleted by the daily `hr-credentials:purge`. The delete-only trigger on the records moves
 * to a retention guard that refuses (with the old message) unless the purge marked its own transaction and the record's
 * own last change is older than 7 years by PostgreSQL's clock. Updates keep their guard; TRUNCATE stays refused.
 * Credential types, their versions and roles are settings and are kept; evidence certificates are staff documents and
 * follow the staff document retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_credential_retention_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF current_setting('kpone.hr_credential_purge', true) = 'on' AND OLD.updated_at < now() - interval '7 years' THEN
  RETURN OLD;
 END IF;
 RAISE EXCEPTION 'HR record evidence is append-only' USING ERRCODE = 'P0001';
END $$;
DROP TRIGGER hr_credential_records_no_delete ON hr_credential_records;
CREATE TRIGGER hr_credential_records_retention BEFORE DELETE ON hr_credential_records FOR EACH ROW EXECUTE FUNCTION guard_hr_credential_retention_delete();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS hr_credential_records_retention ON hr_credential_records;
CREATE TRIGGER hr_credential_records_no_delete BEFORE DELETE ON hr_credential_records FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
DROP FUNCTION IF EXISTS guard_hr_credential_retention_delete();
SQL);
    }
};
