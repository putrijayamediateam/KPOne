<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TRI-1a (owner decisions 19 Nov 2026, TRI-1-DESIGN section 4.1): one append-only row per triage save; a correction is
 * a new row and the latest counts. PostgreSQL refuses updates, deletes, truncates, a row on a visit that is not a
 * registered consultation Waiting (or called in with no consultation started), a stale guideline version, codes the
 * cited version does not hold, and a lower zone that is not a re-take with a fixed reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('visit_triage_assessments')) {
            return;
        }
        Schema::create('visit_triage_assessments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('visit_id');
            $t->unsignedBigInteger('queue_entry_id');
            $t->unsignedSmallInteger('sequence');
            $t->unsignedSmallInteger('systolic_bp')->nullable();
            $t->unsignedSmallInteger('diastolic_bp')->nullable();
            $t->unsignedSmallInteger('pulse_bpm')->nullable();
            $t->decimal('temperature_celsius', 5, 2)->nullable();
            $t->decimal('spo2_percent', 5, 2)->nullable();
            $t->decimal('weight_kg', 7, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->string('age_band', 10)->nullable();
            $t->foreignId('guideline_version_id')->nullable()->constrained('triage_guideline_versions')->restrictOnDelete();
            $t->string('suggested_zone', 10)->nullable();
            $t->string('chosen_zone', 10)->nullable();
            $t->string('override_reason_code', 40)->nullable();
            $t->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('recorded_at');
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->foreign(['visit_id', 'organisation_id', 'branch_id'], 'visit_triage_visit_scope_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('visits')->restrictOnDelete();
            $t->foreign(['queue_entry_id', 'organisation_id', 'branch_id'], 'visit_triage_queue_scope_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('queue_entries')->restrictOnDelete();
            $t->unique(['visit_id', 'sequence']);
            $t->unique(['organisation_id', 'recorded_by_user_id', 'idempotency_key'], 'visit_triage_retry_unique');
            $t->index(['organisation_id', 'branch_id', 'recorded_at']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            DB::statement("ALTER TABLE visit_triage_assessments ADD COLUMN red_flag_codes text NOT NULL DEFAULT '[]'");
            DB::statement("ALTER TABLE visit_triage_assessments ADD COLUMN yellow_sign_codes text NOT NULL DEFAULT '[]'");

            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE visit_triage_assessments ADD COLUMN red_flag_codes text[] NOT NULL DEFAULT '{}';
ALTER TABLE visit_triage_assessments ADD COLUMN yellow_sign_codes text[] NOT NULL DEFAULT '{}';

CREATE OR REPLACE FUNCTION kp_triage_zone_rank(z text) RETURNS integer LANGUAGE sql IMMUTABLE AS $$
 SELECT CASE z WHEN 'green' THEN 1 WHEN 'yellow' THEN 2 WHEN 'red' THEN 3 END
$$;
CREATE OR REPLACE FUNCTION kp_triage_codes_ok(codes text[]) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
 SELECT cardinality(codes) <= 12 AND (cardinality(codes) = 0 OR array_ndims(codes) = 1)
  AND (SELECT count(DISTINCT c) FROM unnest(codes) c) = cardinality(codes)
  AND NOT EXISTS (SELECT 1 FROM unnest(codes) c WHERE c IS NULL OR c !~ '^[a-z][a-z0-9_]{1,39}$')
$$;

ALTER TABLE visit_triage_assessments ADD CONSTRAINT visit_triage_shape CHECK (
 sequence > 0
 AND ((systolic_bp IS NULL) = (diastolic_bp IS NULL))
 AND (systolic_bp IS NULL OR systolic_bp > 0) AND (diastolic_bp IS NULL OR diastolic_bp > 0)
 AND (pulse_bpm IS NULL OR pulse_bpm > 0) AND (temperature_celsius IS NULL OR temperature_celsius > 0)
 AND (spo2_percent IS NULL OR spo2_percent BETWEEN 0 AND 100)
 AND (weight_kg IS NULL OR weight_kg > 0) AND (height_cm IS NULL OR height_cm > 0)
 AND (age_band IS NULL OR age_band IN ('adult','child','unknown'))
 AND (suggested_zone IS NULL OR suggested_zone IN ('green','yellow','red'))
 AND (chosen_zone IS NULL OR chosen_zone IN ('green','yellow','red'))
 AND ((guideline_version_id IS NULL) = (suggested_zone IS NULL))
 AND ((guideline_version_id IS NULL) = (chosen_zone IS NULL))
 AND ((guideline_version_id IS NULL) = (age_band IS NULL))
 AND (guideline_version_id IS NOT NULL OR (cardinality(red_flag_codes) = 0 AND cardinality(yellow_sign_codes) = 0))
 AND kp_triage_codes_ok(red_flag_codes) AND kp_triage_codes_ok(yellow_sign_codes)
 AND (
  (chosen_zone IS NULL AND override_reason_code IS NULL)
  OR (kp_triage_zone_rank(chosen_zone) = kp_triage_zone_rank(suggested_zone) AND override_reason_code IS NULL)
  OR (kp_triage_zone_rank(chosen_zone) < kp_triage_zone_rank(suggested_zone)
      AND override_reason_code IN ('doctor_reviewed_at_triage','device_error_confirmed'))
  OR (kp_triage_zone_rank(chosen_zone) > kp_triage_zone_rank(suggested_zone)
      AND (override_reason_code IS NULL OR override_reason_code IN ('staff_concern','patient_appears_unwell')))));

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
 IF NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.recorded_by_user_id AND organisation_id = NEW.organisation_id) THEN
  RAISE EXCEPTION 'Invalid triage actor' USING ERRCODE = '23514';
 END IF;
 SELECT COALESCE(max(sequence), 0) INTO latest FROM visit_triage_assessments WHERE visit_id = NEW.visit_id;
 IF NEW.sequence <> latest + 1 THEN
  RAISE EXCEPTION 'Triage rows are numbered in order per visit' USING ERRCODE = '23514';
 END IF;
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
CREATE TRIGGER visit_triage_scope BEFORE INSERT ON visit_triage_assessments FOR EACH ROW EXECUTE FUNCTION kp_visit_triage_scope();
CREATE TRIGGER visit_triage_immutable BEFORE UPDATE OR DELETE ON visit_triage_assessments FOR EACH ROW EXECUTE FUNCTION kp_triage_immutable();
CREATE TRIGGER visit_triage_truncate BEFORE TRUNCATE ON visit_triage_assessments FOR EACH STATEMENT EXECUTE FUNCTION kp_triage_immutable();
SQL);
    }

    public function down(): void {} // Triage evidence is retained.
};
