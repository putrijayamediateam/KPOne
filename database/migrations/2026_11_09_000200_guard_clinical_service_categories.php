<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CAT-11 (B.E.R.N.I.S.S for the owner, 2026-11-06): a clinical service's category is one of "Procedure(s)",
 * "Investigation(s)", "Consultation-related" or "Other service", or empty. Rows saved before CAT-11 are not changed
 * or checked: the trigger checks a new row, and an existing row only when its category changes. The same rule is in
 * ClinicalServiceCategories; keep the two lists equal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_clinical_service_category() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.category IS NULL OR (TG_OP = 'UPDATE' AND NEW.category IS NOT DISTINCT FROM OLD.category) THEN
  RETURN NEW;
 END IF;
 IF NEW.category NOT IN ('Procedure(s)', 'Investigation(s)', 'Consultation-related', 'Other service') THEN
  RAISE EXCEPTION 'Clinical service category must be one of the fixed names' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER clinical_service_catalogue_items_category BEFORE INSERT OR UPDATE OF category ON clinical_service_catalogue_items
 FOR EACH ROW EXECUTE FUNCTION guard_clinical_service_category();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS clinical_service_catalogue_items_category ON clinical_service_catalogue_items; DROP FUNCTION IF EXISTS guard_clinical_service_category();');
    }
};
