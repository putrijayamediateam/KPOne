<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-8 announcements and memos. A memo is plain text (encrypted) with an optional PDF, published to everyone, to chosen
 * branches, to chosen roles, or to both. It is never edited: a correction is a new memo that replaces the old one, and
 * withdrawing keeps every read. A replacement that is later withdrawn no longer replaces, so the old memo is live again. Opens and acknowledgements are append-only. The audience is checked in the application
 * under the account lock; PostgreSQL refuses an acknowledgement for a withdrawn, replaced or closed memo, or without the
 * needed opens. Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_memos', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('title', 120);
            $t->text('body');
            $t->string('pdf_path', 200)->nullable();
            $t->unsignedInteger('pdf_bytes')->nullable();
            $t->char('pdf_sha256', 64)->nullable();
            $t->unsignedBigInteger('published_by_user_id');
            $t->timestampTz('published_at');
            $t->timestampTz('expires_at')->nullable();
            $t->unsignedBigInteger('replaces_memo_id')->nullable();
            $t->timestampTz('withdrawn_at')->nullable();
            $t->unsignedBigInteger('withdrawn_by_user_id')->nullable();
            $t->string('withdrawn_reason', 24)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_memos_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            foreach (['published_by_user_id', 'withdrawn_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_memos_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_memos_scope_unique');
            $t->foreign(['replaces_memo_id', 'organisation_id'], 'hr_memos_replaces_fk')->references(['id', 'organisation_id'])->on('hr_memos')->restrictOnDelete();
        });

        Schema::create('hr_memo_branches', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('memo_id');
            $t->unsignedBigInteger('branch_id');
            $t->foreign(['memo_id', 'organisation_id'], 'hr_memo_branches_memo_fk')->references(['id', 'organisation_id'])->on('hr_memos')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_memo_branches_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['memo_id', 'branch_id'], 'hr_memo_branches_once');
        });

        Schema::create('hr_memo_roles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('memo_id');
            $t->string('role', 40);
            $t->foreign('memo_id', 'hr_memo_roles_memo_fk')->references('id')->on('hr_memos')->restrictOnDelete();
            $t->unique(['memo_id', 'role'], 'hr_memo_roles_once');
        });

        Schema::create('hr_memo_opens', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('memo_id');
            $t->unsignedBigInteger('user_id');
            $t->string('kind', 4);
            $t->timestampTz('created_at');
            $t->foreign(['memo_id', 'organisation_id'], 'hr_memo_opens_memo_fk')->references(['id', 'organisation_id'])->on('hr_memos')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_memo_opens_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['memo_id', 'user_id', 'kind'], 'hr_memo_opens_once');
        });

        Schema::create('hr_memo_acknowledgements', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('memo_id');
            $t->unsignedBigInteger('user_id');
            $t->timestampTz('created_at');
            $t->foreign(['memo_id', 'organisation_id'], 'hr_memo_acks_memo_fk')->references(['id', 'organisation_id'])->on('hr_memos')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_memo_acks_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['memo_id', 'user_id'], 'hr_memo_acks_once');
        });

        DB::statement("ALTER TABLE hr_memos ADD CONSTRAINT hr_memos_shape CHECK (char_length(btrim(title)) BETWEEN 3 AND 120 AND char_length(body) > 0
 AND ((pdf_path IS NULL) = (pdf_bytes IS NULL) AND (pdf_path IS NULL) = (pdf_sha256 IS NULL))
 AND (pdf_sha256 IS NULL OR pdf_sha256 ~ '^[0-9a-f]{64}\$')
 AND ((withdrawn_at IS NULL) = (withdrawn_by_user_id IS NULL) AND (withdrawn_at IS NULL) = (withdrawn_reason IS NULL))
 AND (withdrawn_reason IS NULL OR withdrawn_reason IN ('wrong_audience', 'wrong_content', 'no_longer_needed', 'other'))
 AND (expires_at IS NULL OR expires_at > published_at))");
        DB::statement('CREATE UNIQUE INDEX hr_memos_replaced_once ON hr_memos (replaces_memo_id) WHERE replaces_memo_id IS NOT NULL AND withdrawn_at IS NULL');
        DB::statement("ALTER TABLE hr_memo_opens ADD CONSTRAINT hr_memo_opens_kind CHECK (kind IN ('memo', 'pdf'))");
        // Literal role names: this historical migration must not follow later code changes.
        DB::statement("ALTER TABLE hr_memo_roles ADD CONSTRAINT hr_memo_roles_role CHECK (role IN ('director', 'resident_doctor', 'ca', 'ca_supervisor', 'panel_officer', 'finance_officer', 'administrator', 'business_development', 'marketing', 'hr_manager', 'technical_admin'))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_memo_branches_append BEFORE UPDATE OR DELETE ON hr_memo_branches FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memo_roles_append BEFORE UPDATE OR DELETE ON hr_memo_roles FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memo_opens_append BEFORE UPDATE OR DELETE ON hr_memo_opens FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memo_acks_append BEFORE UPDATE OR DELETE ON hr_memo_acknowledgements FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memos_no_delete BEFORE DELETE ON hr_memos FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memos_truncate BEFORE TRUNCATE ON hr_memos FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memo_branches_truncate BEFORE TRUNCATE ON hr_memo_branches FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memo_roles_truncate BEFORE TRUNCATE ON hr_memo_roles FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memo_opens_truncate BEFORE TRUNCATE ON hr_memo_opens FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_memo_acks_truncate BEFORE TRUNCATE ON hr_memo_acknowledgements FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A memo keeps everything it was published with. Only a withdrawal (once) and the lock version may change.
CREATE OR REPLACE FUNCTION guard_hr_memo() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.title <> OLD.title
   OR NEW.body <> OLD.body OR NEW.pdf_path IS DISTINCT FROM OLD.pdf_path OR NEW.pdf_bytes IS DISTINCT FROM OLD.pdf_bytes
   OR NEW.pdf_sha256 IS DISTINCT FROM OLD.pdf_sha256 OR NEW.published_by_user_id <> OLD.published_by_user_id
   OR NEW.published_at <> OLD.published_at OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
   OR NEW.replaces_memo_id IS DISTINCT FROM OLD.replaces_memo_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A published memo cannot be changed' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.withdrawn_at IS NOT NULL AND (NEW.withdrawn_at IS DISTINCT FROM OLD.withdrawn_at
   OR NEW.withdrawn_by_user_id IS DISTINCT FROM OLD.withdrawn_by_user_id OR NEW.withdrawn_reason IS DISTINCT FROM OLD.withdrawn_reason) THEN
  RAISE EXCEPTION 'A withdrawn memo stays withdrawn' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_memos_guard BEFORE UPDATE ON hr_memos FOR EACH ROW EXECUTE FUNCTION guard_hr_memo();

-- A memo replaces an earlier, not withdrawn memo of the same organisation.
CREATE OR REPLACE FUNCTION guard_hr_memo_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE old RECORD;
BEGIN
 IF NEW.withdrawn_at IS NOT NULL THEN RAISE EXCEPTION 'A memo is not published withdrawn' USING ERRCODE = '23514'; END IF;
 IF NEW.replaces_memo_id IS NOT NULL THEN
  SELECT id, withdrawn_at INTO old FROM hr_memos WHERE id = NEW.replaces_memo_id AND organisation_id = NEW.organisation_id FOR UPDATE;
  IF NOT FOUND OR old.id >= NEW.id OR old.withdrawn_at IS NOT NULL THEN
   RAISE EXCEPTION 'A memo replaces an earlier memo that is not withdrawn' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_memos_insert_guard BEFORE INSERT ON hr_memos FOR EACH ROW EXECUTE FUNCTION guard_hr_memo_insert();

-- An acknowledgement only for a memo that is not withdrawn, not replaced and not closed, and only after the person opened
-- the memo (and its PDF, if it has one). The audience is checked by the application under the account lock.
CREATE OR REPLACE FUNCTION guard_hr_memo_acknowledgement() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE m RECORD;
BEGIN
 SELECT id, withdrawn_at, expires_at, pdf_path INTO m FROM hr_memos WHERE id = NEW.memo_id FOR SHARE;
 IF m.withdrawn_at IS NOT NULL THEN RAISE EXCEPTION 'A withdrawn memo takes no acknowledgement' USING ERRCODE = '23514'; END IF;
 IF m.expires_at IS NOT NULL AND m.expires_at <= clock_timestamp() THEN RAISE EXCEPTION 'A closed memo takes no acknowledgement' USING ERRCODE = '23514'; END IF;
 IF EXISTS (SELECT 1 FROM hr_memos WHERE replaces_memo_id = NEW.memo_id AND withdrawn_at IS NULL) THEN
  RAISE EXCEPTION 'A replaced memo takes no acknowledgement' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_memo_opens WHERE memo_id = NEW.memo_id AND user_id = NEW.user_id AND kind = 'memo')
   OR (m.pdf_path IS NOT NULL AND NOT EXISTS (SELECT 1 FROM hr_memo_opens WHERE memo_id = NEW.memo_id AND user_id = NEW.user_id AND kind = 'pdf')) THEN
  RAISE EXCEPTION 'A memo is acknowledged only after it was opened' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_memo_acks_guard BEFORE INSERT ON hr_memo_acknowledgements FOR EACH ROW EXECUTE FUNCTION guard_hr_memo_acknowledgement();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
