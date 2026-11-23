<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TRI-1c (owner decisions 22 Nov 2026): when the doctor starts the consultation, KPOne copies the visit's triage
 * readings into the encounter's vitals, each reading taken from the latest triage save that has it, and the page says
 * "From triage, last taken by <CA> at <time>" while the copy stands.
 *
 * `source_triage_assessment_id` names the visit's latest triage row while the vitals are still the copy. PostgreSQL
 * keeps the label true: the source must be the encounter visit's latest triage row (same organisation and branch),
 * and the seven readings must equal, reading by reading, the latest non-empty value across the visit's triage rows.
 * On update the source can only be kept or cleared (never set or switched), so a change to the readings must clear it
 * in the same save. Additive; existing rows keep a null source. ai_read only uses EXISTS on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounter_vital_observations', function (Blueprint $table) {
            // No foreign key: a key referencing the append-only triage table would change how PostgreSQL refuses a
            // TRUNCATE of it (before its own append-only trigger). Triage rows are never deleted, and the trigger
            // below checks that the source row exists and belongs to the encounter's visit.
            $table->unsignedBigInteger('source_triage_assessment_id')->nullable()->after('height_cm');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kp_encounter_vitals_triage_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE enc record; src record;
BEGIN
 IF TG_OP = 'UPDATE' AND NEW.source_triage_assessment_id IS NOT NULL
    AND NEW.source_triage_assessment_id IS DISTINCT FROM OLD.source_triage_assessment_id THEN
  RAISE EXCEPTION 'The triage source of encounter vitals can only be kept or cleared' USING ERRCODE = '23514';
 END IF;
 IF NEW.source_triage_assessment_id IS NULL THEN
  RETURN NEW;
 END IF;
 SELECT e.visit_id, e.organisation_id, e.branch_id INTO enc FROM clinical_encounters e WHERE e.id = NEW.clinical_encounter_id;
 SELECT t.id, t.visit_id, t.organisation_id, t.branch_id, t.sequence INTO src
   FROM visit_triage_assessments t WHERE t.id = NEW.source_triage_assessment_id;
 IF enc.visit_id IS NULL OR src.id IS NULL OR src.visit_id <> enc.visit_id
    OR src.organisation_id <> NEW.organisation_id OR src.branch_id <> NEW.branch_id THEN
  RAISE EXCEPTION 'The triage source must belong to the encounter''s visit' USING ERRCODE = '23514';
 END IF;
 IF src.sequence <> (SELECT max(m.sequence) FROM visit_triage_assessments m WHERE m.visit_id = enc.visit_id) THEN
  RAISE EXCEPTION 'The triage source must be the visit''s latest triage' USING ERRCODE = '23514';
 END IF;
 IF (NEW.systolic_bp, NEW.diastolic_bp, NEW.pulse_bpm, NEW.temperature_celsius, NEW.spo2_percent, NEW.weight_kg, NEW.height_cm)
    IS DISTINCT FROM (
      (SELECT t.systolic_bp FROM visit_triage_assessments t WHERE t.visit_id = enc.visit_id AND t.systolic_bp IS NOT NULL ORDER BY t.sequence DESC LIMIT 1),
      (SELECT t.diastolic_bp FROM visit_triage_assessments t WHERE t.visit_id = enc.visit_id AND t.diastolic_bp IS NOT NULL ORDER BY t.sequence DESC LIMIT 1),
      (SELECT t.pulse_bpm FROM visit_triage_assessments t WHERE t.visit_id = enc.visit_id AND t.pulse_bpm IS NOT NULL ORDER BY t.sequence DESC LIMIT 1),
      (SELECT t.temperature_celsius FROM visit_triage_assessments t WHERE t.visit_id = enc.visit_id AND t.temperature_celsius IS NOT NULL ORDER BY t.sequence DESC LIMIT 1),
      (SELECT t.spo2_percent FROM visit_triage_assessments t WHERE t.visit_id = enc.visit_id AND t.spo2_percent IS NOT NULL ORDER BY t.sequence DESC LIMIT 1),
      (SELECT t.weight_kg FROM visit_triage_assessments t WHERE t.visit_id = enc.visit_id AND t.weight_kg IS NOT NULL ORDER BY t.sequence DESC LIMIT 1),
      (SELECT t.height_cm FROM visit_triage_assessments t WHERE t.visit_id = enc.visit_id AND t.height_cm IS NOT NULL ORDER BY t.sequence DESC LIMIT 1)
    ) THEN
  RAISE EXCEPTION 'Vitals marked as taken at triage must match the triage readings' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

DROP TRIGGER IF EXISTS encounter_vitals_triage_source ON encounter_vital_observations;
CREATE TRIGGER encounter_vitals_triage_source BEFORE INSERT OR UPDATE ON encounter_vital_observations
 FOR EACH ROW EXECUTE FUNCTION kp_encounter_vitals_triage_source();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS encounter_vitals_triage_source ON encounter_vital_observations;
DROP FUNCTION IF EXISTS kp_encounter_vitals_triage_source();
SQL);
        }
        Schema::table('encounter_vital_observations', function (Blueprint $table) {
            $table->dropColumn('source_triage_assessment_id');
        });
    }
};
