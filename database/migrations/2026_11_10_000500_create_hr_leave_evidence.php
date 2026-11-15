<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-2b leave evidence files (owner, 2026-11-06: "approve the note with the defaults"). A photo or PDF of an MC
 * attached to a leave request, stored encrypted on the private disk; at most 3 active files per request; files are
 * removed or purged, never deleted as rows; every add, open, removal and purge is an append-only event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $t): void {
            $t->boolean('accepts_evidence_files')->default(false);
            $t->unsignedSmallInteger('evidence_retention_days')->nullable();
        });
        DB::statement('ALTER TABLE hr_leave_types ADD CONSTRAINT hr_leave_types_evidence_shape CHECK ((evidence_retention_days IS NULL OR evidence_retention_days BETWEEN 1 AND 3650) AND (NOT accepts_evidence_files OR evidence_retention_days IS NOT NULL))');

        Schema::create('hr_leave_evidence_files', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('leave_request_id');
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->boolean('on_behalf');
            $t->string('kind', 8); // jpeg | png | pdf
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 200);
            $t->string('status', 8)->default('active'); // active | removed | purged
            $t->unsignedBigInteger('removed_by_user_id')->nullable();
            $t->timestampTz('removed_at')->nullable();
            $t->string('removal_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('leave_request_id', 'hr_leave_evidence_files_request_fk')->references('id')->on('hr_leave_requests')->restrictOnDelete();
            $t->foreign(['uploaded_by_user_id', 'organisation_id'], 'hr_leave_evidence_files_uploader_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['removed_by_user_id', 'organisation_id'], 'hr_leave_evidence_files_remover_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['leave_request_id', 'status'], 'hr_leave_evidence_files_request_status');
        });

        Schema::create('hr_leave_evidence_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('evidence_file_id');
            $t->string('action', 8); // added | opened | removed | purged
            $t->unsignedBigInteger('actor_user_id')->nullable(); // null only for the scheduled purge
            $t->timestampTz('occurred_at');
            $t->foreign('evidence_file_id', 'hr_leave_evidence_events_file_fk')->references('id')->on('hr_leave_evidence_files')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_leave_evidence_events_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['evidence_file_id', 'occurred_at'], 'hr_leave_evidence_events_file_time');
        });

        DB::statement(<<<'SQL'
ALTER TABLE hr_leave_evidence_files ADD CONSTRAINT hr_leave_evidence_files_shape CHECK (
 kind IN ('jpeg', 'png', 'pdf') AND bytes BETWEEN 1 AND 5242880 AND sha256 ~ '^[0-9a-f]{64}$'
 AND status IN ('active', 'removed', 'purged')
 AND (removal_reason IS NULL OR removal_reason IN ('withdrawn', 'wrong_file', 'wrong_person', 'not_readable'))
 AND (status <> 'removed' OR (removed_by_user_id IS NOT NULL AND removed_at IS NOT NULL AND removal_reason IS NOT NULL))
 AND (status <> 'purged' OR removed_at IS NOT NULL)
 AND (status <> 'active' OR (removed_by_user_id IS NULL AND removed_at IS NULL AND removal_reason IS NULL))
)
SQL);
        DB::statement("ALTER TABLE hr_leave_evidence_events ADD CONSTRAINT hr_leave_evidence_events_shape CHECK (action IN ('added', 'opened', 'removed', 'purged') AND (actor_user_id IS NOT NULL OR action = 'purged'))");
        DB::statement("CREATE UNIQUE INDEX hr_leave_evidence_files_one_copy ON hr_leave_evidence_files (leave_request_id, sha256) WHERE status = 'active'");

        DB::unprepared(<<<'SQL'
-- A file is added only to a pending or approved request of the same organisation, as one of at most 3 active files.
CREATE OR REPLACE FUNCTION guard_hr_leave_evidence_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE req RECORD;
BEGIN
 IF NEW.status <> 'active' THEN RAISE EXCEPTION 'An evidence file starts as active' USING ERRCODE = '23514'; END IF;
 SELECT organisation_id, status INTO req FROM hr_leave_requests WHERE id = NEW.leave_request_id FOR UPDATE;
 IF req.organisation_id IS DISTINCT FROM NEW.organisation_id THEN
  RAISE EXCEPTION 'Evidence must belong to a request of the same organisation' USING ERRCODE = '23514';
 END IF;
 IF req.status NOT IN ('pending', 'approved') THEN
  RAISE EXCEPTION 'Evidence is added only to a pending or approved request' USING ERRCODE = '23514';
 END IF;
 IF (SELECT count(*) FROM hr_leave_evidence_files WHERE leave_request_id = NEW.leave_request_id AND status = 'active') >= 3 THEN
  RAISE EXCEPTION 'At most 3 evidence files per request' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_leave_evidence_files_insert BEFORE INSERT ON hr_leave_evidence_files FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_evidence_insert();

-- Rows are never deleted; an active file moves once to removed or purged, and nothing else about it ever changes.
CREATE OR REPLACE FUNCTION guard_hr_leave_evidence_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'HR leave evidence records are never deleted' USING ERRCODE = 'P0001'; END IF;
 IF OLD.status <> 'active' OR NEW.status NOT IN ('removed', 'purged') THEN
  RAISE EXCEPTION 'An evidence file only moves once from active to removed or purged' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.leave_request_id <> OLD.leave_request_id
   OR NEW.uploaded_by_user_id <> OLD.uploaded_by_user_id OR NEW.on_behalf <> OLD.on_behalf OR NEW.kind <> OLD.kind OR NEW.bytes <> OLD.bytes
   OR NEW.sha256 <> OLD.sha256 OR NEW.stored_path <> OLD.stored_path OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'HR leave evidence identity is immutable' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_leave_evidence_files_guard BEFORE UPDATE OR DELETE ON hr_leave_evidence_files FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_evidence_update();
CREATE TRIGGER hr_leave_evidence_files_truncate BEFORE TRUNCATE ON hr_leave_evidence_files FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();
-- An event belongs to a file of its own organisation (Suresh).
CREATE OR REPLACE FUNCTION guard_hr_leave_evidence_event_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (SELECT organisation_id FROM hr_leave_evidence_files WHERE id = NEW.evidence_file_id) IS DISTINCT FROM NEW.organisation_id THEN
  RAISE EXCEPTION 'An evidence event must belong to a file of the same organisation' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_leave_evidence_events_insert BEFORE INSERT ON hr_leave_evidence_events FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_evidence_event_insert();
CREATE TRIGGER hr_leave_evidence_events_append BEFORE UPDATE OR DELETE ON hr_leave_evidence_events FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_evidence_events_truncate BEFORE TRUNCATE ON hr_leave_evidence_events FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();
SQL);
    }

    public function down(): void
    {
        // Leave evidence records are retained, following the evidence convention.
    }
};
