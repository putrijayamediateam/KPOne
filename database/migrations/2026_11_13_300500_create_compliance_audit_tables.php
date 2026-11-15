<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-11c monthly audits and supervisor tasks (docs/teams/hr/HR-11-DESIGN.md section 7; owner, 2026-11-11: "that two
 * works i approve use ur recommendation"). The clinic types its own checklist (KPOne ships none); a new checklist version
 * never changes an audit already started. An audit is one checklist version, one branch and one branch-local month;
 * answers can change only while it is open, and submitting is final. Each failed item gets one task in the same
 * transaction. Photos are encrypted files under random names, checked by content, and the original name is never kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_checklist_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'compliance_checklist_versions_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('created_by_user_id', 'compliance_checklist_versions_user_fk')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'compliance_checklist_versions_once');
        });

        Schema::create('compliance_checklist_items', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('version_id');
            $t->unsignedSmallInteger('position');
            $t->string('section', 80);
            $t->string('title', 160);
            $t->string('help', 240)->nullable();
            $t->boolean('photo_required')->default(false);
            $t->foreign('organisation_id', 'compliance_checklist_items_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('version_id', 'compliance_checklist_items_version_fk')->references('id')->on('compliance_checklist_versions')->restrictOnDelete();
            $t->unique(['version_id', 'position'], 'compliance_checklist_items_position');
        });

        Schema::create('compliance_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id')->unique();
            $t->boolean('verify_done_tasks')->default(true);
            $t->unsignedSmallInteger('photo_retention_days')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'compliance_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
        });

        Schema::create('compliance_audits', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('checklist_version_id');
            $t->date('month_start');
            $t->string('status', 12)->default('open');
            $t->unsignedBigInteger('started_by_user_id');
            $t->timestampTz('started_at');
            $t->unsignedBigInteger('submitted_by_user_id')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->boolean('verify_done_tasks');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'compliance_audits_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('branch_id', 'compliance_audits_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('checklist_version_id', 'compliance_audits_version_fk')->references('id')->on('compliance_checklist_versions')->restrictOnDelete();
            $t->foreign('started_by_user_id', 'compliance_audits_started_fk')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('submitted_by_user_id', 'compliance_audits_submitted_fk')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['branch_id', 'month_start'], 'compliance_audits_branch_month_once');
        });

        Schema::create('compliance_audit_answers', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('audit_id');
            $t->unsignedBigInteger('item_id');
            $t->string('result', 4);
            $t->text('note_encrypted')->nullable();
            $t->timestampTz('answered_at');
            $t->foreign('organisation_id', 'compliance_audit_answers_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('audit_id', 'compliance_audit_answers_audit_fk')->references('id')->on('compliance_audits')->restrictOnDelete();
            $t->foreign('item_id', 'compliance_audit_answers_item_fk')->references('id')->on('compliance_checklist_items')->restrictOnDelete();
            $t->unique(['audit_id', 'item_id'], 'compliance_audit_answers_once');
        });

        Schema::create('compliance_audit_photos', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('audit_id');
            $t->unsignedBigInteger('item_id');
            $t->string('label', 10);
            $t->string('kind', 4);
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 160)->nullable();
            $t->unsignedBigInteger('added_by_user_id');
            $t->timestampTz('created_at');
            $t->timestampTz('purged_at')->nullable();
            $t->foreign('organisation_id', 'compliance_audit_photos_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('audit_id', 'compliance_audit_photos_audit_fk')->references('id')->on('compliance_audits')->restrictOnDelete();
            $t->foreign('item_id', 'compliance_audit_photos_item_fk')->references('id')->on('compliance_checklist_items')->restrictOnDelete();
            $t->foreign('added_by_user_id', 'compliance_audit_photos_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('compliance_tasks', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('audit_id');
            $t->unsignedBigInteger('item_id');
            $t->string('status', 10)->default('open');
            $t->boolean('verify_required');
            $t->unsignedBigInteger('done_by_user_id')->nullable();
            $t->timestampTz('done_at')->nullable();
            $t->text('done_note_encrypted')->nullable();
            $t->unsignedBigInteger('verified_by_user_id')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->string('cancel_reason', 20)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'compliance_tasks_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('branch_id', 'compliance_tasks_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('audit_id', 'compliance_tasks_audit_fk')->references('id')->on('compliance_audits')->restrictOnDelete();
            $t->foreign('item_id', 'compliance_tasks_item_fk')->references('id')->on('compliance_checklist_items')->restrictOnDelete();
            foreach (['done_by_user_id', 'verified_by_user_id', 'cancelled_by_user_id'] as $col) {
                $t->foreign($col, 'compliance_tasks_'.$col.'_fk')->references('id')->on('users')->restrictOnDelete();
            }
            $t->unique(['audit_id', 'item_id'], 'compliance_tasks_once_per_failed_item');
        });

        Schema::create('compliance_task_photos', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('task_id');
            $t->string('label', 10);
            $t->string('kind', 4);
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 160)->nullable();
            $t->unsignedBigInteger('added_by_user_id');
            $t->timestampTz('created_at');
            $t->timestampTz('purged_at')->nullable();
            $t->foreign('organisation_id', 'compliance_task_photos_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('task_id', 'compliance_task_photos_task_fk')->references('id')->on('compliance_tasks')->restrictOnDelete();
            $t->foreign('added_by_user_id', 'compliance_task_photos_user_fk')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE compliance_checklist_items ADD CONSTRAINT compliance_checklist_items_shape CHECK (char_length(btrim(section)) BETWEEN 2 AND 80 AND char_length(btrim(title)) BETWEEN 2 AND 160 AND position BETWEEN 1 AND 200)');
        DB::statement('ALTER TABLE compliance_settings ADD CONSTRAINT compliance_settings_shape CHECK (photo_retention_days IS NULL OR photo_retention_days BETWEEN 30 AND 3650)');
        DB::statement("ALTER TABLE compliance_audits ADD CONSTRAINT compliance_audits_shape CHECK (status IN ('open', 'submitted') AND EXTRACT(DAY FROM month_start) = 1 AND ((status = 'open' AND submitted_at IS NULL AND submitted_by_user_id IS NULL) OR (status = 'submitted' AND submitted_at IS NOT NULL AND submitted_by_user_id IS NOT NULL)))");
        DB::statement("ALTER TABLE compliance_audit_answers ADD CONSTRAINT compliance_audit_answers_shape CHECK (result IN ('pass', 'fail', 'na') AND (result <> 'fail' OR note_encrypted IS NOT NULL))");
        foreach (['compliance_audit_photos', 'compliance_task_photos'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_shape CHECK (kind IN ('jpeg', 'png') AND bytes > 0 AND sha256 ~ '^[0-9a-f]{64}\$' AND label ~ '^Photo [1-3]\$' AND ((purged_at IS NULL AND stored_path IS NOT NULL) OR (purged_at IS NOT NULL AND stored_path IS NULL)))");
        }
        DB::statement("ALTER TABLE compliance_tasks ADD CONSTRAINT compliance_tasks_shape CHECK (status IN ('open', 'done', 'verified', 'cancelled')
            AND (status NOT IN ('done', 'verified') OR (done_by_user_id IS NOT NULL AND done_at IS NOT NULL))
            AND (status <> 'verified' OR (verify_required AND verified_by_user_id IS NOT NULL AND verified_at IS NOT NULL AND verified_by_user_id <> done_by_user_id))
            AND (status <> 'cancelled' OR (cancelled_by_user_id IS NOT NULL AND cancelled_at IS NOT NULL AND cancel_reason IN ('done_elsewhere', 'duplicate', 'not_needed', 'other')))
            AND (status = 'cancelled' OR (cancelled_by_user_id IS NULL AND cancelled_at IS NULL AND cancel_reason IS NULL))
            AND (status = 'verified' OR (verified_by_user_id IS NULL AND verified_at IS NULL))
            AND (status <> 'open' OR (done_by_user_id IS NULL AND done_at IS NULL AND done_note_encrypted IS NULL)))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER compliance_checklist_versions_append BEFORE UPDATE OR DELETE ON compliance_checklist_versions FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_checklist_items_append BEFORE UPDATE OR DELETE ON compliance_checklist_items FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_checklist_versions_truncate BEFORE TRUNCATE ON compliance_checklist_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_checklist_items_truncate BEFORE TRUNCATE ON compliance_checklist_items FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_audits_no_delete BEFORE DELETE ON compliance_audits FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_audit_answers_no_delete BEFORE DELETE ON compliance_audit_answers FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_tasks_no_delete BEFORE DELETE ON compliance_tasks FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_audit_photos_no_delete BEFORE DELETE ON compliance_audit_photos FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_task_photos_no_delete BEFORE DELETE ON compliance_task_photos FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_settings_no_delete BEFORE DELETE ON compliance_settings FOR EACH ROW EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_audits_truncate BEFORE TRUNCATE ON compliance_audits FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_audit_answers_truncate BEFORE TRUNCATE ON compliance_audit_answers FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_tasks_truncate BEFORE TRUNCATE ON compliance_tasks FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_audit_photos_truncate BEFORE TRUNCATE ON compliance_audit_photos FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_task_photos_truncate BEFORE TRUNCATE ON compliance_task_photos FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();
CREATE TRIGGER compliance_settings_truncate BEFORE TRUNCATE ON compliance_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_compliance_append_only();

-- An audit keeps its branch, month and checklist version; it moves one way, open to submitted, and is then frozen.
CREATE OR REPLACE FUNCTION guard_compliance_audit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v compliance_checklist_versions%ROWTYPE;
BEGIN
 IF TG_OP = 'INSERT' THEN
  SELECT * INTO v FROM compliance_checklist_versions WHERE id = NEW.checklist_version_id;
  IF v.id IS NULL OR v.organisation_id <> NEW.organisation_id THEN
   RAISE EXCEPTION 'An audit must use its organisation''s checklist' USING ERRCODE = 'P0001';
  END IF;
  IF NEW.status <> 'open' THEN
   RAISE EXCEPTION 'An audit starts open' USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status = 'submitted' THEN
  RAISE EXCEPTION 'A submitted audit is final' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id
   OR NEW.month_start <> OLD.month_start OR NEW.checklist_version_id <> OLD.checklist_version_id OR NEW.started_by_user_id <> OLD.started_by_user_id
   OR NEW.verify_done_tasks <> OLD.verify_done_tasks THEN
  RAISE EXCEPTION 'An audit keeps its branch, month and checklist version' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_audits_guard BEFORE INSERT OR UPDATE ON compliance_audits FOR EACH ROW EXECUTE FUNCTION guard_compliance_audit();

-- An answer belongs to an open audit and to an item of that audit's checklist version; once the audit is submitted nothing moves.
CREATE OR REPLACE FUNCTION guard_compliance_audit_answer() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a compliance_audits%ROWTYPE; i compliance_checklist_items%ROWTYPE;
BEGIN
 IF TG_OP = 'UPDATE' AND (NEW.id <> OLD.id OR NEW.audit_id <> OLD.audit_id OR NEW.item_id <> OLD.item_id OR NEW.organisation_id <> OLD.organisation_id) THEN
  RAISE EXCEPTION 'An answer keeps its audit and item' USING ERRCODE = 'P0001';
 END IF;
 SELECT * INTO a FROM compliance_audits WHERE id = NEW.audit_id;
 IF a.id IS NULL OR a.status <> 'open' THEN
  RAISE EXCEPTION 'Answers can change only while the audit is open' USING ERRCODE = 'P0001';
 END IF;
 SELECT * INTO i FROM compliance_checklist_items WHERE id = NEW.item_id;
 IF i.id IS NULL OR i.version_id <> a.checklist_version_id OR a.organisation_id <> NEW.organisation_id THEN
  RAISE EXCEPTION 'An answer must be for an item of the audit''s checklist version' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_audit_answers_guard BEFORE INSERT OR UPDATE ON compliance_audit_answers FOR EACH ROW EXECUTE FUNCTION guard_compliance_audit_answer();

-- At most 3 photos per item, added only while the audit is open; the only later change is purging the file.
CREATE OR REPLACE FUNCTION guard_compliance_audit_photo() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a compliance_audits%ROWTYPE;
BEGIN
 IF TG_OP = 'UPDATE' THEN
  IF OLD.purged_at IS NULL AND NEW.purged_at IS NOT NULL AND NEW.stored_path IS NULL AND NEW.id = OLD.id AND NEW.public_id = OLD.public_id AND NEW.organisation_id = OLD.organisation_id
     AND NEW.audit_id = OLD.audit_id AND NEW.item_id = OLD.item_id AND NEW.sha256 = OLD.sha256 AND NEW.label = OLD.label AND NEW.kind = OLD.kind AND NEW.bytes = OLD.bytes
     AND NEW.added_by_user_id = OLD.added_by_user_id AND NEW.created_at = OLD.created_at THEN
   RETURN NEW;
  END IF;
  RAISE EXCEPTION 'Clinic compliance records are kept: they are never changed, deleted or truncated' USING ERRCODE = 'P0001';
 END IF;
 SELECT * INTO a FROM compliance_audits WHERE id = NEW.audit_id;
 IF a.id IS NULL OR a.status <> 'open' OR a.organisation_id <> NEW.organisation_id THEN
  RAISE EXCEPTION 'Photos can be added only while the audit is open' USING ERRCODE = 'P0001';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM compliance_checklist_items WHERE id = NEW.item_id AND version_id = a.checklist_version_id) THEN
  RAISE EXCEPTION 'A photo must be for an item of the audit''s checklist version' USING ERRCODE = 'P0001';
 END IF;
 IF (SELECT count(*) FROM compliance_audit_photos WHERE audit_id = NEW.audit_id AND item_id = NEW.item_id AND purged_at IS NULL) >= 3 THEN
  RAISE EXCEPTION 'At most 3 photos per item' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_audit_photos_guard BEFORE INSERT OR UPDATE ON compliance_audit_photos FOR EACH ROW EXECUTE FUNCTION guard_compliance_audit_photo();

-- A task exists only for a failed answer of its own audit, at that audit's branch, and only once it is submitted.
CREATE OR REPLACE FUNCTION guard_compliance_task() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE a compliance_audits%ROWTYPE;
BEGIN
 IF TG_OP = 'INSERT' THEN
  SELECT * INTO a FROM compliance_audits WHERE id = NEW.audit_id;
  IF a.id IS NULL OR a.organisation_id <> NEW.organisation_id OR a.branch_id <> NEW.branch_id OR a.status <> 'submitted' THEN
   RAISE EXCEPTION 'A task belongs to its submitted audit and branch' USING ERRCODE = 'P0001';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM compliance_audit_answers WHERE audit_id = NEW.audit_id AND item_id = NEW.item_id AND result = 'fail') THEN
   RAISE EXCEPTION 'A task exists only for a failed item of its audit' USING ERRCODE = 'P0001';
  END IF;
  IF NEW.status <> 'open' OR NEW.verify_required <> a.verify_done_tasks THEN
   RAISE EXCEPTION 'A task starts open and keeps the audit''s verification rule' USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id OR NEW.audit_id <> OLD.audit_id
   OR NEW.item_id <> OLD.item_id OR NEW.verify_required <> OLD.verify_required THEN
  RAISE EXCEPTION 'A task keeps its audit, item and branch' USING ERRCODE = 'P0001';
 END IF;
 IF NOT ((OLD.status = 'open' AND NEW.status IN ('open', 'done', 'cancelled'))
      OR (OLD.status = 'done' AND NEW.status IN ('done', 'verified', 'cancelled'))
      OR (OLD.status = NEW.status AND OLD.status IN ('verified', 'cancelled'))) THEN
  RAISE EXCEPTION 'A task moves one way: open, done, verified or cancelled' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status IN ('verified', 'cancelled') AND (to_jsonb(NEW) - 'updated_at') <> (to_jsonb(OLD) - 'updated_at') THEN
  RAISE EXCEPTION 'A closed task is final' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status <> 'open' AND (NEW.done_by_user_id IS DISTINCT FROM OLD.done_by_user_id OR NEW.done_at IS DISTINCT FROM OLD.done_at OR NEW.done_note_encrypted IS DISTINCT FROM OLD.done_note_encrypted) THEN
  RAISE EXCEPTION 'Who marked a task done, and when, never changes' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_tasks_guard BEFORE INSERT OR UPDATE ON compliance_tasks FOR EACH ROW EXECUTE FUNCTION guard_compliance_task();

-- At most 3 photos per task, added only while the task is open or done; the only later change is purging the file.
CREATE OR REPLACE FUNCTION guard_compliance_task_photo() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE t compliance_tasks%ROWTYPE;
BEGIN
 IF TG_OP = 'UPDATE' THEN
  IF OLD.purged_at IS NULL AND NEW.purged_at IS NOT NULL AND NEW.stored_path IS NULL AND NEW.id = OLD.id AND NEW.public_id = OLD.public_id AND NEW.organisation_id = OLD.organisation_id
     AND NEW.task_id = OLD.task_id AND NEW.sha256 = OLD.sha256 AND NEW.label = OLD.label AND NEW.kind = OLD.kind AND NEW.bytes = OLD.bytes
     AND NEW.added_by_user_id = OLD.added_by_user_id AND NEW.created_at = OLD.created_at THEN
   RETURN NEW;
  END IF;
  RAISE EXCEPTION 'Clinic compliance records are kept: they are never changed, deleted or truncated' USING ERRCODE = 'P0001';
 END IF;
 SELECT * INTO t FROM compliance_tasks WHERE id = NEW.task_id;
 IF t.id IS NULL OR t.organisation_id <> NEW.organisation_id OR t.status NOT IN ('open', 'done') THEN
  RAISE EXCEPTION 'Photos can be added only to an open or done task' USING ERRCODE = 'P0001';
 END IF;
 IF (SELECT count(*) FROM compliance_task_photos WHERE task_id = NEW.task_id AND purged_at IS NULL) >= 3 THEN
  RAISE EXCEPTION 'At most 3 photos per task' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER compliance_task_photos_guard BEFORE INSERT OR UPDATE ON compliance_task_photos FOR EACH ROW EXECUTE FUNCTION guard_compliance_task_photo();
SQL);
    }

    public function down(): void
    {
        foreach (['compliance_task_photos', 'compliance_tasks', 'compliance_audit_photos', 'compliance_audit_answers', 'compliance_audits', 'compliance_settings', 'compliance_checklist_items', 'compliance_checklist_versions'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS guard_compliance_task_photo(); DROP FUNCTION IF EXISTS guard_compliance_task(); DROP FUNCTION IF EXISTS guard_compliance_audit_photo(); DROP FUNCTION IF EXISTS guard_compliance_audit_answer(); DROP FUNCTION IF EXISTS guard_compliance_audit();');
    }
};
