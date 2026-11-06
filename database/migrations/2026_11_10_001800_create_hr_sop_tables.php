<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-5c SOPs (HR-PLAN 12.3 rule 8). An SOP is published as numbered PDF versions, each to chosen roles; a version is
 * never edited, only replaced by a new one. Each open is recorded, and "I have read and understood" is accepted only
 * after the person opened that version. Acknowledgements are append-only, and withdrawing an SOP keeps every one.
 * Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_sops', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('current_version');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedBigInteger('withdrawn_by_user_id')->nullable();
            $t->timestampTz('withdrawn_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_sops_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            foreach (['created_by_user_id', 'withdrawn_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_sops_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_sops_scope_unique');
        });

        Schema::create('hr_sop_versions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('sop_id');
            $t->unsignedInteger('version');
            $t->string('title', 120);
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 200);
            $t->unsignedBigInteger('published_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['sop_id', 'organisation_id'], 'hr_sop_versions_sop_fk')->references(['id', 'organisation_id'])->on('hr_sops')->restrictOnDelete();
            $t->foreign(['published_by_user_id', 'organisation_id'], 'hr_sop_versions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['sop_id', 'version'], 'hr_sop_versions_once');
            $t->unique(['id', 'organisation_id'], 'hr_sop_versions_scope_unique');
        });

        Schema::create('hr_sop_audiences', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('sop_version_id');
            $t->string('role', 40);
            $t->foreign('sop_version_id', 'hr_sop_audiences_version_fk')->references('id')->on('hr_sop_versions')->restrictOnDelete();
            $t->unique(['sop_version_id', 'role'], 'hr_sop_audiences_once');
        });

        Schema::create('hr_sop_opens', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('sop_version_id');
            $t->unsignedBigInteger('user_id');
            $t->timestampTz('created_at');
            $t->foreign(['sop_version_id', 'organisation_id'], 'hr_sop_opens_version_fk')->references(['id', 'organisation_id'])->on('hr_sop_versions')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_sop_opens_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['sop_version_id', 'user_id'], 'hr_sop_opens_lookup');
        });

        Schema::create('hr_sop_acknowledgements', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('sop_version_id');
            $t->unsignedBigInteger('user_id');
            $t->timestampTz('created_at');
            $t->foreign(['sop_version_id', 'organisation_id'], 'hr_sop_acks_version_fk')->references(['id', 'organisation_id'])->on('hr_sop_versions')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_sop_acks_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['sop_version_id', 'user_id'], 'hr_sop_acks_once');
        });

        DB::statement('ALTER TABLE hr_sops ADD CONSTRAINT hr_sops_shape CHECK (current_version >= 1 AND (is_active = (withdrawn_by_user_id IS NULL)) AND ((withdrawn_by_user_id IS NULL) = (withdrawn_at IS NULL)))');
        DB::statement("ALTER TABLE hr_sop_versions ADD CONSTRAINT hr_sop_versions_shape CHECK (version >= 1 AND char_length(btrim(title)) BETWEEN 3 AND 120 AND bytes > 0 AND sha256 ~ '^[0-9a-f]{64}$')");
        // Literal role names: this historical migration must not follow later code changes.
        DB::statement("ALTER TABLE hr_sop_audiences ADD CONSTRAINT hr_sop_audiences_role CHECK (role IN ('director', 'resident_doctor', 'ca', 'ca_supervisor', 'panel_officer', 'finance_officer', 'administrator', 'business_development', 'marketing', 'hr_manager', 'technical_admin'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_sop_versions_append BEFORE UPDATE OR DELETE ON hr_sop_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sop_audiences_append BEFORE UPDATE OR DELETE ON hr_sop_audiences FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sop_opens_append BEFORE UPDATE OR DELETE ON hr_sop_opens FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sop_acks_append BEFORE UPDATE OR DELETE ON hr_sop_acknowledgements FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sops_no_delete BEFORE DELETE ON hr_sops FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sops_truncate BEFORE TRUNCATE ON hr_sops FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sop_versions_truncate BEFORE TRUNCATE ON hr_sop_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sop_audiences_truncate BEFORE TRUNCATE ON hr_sop_audiences FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sop_opens_truncate BEFORE TRUNCATE ON hr_sop_opens FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_sop_acks_truncate BEFORE TRUNCATE ON hr_sop_acknowledgements FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- An SOP keeps who made it; its version only moves up, by one, to a version that exists; a withdrawn SOP stays withdrawn.
CREATE OR REPLACE FUNCTION guard_hr_sop() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'An SOP keeps who made it' USING ERRCODE = 'P0001';
 END IF;
 IF NOT OLD.is_active AND (NEW.is_active OR NEW.current_version <> OLD.current_version
   OR NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id OR NEW.withdrawn_at IS DISTINCT FROM OLD.withdrawn_at) THEN
  RAISE EXCEPTION 'A withdrawn SOP stays withdrawn' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.current_version <> OLD.current_version THEN
  IF NEW.current_version <> OLD.current_version + 1
    OR NOT EXISTS (SELECT 1 FROM hr_sop_versions WHERE sop_id = NEW.id AND version = NEW.current_version) THEN
   RAISE EXCEPTION 'An SOP moves to its next published version only' USING ERRCODE = 'P0001';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_sops_guard BEFORE UPDATE ON hr_sops FOR EACH ROW EXECUTE FUNCTION guard_hr_sop();

-- Versions are numbered without gaps, and a withdrawn SOP takes no new version.
CREATE OR REPLACE FUNCTION guard_hr_sop_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s RECORD; top integer;
BEGIN
 SELECT is_active INTO s FROM hr_sops WHERE id = NEW.sop_id;
 IF NOT s.is_active THEN RAISE EXCEPTION 'A withdrawn SOP takes no new version' USING ERRCODE = '23514'; END IF;
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_sop_versions WHERE sop_id = NEW.sop_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'SOP versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_sop_versions_guard BEFORE INSERT ON hr_sop_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_sop_version();

-- "I have read and understood" only for the SOP's current version, while it is not withdrawn, by someone who opened
-- that version first.
CREATE OR REPLACE FUNCTION guard_hr_sop_acknowledgement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v RECORD;
BEGIN
 SELECT sv.version, s.current_version, s.is_active INTO v FROM hr_sop_versions sv JOIN hr_sops s ON s.id = sv.sop_id WHERE sv.id = NEW.sop_version_id;
 IF NOT v.is_active OR v.version <> v.current_version THEN
  RAISE EXCEPTION 'Only the current version of a published SOP is acknowledged' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_sop_opens WHERE sop_version_id = NEW.sop_version_id AND user_id = NEW.user_id) THEN
  RAISE EXCEPTION 'An SOP is acknowledged only after it was opened' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_sop_acks_guard BEFORE INSERT ON hr_sop_acknowledgements FOR EACH ROW EXECUTE FUNCTION guard_hr_sop_acknowledgement();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
