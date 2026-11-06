<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-4b documents and checklists (HR-PLAN 11.3 rules 10 and 11). Documents are checked, re-encoded and encrypted files
 * on the private disk with no original file name; a removed or purged document keeps its row and loses only its bytes.
 * Retention is clinic-entered per category (KPOne ships no number). Checklist items are clinic-entered; a tick records
 * who and when. Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_document_retentions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->string('category', 16); // contract | offer | identity | certificate | resignation | other
            $t->unsignedInteger('retention_days');
            $t->unsignedBigInteger('set_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_document_retentions_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_document_retentions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'category'], 'hr_document_retentions_one');
        });

        Schema::create('hr_documents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->string('category', 16);
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('kind', 8); // pdf | jpeg | png
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 200);
            $t->string('status', 8)->default('active'); // active | removed | purged
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->unsignedBigInteger('removed_by_user_id')->nullable();
            $t->timestampTz('removed_at')->nullable();
            $t->string('removal_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_documents_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['uploaded_by_user_id', 'removed_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_documents_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_documents_scope_unique');
            $t->index(['worker_id', 'status'], 'hr_documents_worker');
        });

        Schema::create('hr_document_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('document_id');
            $t->string('event', 8); // added | opened | removed | purged
            $t->unsignedBigInteger('actor_user_id')->nullable(); // null only for the scheduled purge
            $t->timestampTz('created_at');
            $t->foreign(['document_id', 'organisation_id'], 'hr_document_events_document_fk')->references(['id', 'organisation_id'])->on('hr_documents')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_document_events_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        Schema::create('hr_checklist_items', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('list', 12); // onboarding | offboarding
            $t->string('label', 160);
            $t->boolean('about_account')->default(false); // "Ask the account administrator"
            $t->unsignedInteger('position');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_checklist_items_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_checklist_items_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_checklist_items_scope_unique');
        });

        Schema::create('hr_checklist_ticks', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('period_id');
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('ticked_by_user_id');
            $t->timestampTz('ticked_at');
            $t->foreign(['item_id', 'organisation_id'], 'hr_checklist_ticks_item_fk')->references(['id', 'organisation_id'])->on('hr_checklist_items')->restrictOnDelete();
            $t->foreign(['ticked_by_user_id', 'organisation_id'], 'hr_checklist_ticks_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign('period_id', 'hr_checklist_ticks_period_fk')->references('id')->on('hr_employment_periods')->restrictOnDelete();
            $t->unique(['period_id', 'item_id'], 'hr_checklist_ticks_once');
        });

        // The HR-4a change list gains the two HR-4b areas (field names only, as before).
        DB::statement('ALTER TABLE hr_record_changes DROP CONSTRAINT hr_record_changes_shape');
        DB::statement("ALTER TABLE hr_record_changes ADD CONSTRAINT hr_record_changes_shape CHECK (area IN ('worker', 'employment', 'position', 'personal', 'bank', 'correction', 'document', 'checklist') AND jsonb_typeof(fields) = 'array')");
        DB::statement("ALTER TABLE hr_document_retentions ADD CONSTRAINT hr_document_retentions_shape CHECK (category IN ('contract', 'offer', 'identity', 'certificate', 'resignation', 'other') AND retention_days BETWEEN 1 AND 36500)");
        DB::statement(<<<'SQL'
ALTER TABLE hr_documents ADD CONSTRAINT hr_documents_shape CHECK (
 category IN ('contract', 'offer', 'identity', 'certificate', 'resignation', 'other')
 AND kind IN ('pdf', 'jpeg', 'png')
 AND bytes BETWEEN 1 AND 10485760
 AND sha256 ~ '^[0-9a-f]{64}$'
 AND status IN ('active', 'removed', 'purged')
 AND (expires_on IS NULL OR issued_on IS NULL OR expires_on >= issued_on)
 AND (status = 'active') = (removed_at IS NULL)
 AND (status = 'removed') = (removal_reason IS NOT NULL)
 AND (status = 'removed') = (removed_by_user_id IS NOT NULL)
 AND (removal_reason IS NULL OR removal_reason IN ('wrong_file', 'wrong_person', 'not_readable'))
)
SQL);
        DB::statement("ALTER TABLE hr_document_events ADD CONSTRAINT hr_document_events_shape CHECK (event IN ('added', 'opened', 'removed', 'purged') AND (actor_user_id IS NULL) = (event = 'purged'))");
        DB::statement("ALTER TABLE hr_checklist_items ADD CONSTRAINT hr_checklist_items_shape CHECK (list IN ('onboarding', 'offboarding') AND char_length(btrim(label)) BETWEEN 2 AND 160)");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_document_events_append BEFORE UPDATE OR DELETE ON hr_document_events FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_checklist_ticks_append BEFORE UPDATE OR DELETE ON hr_checklist_ticks FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_documents_no_delete BEFORE DELETE ON hr_documents FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_checklist_items_no_delete BEFORE DELETE ON hr_checklist_items FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_document_retentions_no_delete BEFORE DELETE ON hr_document_retentions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_documents_truncate BEFORE TRUNCATE ON hr_documents FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_document_events_truncate BEFORE TRUNCATE ON hr_document_events FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_document_retentions_truncate BEFORE TRUNCATE ON hr_document_retentions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_checklist_items_truncate BEFORE TRUNCATE ON hr_checklist_items FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_checklist_ticks_truncate BEFORE TRUNCATE ON hr_checklist_ticks FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A document keeps what was uploaded; it moves once, from active to removed or purged, and then only loses its bytes.
CREATE OR REPLACE FUNCTION guard_hr_document() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'active' THEN RAISE EXCEPTION 'A document starts as active' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.category <> OLD.category OR NEW.kind <> OLD.kind OR NEW.bytes <> OLD.bytes OR NEW.sha256 <> OLD.sha256
   OR NEW.stored_path <> OLD.stored_path OR NEW.uploaded_by_user_id <> OLD.uploaded_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.issued_on IS DISTINCT FROM OLD.issued_on OR NEW.expires_on IS DISTINCT FROM OLD.expires_on THEN
  RAISE EXCEPTION 'A document is kept as uploaded' USING ERRCODE = 'P0001';
 END IF;
 IF NOT (OLD.status = 'active' AND NEW.status IN ('removed', 'purged')) THEN
  RAISE EXCEPTION 'A document moves once' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_documents_guard BEFORE INSERT OR UPDATE ON hr_documents FOR EACH ROW EXECUTE FUNCTION guard_hr_document();

-- A tick belongs to a period of the same organisation, on the list that period offers (onboarding always, offboarding
-- only once the period has an end date).
CREATE OR REPLACE FUNCTION guard_hr_checklist_tick() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p RECORD; i RECORD;
BEGIN
 SELECT organisation_id, ends_on INTO p FROM hr_employment_periods WHERE id = NEW.period_id;
 SELECT organisation_id, list, is_active INTO i FROM hr_checklist_items WHERE id = NEW.item_id;
 IF p.organisation_id IS DISTINCT FROM NEW.organisation_id OR i.organisation_id IS DISTINCT FROM NEW.organisation_id THEN
  RAISE EXCEPTION 'A tick stays inside one organisation' USING ERRCODE = '23514';
 END IF;
 IF i.list = 'offboarding' AND p.ends_on IS NULL THEN
  RAISE EXCEPTION 'Offboarding items are ticked only for an ended period' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_checklist_ticks_guard BEFORE INSERT ON hr_checklist_ticks FOR EACH ROW EXECUTE FUNCTION guard_hr_checklist_tick();

-- An item's list never changes (a ticked item must keep meaning what it meant).
CREATE OR REPLACE FUNCTION guard_hr_checklist_item() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.list <> OLD.list
   OR NEW.label <> OLD.label OR NEW.about_account <> OLD.about_account OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A checklist item keeps its wording; archive it and add a new one' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_checklist_items_guard BEFORE UPDATE ON hr_checklist_items FOR EACH ROW EXECUTE FUNCTION guard_hr_checklist_item();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
