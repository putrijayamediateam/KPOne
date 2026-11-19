<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TRI-1a (owner decisions 19 Nov 2026, TRI-1-DESIGN section 4.2): versioned triage guideline settings. KPOne ships no
 * clinical list. A version is entered as a Draft with its red flags, yellow signs and thresholds in one transaction and
 * counts only once someone other than its entrant approves it. PostgreSQL refuses an approved insert, self-approval,
 * a retired version without a successor, later sign or threshold inserts, and any delete or truncate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('triage_guideline_versions')) {
            return;
        }
        Schema::create('triage_guideline_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version_number');
            $t->string('status', 20)->default('draft');
            $t->unsignedSmallInteger('child_age_limit_years');
            $t->string('approving_doctor_name', 120);
            $t->date('doctor_signed_on');
            $t->unsignedSmallInteger('red_flag_count');
            $t->unsignedSmallInteger('yellow_sign_count');
            $t->unsignedSmallInteger('threshold_count');
            $t->foreignId('entered_by_user_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('entered_at');
            $t->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('replaced_at')->nullable();
            $t->foreignId('discarded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('discarded_at')->nullable();
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->unique(['organisation_id', 'version_number']);
            $t->unique(['organisation_id', 'entered_by_user_id', 'idempotency_key'], 'triage_guideline_retry_unique');
        });
        Schema::create('triage_guideline_signs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guideline_version_id')->constrained('triage_guideline_versions')->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('code', 40);
            $t->string('label', 160);
            $t->unsignedSmallInteger('position');
            $t->timestampTz('created_at');
            $t->unique(['guideline_version_id', 'code']);
            $t->unique(['guideline_version_id', 'kind', 'position']);
        });
        Schema::create('triage_guideline_thresholds', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guideline_version_id')->constrained('triage_guideline_versions')->restrictOnDelete();
            $t->string('reading', 30);
            $t->string('age_band', 10);
            $t->string('direction', 10);
            $t->decimal('limit_value', 7, 2);
            $t->string('zone', 10);
            $t->timestampTz('created_at');
            $t->unique(['guideline_version_id', 'reading', 'age_band', 'direction'], 'triage_threshold_unique');
        });
        Schema::create('triage_guideline_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('guideline_version_id')->constrained('triage_guideline_versions')->restrictOnDelete();
            $t->string('event_type', 20);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('record_version');
            $t->timestampTz('occurred_at');
            $t->unique(['guideline_version_id', 'record_version']);
        });
        Schema::create('triage_guideline_commands', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $t->uuid('retry_key');
            $t->char('payload_hash', 64);
            $t->json('result');
            $t->timestampTz('occurred_at');
            $t->unique(['organisation_id', 'actor_user_id', 'retry_key'], 'triage_guideline_command_unique');
        });
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
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
 AND ((discarded_by_user_id IS NULL) = (discarded_at IS NULL)));
CREATE UNIQUE INDEX triage_guideline_one_in_force ON triage_guideline_versions(organisation_id) WHERE status = 'approved';
CREATE UNIQUE INDEX triage_guideline_one_draft ON triage_guideline_versions(organisation_id) WHERE status = 'draft';
ALTER TABLE triage_guideline_signs ADD CONSTRAINT triage_guideline_sign_shape CHECK (
 kind IN ('red_flag','yellow_sign') AND position BETWEEN 1 AND 12
 AND code ~ '^[a-z][a-z0-9_]{1,39}$' AND char_length(btrim(label)) BETWEEN 3 AND 160);
ALTER TABLE triage_guideline_thresholds ADD CONSTRAINT triage_guideline_threshold_shape CHECK (
 reading IN ('systolic_bp','diastolic_bp','pulse_bpm','temperature_celsius','spo2_percent','weight_kg','height_cm')
 AND age_band IN ('adult','child') AND direction IN ('very_low','very_high') AND zone IN ('red','yellow')
 AND limit_value > 0);
ALTER TABLE triage_guideline_events ADD CONSTRAINT triage_guideline_event_shape CHECK (
 event_type IN ('entered','approved','replaced','discarded') AND record_version > 0
 AND (actor_user_id IS NOT NULL OR event_type = 'replaced'));

CREATE OR REPLACE FUNCTION kp_triage_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Triage evidence is append-only' USING ERRCODE = 'P0001'; END $$;

CREATE OR REPLACE FUNCTION kp_triage_manager(org bigint, uid bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM users u WHERE u.id = uid AND u.organisation_id = org AND u.is_active)
 AND (EXISTS (SELECT 1 FROM model_has_roles mr JOIN role_has_permissions rp ON rp.role_id = mr.role_id
              JOIN permissions p ON p.id = rp.permission_id
              WHERE mr.model_id = uid AND p.name = 'triage.guideline.manage.organisation')
   OR EXISTS (SELECT 1 FROM model_has_permissions mp JOIN permissions p ON p.id = mp.permission_id
              WHERE mp.model_id = uid AND p.name = 'triage.guideline.manage.organisation'))
$$;

CREATE OR REPLACE FUNCTION kp_triage_guideline_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE latest integer;
BEGIN
 PERFORM 1 FROM organisations WHERE id = NEW.organisation_id FOR UPDATE;
 SELECT COALESCE(max(version_number), 0) INTO latest FROM triage_guideline_versions WHERE organisation_id = NEW.organisation_id;
 IF NEW.status <> 'draft' OR NEW.lock_version <> 1 OR NEW.version_number <> latest + 1
  OR NEW.approved_by_user_id IS NOT NULL OR NEW.approved_at IS NOT NULL OR NEW.replaced_at IS NOT NULL
  OR NEW.discarded_by_user_id IS NOT NULL OR NEW.discarded_at IS NOT NULL
  OR NOT kp_triage_manager(NEW.organisation_id, NEW.entered_by_user_id) THEN
  RAISE EXCEPTION 'A triage guideline version starts as a numbered Draft entered by a guideline manager' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER triage_guideline_insert BEFORE INSERT ON triage_guideline_versions FOR EACH ROW EXECUTE FUNCTION kp_triage_guideline_insert();

CREATE OR REPLACE FUNCTION kp_triage_guideline_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT ((OLD.status = 'draft' AND NEW.status IN ('approved','discarded')) OR (OLD.status = 'approved' AND NEW.status = 'replaced'))
  OR NEW.lock_version <> OLD.lock_version + 1
  OR (NEW.id, NEW.organisation_id, NEW.version_number, NEW.child_age_limit_years, NEW.approving_doctor_name, NEW.doctor_signed_on,
      NEW.red_flag_count, NEW.yellow_sign_count, NEW.threshold_count, NEW.entered_by_user_id, NEW.entered_at,
      NEW.idempotency_key, NEW.payload_hash, NEW.created_at)
     IS DISTINCT FROM
     (OLD.id, OLD.organisation_id, OLD.version_number, OLD.child_age_limit_years, OLD.approving_doctor_name, OLD.doctor_signed_on,
      OLD.red_flag_count, OLD.yellow_sign_count, OLD.threshold_count, OLD.entered_by_user_id, OLD.entered_at,
      OLD.idempotency_key, OLD.payload_hash, OLD.created_at)
  OR (OLD.status = 'approved' AND (NEW.approved_by_user_id, NEW.approved_at) IS DISTINCT FROM (OLD.approved_by_user_id, OLD.approved_at))
  OR (NEW.status = 'approved' AND (NEW.approved_by_user_id = NEW.entered_by_user_id OR NOT kp_triage_manager(NEW.organisation_id, NEW.approved_by_user_id)))
  OR (NEW.status = 'discarded' AND NOT kp_triage_manager(NEW.organisation_id, NEW.discarded_by_user_id)) THEN
  RAISE EXCEPTION 'Invalid triage guideline change' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER triage_guideline_update BEFORE UPDATE ON triage_guideline_versions FOR EACH ROW EXECUTE FUNCTION kp_triage_guideline_update();
CREATE TRIGGER triage_guideline_delete BEFORE DELETE ON triage_guideline_versions FOR EACH ROW EXECUTE FUNCTION kp_triage_immutable();
CREATE TRIGGER triage_guideline_truncate BEFORE TRUNCATE ON triage_guideline_versions FOR EACH STATEMENT EXECUTE FUNCTION kp_triage_immutable();

CREATE OR REPLACE FUNCTION kp_triage_guideline_consistency() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v record;
BEGIN
 SELECT * INTO v FROM triage_guideline_versions WHERE id = NEW.id;
 IF NOT EXISTS (SELECT 1 FROM triage_guideline_events e WHERE e.guideline_version_id = v.id AND e.record_version = v.lock_version
   AND e.event_type = CASE v.status WHEN 'draft' THEN 'entered' ELSE v.status END) THEN
  RAISE EXCEPTION 'A triage guideline change requires its event' USING ERRCODE = '23514';
 END IF;
 IF v.status = 'replaced' AND NOT EXISTS (SELECT 1 FROM triage_guideline_versions s WHERE s.organisation_id = v.organisation_id
   AND s.id <> v.id AND s.status IN ('approved','replaced') AND s.approved_at = v.replaced_at) THEN
  RAISE EXCEPTION 'A triage guideline version is replaced only by approving its successor' USING ERRCODE = '23514';
 END IF;
 IF (SELECT count(*) FROM triage_guideline_signs WHERE guideline_version_id = v.id AND kind = 'red_flag') <> v.red_flag_count
  OR (SELECT count(*) FROM triage_guideline_signs WHERE guideline_version_id = v.id AND kind = 'yellow_sign') <> v.yellow_sign_count
  OR (SELECT count(*) FROM triage_guideline_thresholds WHERE guideline_version_id = v.id) <> v.threshold_count THEN
  RAISE EXCEPTION 'A triage guideline version must hold exactly its entered signs and thresholds' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER triage_guideline_consistency AFTER INSERT OR UPDATE ON triage_guideline_versions
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kp_triage_guideline_consistency();

CREATE OR REPLACE FUNCTION kp_triage_guideline_part_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM triage_guideline_versions WHERE id = NEW.guideline_version_id AND status = 'draft') THEN
  RAISE EXCEPTION 'Signs and thresholds belong to a Draft triage guideline version' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION kp_triage_guideline_part_count() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v record;
BEGIN
 SELECT * INTO v FROM triage_guideline_versions WHERE id = NEW.guideline_version_id;
 IF (SELECT count(*) FROM triage_guideline_signs WHERE guideline_version_id = v.id AND kind = 'red_flag') <> v.red_flag_count
  OR (SELECT count(*) FROM triage_guideline_signs WHERE guideline_version_id = v.id AND kind = 'yellow_sign') <> v.yellow_sign_count
  OR (SELECT count(*) FROM triage_guideline_thresholds WHERE guideline_version_id = v.id) <> v.threshold_count THEN
  RAISE EXCEPTION 'Signs and thresholds are written only with their triage guideline version' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
SQL);
        foreach (['triage_guideline_signs', 'triage_guideline_thresholds'] as $table) {
            DB::unprepared(<<<SQL
CREATE TRIGGER {$table}_draft BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION kp_triage_guideline_part_insert();
CREATE CONSTRAINT TRIGGER {$table}_count AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kp_triage_guideline_part_count();
SQL);
        }
        foreach (['triage_guideline_signs', 'triage_guideline_thresholds', 'triage_guideline_events', 'triage_guideline_commands'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION kp_triage_immutable(); CREATE TRIGGER {$table}_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION kp_triage_immutable();");
        }
    }

    public function down(): void {} // Guideline evidence is retained.
};
