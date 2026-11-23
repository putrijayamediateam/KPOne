<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TRI-1b (owner, 20 Nov 2026): a reading may have two limits per age group and direction, a yellow one and a red one
 * (for example temperature 38 °C yellow and 39.5 °C red). The yellow limit is always nearer normal than the red one,
 * and every "very low" limit stays below every "very high" limit for the same reading and age group. Up to 56 limits a
 * version (7 readings × 2 age groups × 2 directions × 2 zones). Versions already entered stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_guideline_thresholds', function (Blueprint $t): void {
            $t->dropUnique('triage_threshold_unique');
            $t->unique(['guideline_version_id', 'reading', 'age_band', 'direction', 'zone'], 'triage_threshold_zone_unique');
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE triage_guideline_versions DROP CONSTRAINT triage_guideline_shape;
ALTER TABLE triage_guideline_versions ADD CONSTRAINT triage_guideline_shape CHECK (
 status IN ('draft','approved','replaced','discarded') AND lock_version > 0 AND version_number > 0
 AND child_age_limit_years BETWEEN 1 AND 18
 AND red_flag_count BETWEEN 1 AND 12 AND yellow_sign_count BETWEEN 0 AND 12 AND threshold_count BETWEEN 0 AND 56
 AND char_length(btrim(approving_doctor_name)) BETWEEN 3 AND 120
 AND ((approved_by_user_id IS NULL) = (approved_at IS NULL))
 AND (approved_by_user_id IS NULL OR approved_by_user_id <> entered_by_user_id)
 AND ((status IN ('approved','replaced')) = (approved_at IS NOT NULL))
 AND ((status = 'replaced') = (replaced_at IS NOT NULL))
 AND ((status = 'discarded') = (discarded_at IS NOT NULL))
 AND ((discarded_by_user_id IS NULL) = (discarded_at IS NULL)));

CREATE OR REPLACE FUNCTION kp_triage_guideline_consistency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v record;
BEGIN
 SELECT * INTO v FROM triage_guideline_versions WHERE id = NEW.id;
 IF NOT EXISTS (SELECT 1 FROM triage_guideline_events e WHERE e.guideline_version_id = v.id AND e.record_version = v.lock_version
   AND e.event_type = CASE v.status WHEN 'draft' THEN 'entered' ELSE v.status END) THEN
  RAISE EXCEPTION 'A triage guideline change requires its event' USING ERRCODE = '23514';
 END IF;
 IF v.status = 'replaced' AND NOT EXISTS (SELECT 1 FROM triage_guideline_versions s WHERE s.organisation_id = v.organisation_id
   AND s.id <> v.id AND s.status = 'approved' AND s.version_number > v.version_number AND s.approved_at = v.replaced_at) THEN
  RAISE EXCEPTION 'A triage guideline version is replaced only by approving its successor' USING ERRCODE = '23514';
 END IF;
 IF (SELECT count(*) FROM triage_guideline_signs WHERE guideline_version_id = v.id AND kind = 'red_flag') <> v.red_flag_count
  OR (SELECT count(*) FROM triage_guideline_signs WHERE guideline_version_id = v.id AND kind = 'yellow_sign') <> v.yellow_sign_count
  OR (SELECT count(*) FROM triage_guideline_thresholds WHERE guideline_version_id = v.id) <> v.threshold_count THEN
  RAISE EXCEPTION 'A triage guideline version must hold exactly its entered signs and thresholds' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM triage_guideline_thresholds lo JOIN triage_guideline_thresholds hi
   ON hi.guideline_version_id = lo.guideline_version_id AND hi.reading = lo.reading AND hi.age_band = lo.age_band
   WHERE lo.guideline_version_id = v.id AND lo.direction = 'very_low' AND hi.direction = 'very_high' AND lo.limit_value >= hi.limit_value) THEN
  RAISE EXCEPTION 'A very low limit must be below the very high limit for the same reading' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM triage_guideline_thresholds y JOIN triage_guideline_thresholds r
   ON r.guideline_version_id = y.guideline_version_id AND r.reading = y.reading AND r.age_band = y.age_band AND r.direction = y.direction
   WHERE y.guideline_version_id = v.id AND y.zone = 'yellow' AND r.zone = 'red'
     AND ((y.direction = 'very_high' AND y.limit_value >= r.limit_value) OR (y.direction = 'very_low' AND y.limit_value <= r.limit_value))) THEN
  RAISE EXCEPTION 'A yellow limit must be nearer normal than the red limit for the same reading' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
SQL);
    }

    /** Refused once any version holds two limits for one reading, age group and direction (evidence is retained). */
    public function down(): void
    {
        $doubled = DB::table('triage_guideline_thresholds')
            ->select('guideline_version_id', 'reading', 'age_band', 'direction')
            ->groupBy('guideline_version_id', 'reading', 'age_band', 'direction')
            ->havingRaw('COUNT(*) > 1')->exists();
        if ($doubled) {
            throw new RuntimeException('A triage guideline version holds a yellow and a red limit for one reading; this migration cannot be rolled back.');
        }
        Schema::table('triage_guideline_thresholds', function (Blueprint $t): void {
            $t->dropUnique('triage_threshold_zone_unique');
            $t->unique(['guideline_version_id', 'reading', 'age_band', 'direction'], 'triage_threshold_unique');
        });
        if (DB::getDriverName() === 'pgsql') {
            // With one limit per direction no version can hold more than 28; the yellow-nearer-normal check is kept.
            DB::unprepared("ALTER TABLE triage_guideline_versions DROP CONSTRAINT triage_guideline_shape;
ALTER TABLE triage_guideline_versions ADD CONSTRAINT triage_guideline_shape CHECK (
 status IN ('draft','approved','replaced','discarded') AND lock_version > 0 AND version_number > 0
 AND child_age_limit_years BETWEEN 1 AND 18
 AND red_flag_count BETWEEN 1 AND 12 AND yellow_sign_count BETWEEN 0 AND 12 AND threshold_count BETWEEN 0 AND 28
 AND char_length(btrim(approving_doctor_name)) BETWEEN 3 AND 120
 AND ((approved_by_user_id IS NULL) = (approved_at IS NULL))
 AND (approved_by_user_id IS NULL OR approved_by_user_id <> entered_by_user_id)
 AND ((status IN ('approved','replaced')) = (approved_at IS NOT NULL))
 AND ((status = 'replaced') = (replaced_at IS NOT NULL))
 AND ((status = 'discarded') = (discarded_at IS NOT NULL))
 AND ((discarded_by_user_id IS NULL) = (discarded_at IS NULL)));");
        }
    }
};
