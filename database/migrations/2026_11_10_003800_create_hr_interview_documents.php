<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-17d interview documents (HR-PLAN 19.7; owner, 2026-11-10: "the function now, with dummy PDFs"). HR uploads PDFs
 * per position type (at most 10 MB each and 10 active per type), stored encrypted under a generated name with no
 * original file name. A candidate downloads them from their private page only while their interview is confirmed,
 * and each download is recorded before any byte is sent. A removal keeps the row and deletes the bytes; nothing is
 * deleted from the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_interview_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('position_type', 20);
            $t->string('title', 80);
            $t->string('stored_path', 200)->nullable(); // cleared when the document is removed and its bytes deleted
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('status', 10); // active | removed
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->timestampTz('uploaded_at');
            $t->unsignedBigInteger('removed_by_user_id')->nullable();
            $t->timestampTz('removed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->unique(['id', 'organisation_id'], 'hr_interview_docs_org_key');
            $t->foreign('organisation_id', 'hr_interview_docs_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['uploaded_by_user_id', 'organisation_id'], 'hr_interview_docs_uploader_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['removed_by_user_id', 'organisation_id'], 'hr_interview_docs_remover_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'position_type', 'status'], 'hr_interview_docs_type');
        });

        Schema::create('hr_interview_document_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('document_id');
            $t->unsignedBigInteger('application_id')->nullable(); // set for a candidate's download only
            $t->string('action', 12); // uploaded | opened | removed | downloaded
            $t->unsignedBigInteger('actor_user_id')->nullable(); // HR; empty for a candidate's download
            $t->timestampTz('created_at');
            $t->foreign(['document_id', 'organisation_id'], 'hr_interview_doc_events_doc_fk')->references(['id', 'organisation_id'])->on('hr_interview_documents')->restrictOnDelete();
            $t->foreign(['application_id', 'organisation_id'], 'hr_interview_doc_events_app_fk')->references(['id', 'organisation_id'])->on('hr_job_applications')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_interview_doc_events_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['application_id', 'document_id'], 'hr_interview_doc_events_app');
        });

        DB::statement("ALTER TABLE hr_interview_documents ADD CONSTRAINT hr_interview_docs_shape CHECK (
            position_type IN ('ca', 'intern', 'locum', 'resident_doctor')
            AND char_length(title) BETWEEN 3 AND 80 AND title !~ '[<>{}\\r\\n]'
            AND bytes BETWEEN 1 AND 10485760 AND sha256 ~ '^[0-9a-f]{64}$'
            AND status IN ('active', 'removed') AND lock_version >= 1
            AND ((status = 'active') = (stored_path IS NOT NULL AND removed_at IS NULL AND removed_by_user_id IS NULL))
            AND (status = 'active' OR (removed_at IS NOT NULL AND removed_by_user_id IS NOT NULL)))");
        // One active copy of the same file per position type.
        DB::statement("CREATE UNIQUE INDEX hr_interview_docs_one_copy ON hr_interview_documents (organisation_id, position_type, sha256) WHERE status = 'active'");
        DB::statement("ALTER TABLE hr_interview_document_events ADD CONSTRAINT hr_interview_doc_events_shape CHECK (
            action IN ('uploaded', 'opened', 'removed', 'downloaded')
            AND ((action = 'downloaded') = (application_id IS NOT NULL AND actor_user_id IS NULL))
            AND (action = 'downloaded' OR actor_user_id IS NOT NULL))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_interview_docs_no_delete BEFORE DELETE ON hr_interview_documents FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_interview_docs_truncate BEFORE TRUNCATE ON hr_interview_documents FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_interview_doc_events_append BEFORE UPDATE OR DELETE ON hr_interview_document_events FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_interview_doc_events_truncate BEFORE TRUNCATE ON hr_interview_document_events FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- At most 10 active documents per position type. The same per-type lock as the service, so two inserts count in turn
-- even when they bypass the service (Suresh, HR-17d m3).
CREATE OR REPLACE FUNCTION guard_hr_interview_document_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('hr_interview_documents:' || NEW.organisation_id || ':' || NEW.position_type, 0));
 IF NEW.status <> 'active' OR (SELECT count(*) FROM hr_interview_documents d WHERE d.organisation_id = NEW.organisation_id
     AND d.position_type = NEW.position_type AND d.status = 'active') >= 10 THEN
  RAISE EXCEPTION 'At most 10 interview documents per position type' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_interview_docs_insert_guard BEFORE INSERT ON hr_interview_documents FOR EACH ROW EXECUTE FUNCTION guard_hr_interview_document_insert();

-- A document keeps what was uploaded; the only change is its one removal.
CREATE OR REPLACE FUNCTION guard_hr_interview_document() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.position_type <> OLD.position_type
   OR NEW.title <> OLD.title OR NEW.bytes <> OLD.bytes OR NEW.sha256 <> OLD.sha256 OR NEW.uploaded_by_user_id <> OLD.uploaded_by_user_id
   OR NEW.uploaded_at <> OLD.uploaded_at OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR OLD.status <> 'active' OR NEW.status <> 'removed' OR NEW.stored_path IS NOT NULL OR NEW.lock_version <> OLD.lock_version + 1 THEN
  RAISE EXCEPTION 'An interview document keeps what was uploaded' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_interview_docs_guard BEFORE UPDATE ON hr_interview_documents FOR EACH ROW EXECUTE FUNCTION guard_hr_interview_document();

-- A candidate downloads only an active document of their own position type, while their interview is confirmed.
CREATE OR REPLACE FUNCTION guard_hr_interview_document_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.action = 'downloaded' AND NOT EXISTS (
     SELECT 1 FROM hr_interview_documents d
       JOIN hr_job_applications a ON a.id = NEW.application_id AND a.organisation_id = d.organisation_id
       JOIN hr_job_positions p ON p.id = a.position_id AND p.position_type = d.position_type
     WHERE d.id = NEW.document_id AND d.status = 'active' AND a.status = 'interview' AND a.purged_at IS NULL
       AND EXISTS (SELECT 1 FROM hr_job_interviews i WHERE i.application_id = a.id AND i.status = 'confirmed')) THEN
  RAISE EXCEPTION 'Interview documents are for a confirmed interview' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_interview_doc_events_insert_guard BEFORE INSERT ON hr_interview_document_events FOR EACH ROW EXECUTE FUNCTION guard_hr_interview_document_event();
SQL);
    }

    public function down(): void {} // Documents and their events are evidence; they are kept.
};
