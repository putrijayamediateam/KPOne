<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-11a clinic licences (docs/teams/hr/HR-11-DESIGN.md section 5). KPOne ships no licence type: the clinic enters
 * them. A licence is one series per type and branch (or the whole clinic); each renewal is an append-only version, and
 * a wrong version is marked entered in error in its own append-only row, never edited or deleted. Scans and photos are
 * rows pointing at encrypted private-disk files; at most 3 per version. Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_licence_types', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('name', 120);
            // Fixed when the type is added; to change it, archive the type and add a new one.
            $t->boolean('expires');
            $t->unsignedSmallInteger('warn_from_days')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'compliance_licence_types_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'compliance_licence_types_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'compliance_licence_types_scope_unique');
        });

        Schema::create('compliance_licences', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('type_id');
            $t->unsignedBigInteger('branch_id')->nullable(); // null: the whole clinic
            $t->unsignedInteger('current_version')->default(1);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['type_id', 'organisation_id'], 'compliance_licences_type_fk')->references(['id', 'organisation_id'])->on('compliance_licence_types')->restrictOnDelete();
            $t->foreign('branch_id', 'compliance_licences_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'compliance_licences_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'compliance_licences_scope_unique');
        });
        DB::statement('CREATE UNIQUE INDEX compliance_licences_one_series ON compliance_licences (organisation_id, type_id, COALESCE(branch_id, 0))');

        Schema::create('compliance_licence_versions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('licence_id');
            $t->unsignedInteger('version');
            $t->text('reference_encrypted')->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->text('note_encrypted')->nullable();
            $t->unsignedBigInteger('entered_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['licence_id', 'organisation_id'], 'compliance_licence_versions_licence_fk')->references(['id', 'organisation_id'])->on('compliance_licences')->restrictOnDelete();
            $t->foreign(['entered_by_user_id', 'organisation_id'], 'compliance_licence_versions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['licence_id', 'version'], 'compliance_licence_versions_once');
            $t->unique(['id', 'organisation_id'], 'compliance_licence_versions_scope_unique');
        });

        Schema::create('compliance_licence_corrections', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('version_id');
            $t->string('reason_code', 16);
            $t->unsignedBigInteger('corrected_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['version_id', 'organisation_id'], 'compliance_licence_corrections_version_fk')->references(['id', 'organisation_id'])->on('compliance_licence_versions')->restrictOnDelete();
            $t->foreign(['corrected_by_user_id', 'organisation_id'], 'compliance_licence_corrections_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique('version_id', 'compliance_licence_corrections_once');
        });

        Schema::create('compliance_licence_files', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('version_id');
            $t->string('label', 20); // "Scan 1": the original file name is never kept
            $t->string('kind', 8);
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 120);
            $t->unsignedBigInteger('added_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['version_id', 'organisation_id'], 'compliance_licence_files_version_fk')->references(['id', 'organisation_id'])->on('compliance_licence_versions')->restrictOnDelete();
            $t->foreign(['added_by_user_id', 'organisation_id'], 'compliance_licence_files_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['version_id', 'label'], 'compliance_licence_files_label_once');
        });

        Schema::create('compliance_retries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->uuid('idempotency_key');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('action', 40);
            $t->char('payload_hash', 64);
            $t->string('result', 64);
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'compliance_retries_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'compliance_retries_once');
        });

        DB::statement('ALTER TABLE compliance_licence_types ADD CONSTRAINT compliance_licence_types_shape CHECK (char_length(btrim(name)) BETWEEN 2 AND 120 AND (expires = (warn_from_days IS NOT NULL)) AND (warn_from_days IS NULL OR warn_from_days BETWEEN 1 AND 365))');
        DB::statement('ALTER TABLE compliance_licences ADD CONSTRAINT compliance_licences_shape CHECK (current_version >= 1)');
        DB::statement('ALTER TABLE compliance_licence_versions ADD CONSTRAINT compliance_licence_versions_shape CHECK (version >= 1 AND (reference_encrypted IS NULL OR reference_encrypted <> \'\') AND (expires_on IS NULL OR issued_on IS NULL OR expires_on > issued_on))');
        DB::statement("ALTER TABLE compliance_licence_corrections ADD CONSTRAINT compliance_licence_corrections_shape CHECK (reason_code IN ('wrong_dates', 'wrong_licence', 'duplicate', 'other'))");
        DB::statement("ALTER TABLE compliance_licence_files ADD CONSTRAINT compliance_licence_files_shape CHECK (kind IN ('jpeg', 'png', 'pdf') AND bytes > 0 AND sha256 ~ '^[0-9a-f]{64}$' AND label ~ '^(Scan|Photo) [1-3]$')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_compliance_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Clinic compliance records are kept: they are never changed, deleted or truncated' USING ERRCODE = 'P0001';
END $$;
CREATE TRIGGER compliance_licence_versions_append BEFORE UPDATE OR DELETE ON compliance_licence_versions FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licence_corrections_append BEFORE UPDATE OR DELETE ON compliance_licence_corrections FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licence_files_append BEFORE UPDATE OR DELETE ON compliance_licence_files FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_retries_append BEFORE UPDATE OR DELETE ON compliance_retries FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licence_types_no_delete BEFORE DELETE ON compliance_licence_types FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licences_no_delete BEFORE DELETE ON compliance_licences FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licence_types_truncate BEFORE TRUNCATE ON compliance_licence_types FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licences_truncate BEFORE TRUNCATE ON compliance_licences FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licence_versions_truncate BEFORE TRUNCATE ON compliance_licence_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licence_corrections_truncate BEFORE TRUNCATE ON compliance_licence_corrections FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_licence_files_truncate BEFORE TRUNCATE ON compliance_licence_files FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_retries_truncate BEFORE TRUNCATE ON compliance_retries FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();

-- A type keeps whether it expires; only its name, warning days and active flag move, and an archived type stays archived.
CREATE OR REPLACE FUNCTION guard_compliance_licence_type() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.expires <> OLD.expires
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A licence type keeps whether it expires; archive it and add a new one' USING ERRCODE = 'P0001';
 END IF;
 IF NOT OLD.is_active AND NEW.is_active THEN
  RAISE EXCEPTION 'An archived licence type stays archived' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_licence_types_guard BEFORE UPDATE ON compliance_licence_types FOR EACH ROW EXECUTE FUNCTION guard_compliance_licence_type();

-- A series keeps its type and branch; only the current version number moves, upwards by one.
CREATE OR REPLACE FUNCTION guard_compliance_licence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ty RECORD; br RECORD;
BEGIN
 IF TG_OP = 'INSERT' THEN
  SELECT organisation_id, is_active INTO ty FROM compliance_licence_types WHERE id = NEW.type_id;
  IF ty.organisation_id IS DISTINCT FROM NEW.organisation_id THEN RAISE EXCEPTION 'A licence stays inside one organisation' USING ERRCODE = '23514'; END IF;
  IF NOT ty.is_active THEN RAISE EXCEPTION 'An archived licence type takes no new licences' USING ERRCODE = '23514'; END IF;
  IF NEW.branch_id IS NOT NULL THEN
   SELECT organisation_id INTO br FROM branches WHERE id = NEW.branch_id;
   IF br.organisation_id IS DISTINCT FROM NEW.organisation_id THEN RAISE EXCEPTION 'A licence branch belongs to the same organisation' USING ERRCODE = '23514'; END IF;
  END IF;
  IF NEW.current_version <> 1 THEN RAISE EXCEPTION 'A licence series starts at version 1' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.type_id <> OLD.type_id
   OR NEW.branch_id IS DISTINCT FROM OLD.branch_id OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A licence keeps its type and branch' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.current_version <> OLD.current_version AND NEW.current_version <> OLD.current_version + 1 THEN
  RAISE EXCEPTION 'A licence version moves up by one' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_licences_guard BEFORE INSERT OR UPDATE ON compliance_licences FOR EACH ROW EXECUTE FUNCTION guard_compliance_licence();

-- A version is numbered without gaps; an expiry date is needed exactly when the type expires.
CREATE OR REPLACE FUNCTION guard_compliance_licence_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE lic RECORD; ty RECORD; top integer;
BEGIN
 SELECT type_id, organisation_id INTO lic FROM compliance_licences WHERE id = NEW.licence_id;
 SELECT expires INTO ty FROM compliance_licence_types WHERE id = lic.type_id;
 IF (ty.expires AND NEW.expires_on IS NULL) OR (NOT ty.expires AND NEW.expires_on IS NOT NULL) THEN
  RAISE EXCEPTION 'A licence has an expiry date exactly when its type expires' USING ERRCODE = '23514';
 END IF;
 SELECT COALESCE(MAX(version), 0) INTO top FROM compliance_licence_versions WHERE licence_id = NEW.licence_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Licence versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_licence_versions_guard BEFORE INSERT ON compliance_licence_versions FOR EACH ROW EXECUTE FUNCTION guard_compliance_licence_version();

-- At most 3 files per version.
CREATE OR REPLACE FUNCTION guard_compliance_licence_file() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (SELECT COUNT(*) FROM compliance_licence_files WHERE version_id = NEW.version_id) >= 3 THEN
  RAISE EXCEPTION 'A licence version holds at most 3 files' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_licence_files_guard BEFORE INSERT ON compliance_licence_files FOR EACH ROW EXECUTE FUNCTION guard_compliance_licence_file();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_retries');
        Schema::dropIfExists('compliance_licence_files');
        Schema::dropIfExists('compliance_licence_corrections');
        Schema::dropIfExists('compliance_licence_versions');
        Schema::dropIfExists('compliance_licences');
        Schema::dropIfExists('compliance_licence_types');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_compliance_licence_file(); DROP FUNCTION IF EXISTS guard_compliance_licence_version(); DROP FUNCTION IF EXISTS guard_compliance_licence(); DROP FUNCTION IF EXISTS guard_compliance_licence_type(); DROP FUNCTION IF EXISTS guard_compliance_append_only();');
    }
};
