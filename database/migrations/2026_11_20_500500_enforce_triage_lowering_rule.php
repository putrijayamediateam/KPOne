<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TRI-1b, owner rule 1 (20 Nov 2026): a triage zone is lowered only after the reading that crossed its limit has been
 * retaken and is within limits, and never while a red flag is ticked. PostgreSQL repeats TriageService:
 *
 * - kp_triage_assess() works out the zone from the readings and the list of crossed readings, with the same rules as
 *   TriageZoneRules (PHP) and triage-zone.ts (tests/Fixtures/triage-zone-cases.json keeps the three in step);
 * - the insert trigger refuses a suggested zone or an age group that does not follow the readings, codes and the
 *   patient's date of birth (so a direct write cannot fake a higher suggestion to dodge the rule), and a lowering
 *   (below the suggestion, or below the visit's latest zone) with no earlier row or no reading, with a red flag
 *   ticked, with a reading that crossed a limit earlier not retaken within limits, or, for an override below the
 *   suggestion, with any reading past a limit now.
 *
 * Existing rows are not touched (append-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kp_triage_assess(version_id bigint, band text, sys numeric, dia numeric, pulse numeric,
  temp numeric, spo2 numeric, weight numeric, height numeric, OUT zone text, OUT crossed text[], OUT red text[])
LANGUAGE sql STABLE AS $$
 SELECT CASE COALESCE(max(CASE t.zone WHEN 'red' THEN 3 WHEN 'yellow' THEN 2 END), 1)
          WHEN 3 THEN 'red' WHEN 2 THEN 'yellow' ELSE 'green' END,
        COALESCE(array_agg(DISTINCT r.reading ORDER BY r.reading) FILTER (WHERE t.id IS NOT NULL), '{}'),
        COALESCE(array_agg(DISTINCT r.reading ORDER BY r.reading) FILTER (WHERE t.zone = 'red'), '{}')
 FROM (VALUES ('systolic_bp', sys), ('diastolic_bp', dia), ('pulse_bpm', pulse), ('temperature_celsius', temp),
              ('spo2_percent', spo2), ('weight_kg', weight), ('height_cm', height)) AS r(reading, value)
 LEFT JOIN triage_guideline_thresholds t
   ON t.guideline_version_id = version_id AND t.reading = r.reading
  AND (band = 'unknown' OR t.age_band = band)
  AND r.value IS NOT NULL
  AND ((t.direction = 'very_high' AND r.value >= t.limit_value) OR (t.direction = 'very_low' AND r.value <= t.limit_value))
$$;

CREATE OR REPLACE FUNCTION kp_triage_reading(row_value visit_triage_assessments, field text) RETURNS numeric LANGUAGE sql IMMUTABLE AS $$
 SELECT CASE field WHEN 'systolic_bp' THEN row_value.systolic_bp::numeric WHEN 'diastolic_bp' THEN row_value.diastolic_bp::numeric
   WHEN 'pulse_bpm' THEN row_value.pulse_bpm::numeric WHEN 'temperature_celsius' THEN row_value.temperature_celsius
   WHEN 'spo2_percent' THEN row_value.spo2_percent WHEN 'weight_kg' THEN row_value.weight_kg WHEN 'height_cm' THEN row_value.height_cm END
$$;

CREATE OR REPLACE FUNCTION kp_visit_triage_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v record; q record; g record; p record; a record; latest integer; expected text; band text; previous text;
  retake text[]; above text[]; today date; years integer;
BEGIN
 SELECT * INTO v FROM visits WHERE id = NEW.visit_id FOR UPDATE;
 SELECT * INTO q FROM queue_entries WHERE id = NEW.queue_entry_id;
 IF v.id IS NULL OR q.id IS NULL OR q.visit_id <> v.id
  OR v.organisation_id <> NEW.organisation_id OR v.branch_id <> NEW.branch_id
  OR q.organisation_id <> NEW.organisation_id OR q.branch_id <> NEW.branch_id
  OR v.status <> 'registered' OR v.visit_type <> 'consultation'
  OR NOT (q.status = 'waiting' OR (q.status = 'serving' AND NOT EXISTS (SELECT 1 FROM clinical_encounters e WHERE e.visit_id = v.id))) THEN
  RAISE EXCEPTION 'Triage needs a registered consultation that is Waiting or called in before the consultation starts' USING ERRCODE = '23514';
 END IF;
 IF NOT kp_triage_holds(NEW.organisation_id, NEW.recorded_by_user_id, 'visits.triage.record.branch') THEN
  RAISE EXCEPTION 'Triage is recorded only by an active holder of the triage permission' USING ERRCODE = '23514';
 END IF;
 SELECT COALESCE(max(sequence), 0) INTO latest FROM visit_triage_assessments WHERE visit_id = NEW.visit_id;
 IF NEW.sequence <> latest + 1 THEN
  RAISE EXCEPTION 'Triage rows are numbered in order per visit' USING ERRCODE = '23514';
 END IF;
 PERFORM kp_triage_guideline_gate(NEW.organisation_id, false);
 SELECT id, child_age_limit_years INTO g FROM triage_guideline_versions
  WHERE organisation_id = NEW.organisation_id AND status = 'approved' FOR SHARE;
 IF g.id IS DISTINCT FROM NEW.guideline_version_id THEN
  RAISE EXCEPTION 'Triage must use the triage guideline version in force' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM unnest(NEW.red_flag_codes) c WHERE NOT EXISTS (SELECT 1 FROM triage_guideline_signs s
      WHERE s.guideline_version_id = NEW.guideline_version_id AND s.kind = 'red_flag' AND s.code = c))
  OR EXISTS (SELECT 1 FROM unnest(NEW.yellow_sign_codes) c WHERE NOT EXISTS (SELECT 1 FROM triage_guideline_signs s
      WHERE s.guideline_version_id = NEW.guideline_version_id AND s.kind = 'yellow_sign' AND s.code = c)) THEN
  RAISE EXCEPTION 'Triage codes must belong to the triage guideline version in force' USING ERRCODE = '23514';
 END IF;
 IF NEW.guideline_version_id IS NULL THEN
  RETURN NEW;
 END IF;

 -- The age group follows the date of birth on the branch-local date of the save (an unknown date of birth with an
 -- adult confirmation is adult), and the suggested zone follows the readings and the ticked codes.
 SELECT pt.date_of_birth AS dob, b.timezone AS tz INTO p FROM patients pt JOIN branches b ON b.id = NEW.branch_id WHERE pt.id = v.patient_id;
 today := (NEW.recorded_at AT TIME ZONE p.tz)::date;
 IF p.dob IS NULL THEN
  band := CASE WHEN EXISTS (SELECT 1 FROM patient_adult_confirmations c WHERE c.organisation_id = NEW.organisation_id AND c.patient_id = v.patient_id)
   THEN 'adult' ELSE 'unknown' END;
 ELSE
  years := extract(year FROM age(today, p.dob))::integer;
  band := CASE WHEN years < g.child_age_limit_years THEN 'child' ELSE 'adult' END;
 END IF;
 IF band IS DISTINCT FROM NEW.age_band THEN
  RAISE EXCEPTION 'The triage age group must follow the patient''s date of birth' USING ERRCODE = '23514';
 END IF;
 SELECT * INTO a FROM kp_triage_assess(NEW.guideline_version_id, NEW.age_band, NEW.systolic_bp, NEW.diastolic_bp, NEW.pulse_bpm,
   NEW.temperature_celsius, NEW.spo2_percent, NEW.weight_kg, NEW.height_cm);
 expected := a.zone;
 IF cardinality(NEW.yellow_sign_codes) > 0 AND expected = 'green' THEN expected := 'yellow'; END IF;
 IF cardinality(NEW.red_flag_codes) > 0 THEN expected := 'red'; END IF;
 IF expected <> NEW.suggested_zone THEN
  RAISE EXCEPTION 'The suggested triage zone must follow the readings and the triage guideline' USING ERRCODE = '23514';
 END IF;

 SELECT chosen_zone INTO previous FROM visit_triage_assessments
  WHERE visit_id = NEW.visit_id AND chosen_zone IS NOT NULL ORDER BY sequence DESC LIMIT 1;
 IF kp_triage_zone_rank(NEW.chosen_zone) < kp_triage_zone_rank(NEW.suggested_zone)
  OR (previous IS NOT NULL AND kp_triage_zone_rank(NEW.chosen_zone) < kp_triage_zone_rank(previous)) THEN
  IF latest = 0 OR num_nonnulls(NEW.systolic_bp, NEW.pulse_bpm, NEW.temperature_celsius, NEW.spo2_percent, NEW.weight_kg, NEW.height_cm) = 0 THEN
   RAISE EXCEPTION 'A lower triage zone needs the readings taken again' USING ERRCODE = '23514';
  END IF;
  IF cardinality(NEW.red_flag_codes) > 0 THEN
   RAISE EXCEPTION 'A triage zone cannot be lowered while a red flag is ticked' USING ERRCODE = '23514';
  END IF;
  IF NEW.chosen_zone <> 'red' AND EXISTS (SELECT 1 FROM visit_triage_assessments e WHERE e.visit_id = NEW.visit_id AND cardinality(e.red_flag_codes) > 0) THEN
   RAISE EXCEPTION 'A red flag was ticked at this visit, so the triage zone stays red' USING ERRCODE = '23514';
  END IF;
  -- Past a limit of a zone above the one chosen: any limit for green, a red limit for yellow, none for red.
  above := CASE NEW.chosen_zone WHEN 'green' THEN a.crossed WHEN 'yellow' THEN a.red ELSE '{}'::text[] END;
  SELECT COALESCE(array_agg(DISTINCT c), '{}') INTO retake FROM visit_triage_assessments e,
   LATERAL kp_triage_assess(e.guideline_version_id, e.age_band, e.systolic_bp, e.diastolic_bp, e.pulse_bpm,
     e.temperature_celsius, e.spo2_percent, e.weight_kg, e.height_cm) x, unnest(x.crossed) c
   WHERE e.visit_id = NEW.visit_id AND e.guideline_version_id IS NOT NULL;
  IF EXISTS (SELECT 1 FROM unnest(retake) c WHERE c = ANY (above) OR kp_triage_reading(NEW, c) IS NULL) THEN
   RAISE EXCEPTION 'A lower triage zone needs every reading that crossed a limit retaken within limits' USING ERRCODE = '23514';
  END IF;
  IF kp_triage_zone_rank(NEW.chosen_zone) < kp_triage_zone_rank(NEW.suggested_zone) AND cardinality(above) > 0 THEN
   RAISE EXCEPTION 'A zone below the suggestion needs every reading within the limits of that zone' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    /** Restores the TRI-1a insert rule; the assessment helpers stay (harmless, used by nothing else). */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kp_visit_triage_scope() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v record; q record; g record; prev record; latest integer;
BEGIN
 SELECT * INTO v FROM visits WHERE id = NEW.visit_id FOR UPDATE;
 SELECT * INTO q FROM queue_entries WHERE id = NEW.queue_entry_id;
 IF v.id IS NULL OR q.id IS NULL OR q.visit_id <> v.id
  OR v.organisation_id <> NEW.organisation_id OR v.branch_id <> NEW.branch_id
  OR q.organisation_id <> NEW.organisation_id OR q.branch_id <> NEW.branch_id
  OR v.status <> 'registered' OR v.visit_type <> 'consultation'
  OR NOT (q.status = 'waiting' OR (q.status = 'serving' AND NOT EXISTS (SELECT 1 FROM clinical_encounters e WHERE e.visit_id = v.id))) THEN
  RAISE EXCEPTION 'Triage needs a registered consultation that is Waiting or called in before the consultation starts' USING ERRCODE = '23514';
 END IF;
 IF NOT kp_triage_holds(NEW.organisation_id, NEW.recorded_by_user_id, 'visits.triage.record.branch') THEN
  RAISE EXCEPTION 'Triage is recorded only by an active holder of the triage permission' USING ERRCODE = '23514';
 END IF;
 SELECT COALESCE(max(sequence), 0) INTO latest FROM visit_triage_assessments WHERE visit_id = NEW.visit_id;
 IF NEW.sequence <> latest + 1 THEN
  RAISE EXCEPTION 'Triage rows are numbered in order per visit' USING ERRCODE = '23514';
 END IF;
 PERFORM kp_triage_guideline_gate(NEW.organisation_id, false);
 SELECT id, child_age_limit_years INTO g FROM triage_guideline_versions
  WHERE organisation_id = NEW.organisation_id AND status = 'approved' FOR SHARE;
 IF g.id IS DISTINCT FROM NEW.guideline_version_id THEN
  RAISE EXCEPTION 'Triage must use the triage guideline version in force' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM unnest(NEW.red_flag_codes) c WHERE NOT EXISTS (SELECT 1 FROM triage_guideline_signs s
      WHERE s.guideline_version_id = NEW.guideline_version_id AND s.kind = 'red_flag' AND s.code = c))
  OR EXISTS (SELECT 1 FROM unnest(NEW.yellow_sign_codes) c WHERE NOT EXISTS (SELECT 1 FROM triage_guideline_signs s
      WHERE s.guideline_version_id = NEW.guideline_version_id AND s.kind = 'yellow_sign' AND s.code = c)) THEN
  RAISE EXCEPTION 'Triage codes must belong to the triage guideline version in force' USING ERRCODE = '23514';
 END IF;
 IF NEW.chosen_zone IS NOT NULL AND kp_triage_zone_rank(NEW.chosen_zone) < kp_triage_zone_rank(NEW.suggested_zone) THEN
  SELECT * INTO prev FROM visit_triage_assessments WHERE visit_id = NEW.visit_id AND sequence = latest;
  IF prev.id IS NULL
   OR num_nonnulls(NEW.systolic_bp, NEW.pulse_bpm, NEW.temperature_celsius, NEW.spo2_percent, NEW.weight_kg, NEW.height_cm) = 0
   OR (NEW.systolic_bp, NEW.diastolic_bp, NEW.pulse_bpm, NEW.temperature_celsius, NEW.spo2_percent, NEW.weight_kg, NEW.height_cm)
      IS NOT DISTINCT FROM (prev.systolic_bp, prev.diastolic_bp, prev.pulse_bpm, prev.temperature_celsius, prev.spo2_percent, prev.weight_kg, prev.height_cm) THEN
   RAISE EXCEPTION 'A lower triage zone needs the readings taken again' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }
};
