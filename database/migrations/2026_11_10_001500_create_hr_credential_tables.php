<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-5a credential register (HR-PLAN 12.3). KPOne ships no credential type: HR enters them, and a type's name, warning
 * days and the roles that need it are kept as append-only versions. A person's credential is versioned too: each new
 * record waits until someone other than the person and other than whoever entered it accepts it, and only the newest
 * accepted version is in force. Health items hold only Done or Not done and a date. Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_credential_types', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('code', 16);
            // Fixed when the type is added; to change either, archive the type and add a new one.
            $t->boolean('expires');
            $t->boolean('health_item');
            $t->unsignedInteger('current_version');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_credential_types_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_credential_types_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_credential_types_scope_unique');
            $t->unique(['organisation_id', 'code'], 'hr_credential_types_code_unique');
        });

        Schema::create('hr_credential_type_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('type_id');
            $t->unsignedInteger('version');
            $t->string('name', 120);
            $t->unsignedSmallInteger('warn_from_days');
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['type_id', 'organisation_id'], 'hr_credential_type_versions_type_fk')->references(['id', 'organisation_id'])->on('hr_credential_types')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_credential_type_versions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['type_id', 'version'], 'hr_credential_type_versions_once');
        });

        Schema::create('hr_credential_type_roles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('type_version_id');
            $t->string('role', 40);
            $t->foreign('type_version_id', 'hr_credential_type_roles_version_fk')->references('id')->on('hr_credential_type_versions')->restrictOnDelete();
            $t->unique(['type_version_id', 'role'], 'hr_credential_type_roles_once');
        });

        Schema::create('hr_credential_records', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('type_id');
            $t->unsignedInteger('version');
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('issuing_body', 120)->nullable();
            $t->text('reference_encrypted')->nullable();
            $t->string('reference_last4', 4)->nullable();
            $t->unsignedBigInteger('document_id')->nullable(); // HR-4b vault, category Certificate
            $t->boolean('health_done')->nullable(); // health items only: Done or Not done
            $t->string('status', 10)->default('waiting'); // waiting | accepted | rejected | replaced
            $t->unsignedBigInteger('entered_by_user_id');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_credential_records_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['type_id', 'organisation_id'], 'hr_credential_records_type_fk')->references(['id', 'organisation_id'])->on('hr_credential_types')->restrictOnDelete();
            $t->foreign(['document_id', 'organisation_id'], 'hr_credential_records_document_fk')->references(['id', 'organisation_id'])->on('hr_documents')->restrictOnDelete();
            foreach (['entered_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_credential_records_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['worker_id', 'type_id', 'version'], 'hr_credential_records_version_once');
            $t->index(['worker_id', 'status'], 'hr_credential_records_worker');
        });

        DB::statement("ALTER TABLE hr_credential_types ADD CONSTRAINT hr_credential_types_shape CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,15}$' AND current_version >= 1 AND NOT (health_item AND expires))");
        DB::statement('ALTER TABLE hr_credential_type_versions ADD CONSTRAINT hr_credential_type_versions_shape CHECK (version >= 1 AND char_length(btrim(name)) BETWEEN 2 AND 120 AND warn_from_days BETWEEN 1 AND 365)');
        DB::statement(<<<'SQL'
ALTER TABLE hr_credential_records ADD CONSTRAINT hr_credential_records_shape CHECK (
 version >= 1
 AND status IN ('waiting', 'accepted', 'rejected', 'replaced')
 AND (status = 'waiting') = (decided_by_user_id IS NULL)
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (status = 'rejected') = (decision_reason IS NOT NULL)
 AND (decision_reason IS NULL OR decision_reason IN ('wrong_dates', 'not_readable', 'wrong_person', 'other'))
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> entered_by_user_id)
 AND (expires_on IS NULL OR issued_on IS NULL OR expires_on > issued_on)
 AND (reference_encrypted IS NULL) = (reference_last4 IS NULL)
)
SQL);
        // One version in force, and at most one waiting, per person and type.
        DB::statement("CREATE UNIQUE INDEX hr_credential_records_one_accepted ON hr_credential_records (worker_id, type_id) WHERE status = 'accepted'");
        DB::statement("CREATE UNIQUE INDEX hr_credential_records_one_waiting ON hr_credential_records (worker_id, type_id) WHERE status = 'waiting'");

        // The HR-4a change list gains the HR-5a area (field names only, as before).
        DB::statement('ALTER TABLE hr_record_changes DROP CONSTRAINT hr_record_changes_shape');
        DB::statement("ALTER TABLE hr_record_changes ADD CONSTRAINT hr_record_changes_shape CHECK (area IN ('worker', 'employment', 'position', 'personal', 'bank', 'correction', 'document', 'checklist', 'credential') AND jsonb_typeof(fields) = 'array')");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_credential_type_versions_append BEFORE UPDATE OR DELETE ON hr_credential_type_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_credential_type_roles_append BEFORE UPDATE OR DELETE ON hr_credential_type_roles FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_credential_types_no_delete BEFORE DELETE ON hr_credential_types FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_credential_records_no_delete BEFORE DELETE ON hr_credential_records FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_credential_types_truncate BEFORE TRUNCATE ON hr_credential_types FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_credential_type_versions_truncate BEFORE TRUNCATE ON hr_credential_type_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_credential_type_roles_truncate BEFORE TRUNCATE ON hr_credential_type_roles FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_credential_records_truncate BEFORE TRUNCATE ON hr_credential_records FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A type keeps its code and its expires and health settings; only the current version and the active flag move.
CREATE OR REPLACE FUNCTION guard_hr_credential_type() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.code <> OLD.code
   OR NEW.expires <> OLD.expires OR NEW.health_item <> OLD.health_item OR NEW.created_by_user_id <> OLD.created_by_user_id
   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A credential type keeps its code and settings; archive it and add a new one' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.current_version < OLD.current_version THEN
  RAISE EXCEPTION 'A credential type version never goes back' USING ERRCODE = 'P0001';
 END IF;
 IF NOT OLD.is_active AND NEW.is_active THEN
  RAISE EXCEPTION 'An archived credential type stays archived' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_credential_types_guard BEFORE UPDATE ON hr_credential_types FOR EACH ROW EXECUTE FUNCTION guard_hr_credential_type();

-- A record matches its type (health items: Done or Not done and a date, nothing else), keeps what was entered, moves
-- waiting -> accepted | rejected and accepted -> replaced, and is never decided by the person it is about.
CREATE OR REPLACE FUNCTION guard_hr_credential_record() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE ty RECORD; w RECORD; d RECORD; top integer;
BEGIN
 SELECT organisation_id, user_id INTO w FROM hr_workers WHERE id = NEW.worker_id;
 IF TG_OP = 'INSERT' THEN
  SELECT organisation_id, expires, health_item, is_active INTO ty FROM hr_credential_types WHERE id = NEW.type_id;
  IF ty.organisation_id IS DISTINCT FROM NEW.organisation_id OR w.organisation_id IS DISTINCT FROM NEW.organisation_id THEN
   RAISE EXCEPTION 'A credential stays inside one organisation' USING ERRCODE = '23514';
  END IF;
  IF NOT ty.is_active THEN RAISE EXCEPTION 'An archived credential type takes no new records' USING ERRCODE = '23514'; END IF;
  IF NEW.status <> 'waiting' THEN RAISE EXCEPTION 'A credential record starts as waiting for check' USING ERRCODE = '23514'; END IF;
  IF ty.health_item THEN
   IF NEW.health_done IS NULL OR NEW.expires_on IS NOT NULL OR NEW.issuing_body IS NOT NULL OR NEW.reference_encrypted IS NOT NULL
     OR NEW.document_id IS NOT NULL OR (NEW.health_done AND NEW.issued_on IS NULL) OR (NOT NEW.health_done AND NEW.issued_on IS NOT NULL) THEN
    RAISE EXCEPTION 'A health item holds only Done or Not done and a date' USING ERRCODE = '23514';
   END IF;
  ELSE
   IF NEW.health_done IS NOT NULL OR NEW.issued_on IS NULL OR (ty.expires AND NEW.expires_on IS NULL) OR (NOT ty.expires AND NEW.expires_on IS NOT NULL) THEN
    RAISE EXCEPTION 'A credential has an issue date, and an expiry date only when its type expires' USING ERRCODE = '23514';
   END IF;
  END IF;
  IF NEW.document_id IS NOT NULL THEN
   SELECT worker_id, category INTO d FROM hr_documents WHERE id = NEW.document_id;
   IF d.worker_id IS DISTINCT FROM NEW.worker_id OR d.category <> 'certificate' THEN
    RAISE EXCEPTION 'Evidence is a certificate in the same staff file' USING ERRCODE = '23514';
   END IF;
  END IF;
  SELECT COALESCE(MAX(version), 0) INTO top FROM hr_credential_records WHERE worker_id = NEW.worker_id AND type_id = NEW.type_id;
  IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Credential versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
  IF w.user_id IS NOT NULL AND NEW.decided_by_user_id IS NOT DISTINCT FROM w.user_id THEN
   RAISE EXCEPTION 'Nobody checks their own credential' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.type_id <> OLD.type_id OR NEW.version <> OLD.version OR NEW.issued_on IS DISTINCT FROM OLD.issued_on
   OR NEW.expires_on IS DISTINCT FROM OLD.expires_on OR NEW.issuing_body IS DISTINCT FROM OLD.issuing_body
   OR NEW.reference_encrypted IS DISTINCT FROM OLD.reference_encrypted OR NEW.reference_last4 IS DISTINCT FROM OLD.reference_last4
   OR NEW.document_id IS DISTINCT FROM OLD.document_id OR NEW.health_done IS DISTINCT FROM OLD.health_done
   OR NEW.entered_by_user_id <> OLD.entered_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A credential record keeps what was entered' USING ERRCODE = 'P0001';
 END IF;
 IF NOT ((OLD.status = 'waiting' AND NEW.status IN ('accepted', 'rejected')) OR (OLD.status = 'accepted' AND NEW.status = 'replaced')) THEN
  RAISE EXCEPTION 'A credential record moves waiting to accepted or rejected, and accepted to replaced' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'accepted' AND (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id OR NEW.decided_at IS DISTINCT FROM OLD.decided_at) THEN
  RAISE EXCEPTION 'A replaced record keeps who accepted it' USING ERRCODE = 'P0001';
 END IF;
 IF w.user_id IS NOT NULL AND NEW.decided_by_user_id = w.user_id THEN
  RAISE EXCEPTION 'Nobody checks their own credential' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_credential_records_guard BEFORE INSERT OR UPDATE ON hr_credential_records FOR EACH ROW EXECUTE FUNCTION guard_hr_credential_record();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
