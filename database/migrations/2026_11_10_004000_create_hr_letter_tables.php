<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-10a letters (HR-PLAN 20.3; owner, 2026-11-10: "all continue with your recommendations"). HR enters letter templates
 * (confirmation, warning, show-cause, other; EN and BM; each save a new version; KPOne ships none). Issuing a letter to a
 * staff file stores an encrypted, immutable snapshot (the filled text, the letterhead text and logo, the template version)
 * under a number HRL/{year}/{6-digit} from a locked yearly counter, never reused. A letter is never edited: a correction is
 * a new letter that replaces it, and a void keeps the row with its reason. Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_letter_templates', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('type', 12);
            $t->unsignedInteger('current_version')->default(1);
            $t->timestampTz('retired_at')->nullable();
            $t->unsignedBigInteger('retired_by_user_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_letter_templates_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            foreach (['created_by_user_id', 'retired_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_letter_templates_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_letter_templates_scope');
        });

        Schema::create('hr_letter_template_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('template_id');
            $t->unsignedInteger('version');
            $t->string('title', 80);
            $t->text('body_en');
            $t->text('body_bm');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['template_id', 'organisation_id'], 'hr_letter_tpl_versions_tpl_fk')->references(['id', 'organisation_id'])->on('hr_letter_templates')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_letter_tpl_versions_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['template_id', 'version'], 'hr_letter_tpl_versions_once');
            $t->unique(['id', 'organisation_id'], 'hr_letter_tpl_versions_scope');
        });

        Schema::create('hr_letter_counters', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number');
            $t->foreign('organisation_id', 'hr_letter_counters_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->unique(['organisation_id', 'year'], 'hr_letter_counters_once');
        });

        Schema::create('hr_letters', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('template_version_id');
            $t->string('type', 12);
            $t->char('language', 2);
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('number');
            $t->string('reference', 20);
            $t->text('snapshot_encrypted');
            $t->char('snapshot_hash', 64);
            $t->unsignedBigInteger('replaces_letter_id')->nullable();
            $t->unsignedBigInteger('issued_by_user_id');
            $t->timestampTz('issued_at');
            $t->date('given_on')->nullable();
            $t->unsignedBigInteger('given_recorded_by_user_id')->nullable();
            $t->timestampTz('voided_at')->nullable();
            $t->unsignedBigInteger('voided_by_user_id')->nullable();
            $t->string('void_reason', 20)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_letters_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_letters_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['template_version_id', 'organisation_id'], 'hr_letters_tpl_version_fk')->references(['id', 'organisation_id'])->on('hr_letter_template_versions')->restrictOnDelete();
            foreach (['issued_by_user_id', 'given_recorded_by_user_id', 'voided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_letters_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_letters_scope');
            $t->foreign(['replaces_letter_id', 'organisation_id'], 'hr_letters_replaces_fk')->references(['id', 'organisation_id'])->on('hr_letters')->restrictOnDelete();
            $t->unique(['organisation_id', 'year', 'number'], 'hr_letters_number_once');
            $t->unique(['organisation_id', 'reference'], 'hr_letters_reference_once');
            $t->unique('replaces_letter_id', 'hr_letters_replaced_once');
            $t->index(['organisation_id', 'worker_id'], 'hr_letters_worker');
        });

        Schema::create('hr_letter_opens', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('letter_id');
            $t->unsignedBigInteger('opened_by_user_id');
            $t->boolean('own');
            $t->timestampTz('created_at');
            $t->foreign(['letter_id', 'organisation_id'], 'hr_letter_opens_letter_fk')->references(['id', 'organisation_id'])->on('hr_letters')->restrictOnDelete();
            $t->foreign(['opened_by_user_id', 'organisation_id'], 'hr_letter_opens_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index('letter_id', 'hr_letter_opens_letter');
        });

        DB::statement("ALTER TABLE hr_letter_templates ADD CONSTRAINT hr_letter_templates_shape CHECK (
            type IN ('confirmation', 'warning', 'show_cause', 'other') AND current_version >= 1 AND lock_version >= 1
            AND ((retired_at IS NULL) = (retired_by_user_id IS NULL)))");
        DB::statement("ALTER TABLE hr_letter_template_versions ADD CONSTRAINT hr_letter_tpl_versions_shape CHECK (
            version >= 1 AND char_length(btrim(title)) BETWEEN 3 AND 80 AND title !~ '[<>{}\\r\\n]'
            AND char_length(btrim(body_en)) BETWEEN 20 AND 5000 AND char_length(btrim(body_bm)) BETWEEN 20 AND 5000
            AND body_en !~ '[<>]' AND body_bm !~ '[<>]')");
        DB::statement('ALTER TABLE hr_letter_counters ADD CONSTRAINT hr_letter_counters_shape CHECK (year BETWEEN 2000 AND 2999 AND last_number BETWEEN 0 AND 999999)');
        DB::statement("ALTER TABLE hr_letters ADD CONSTRAINT hr_letters_shape CHECK (
            type IN ('confirmation', 'warning', 'show_cause', 'other') AND language IN ('en', 'bm')
            AND year BETWEEN 2000 AND 2999 AND number BETWEEN 1 AND 999999
            AND reference = 'HRL/' || year || '/' || lpad(number::text, 6, '0')
            AND snapshot_hash ~ '^[0-9a-f]{64}\$' AND lock_version >= 1
            AND ((given_on IS NULL) = (given_recorded_by_user_id IS NULL))
            AND ((voided_at IS NULL) = (voided_by_user_id IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))
            AND (void_reason IS NULL OR void_reason IN ('wrong_person', 'wrong_content', 'issued_in_error', 'replaced', 'other')))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_letter_tpl_versions_append BEFORE UPDATE OR DELETE ON hr_letter_template_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letter_tpl_versions_truncate BEFORE TRUNCATE ON hr_letter_template_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letter_opens_append BEFORE UPDATE OR DELETE ON hr_letter_opens FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letter_opens_truncate BEFORE TRUNCATE ON hr_letter_opens FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letter_templates_no_delete BEFORE DELETE ON hr_letter_templates FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letter_templates_truncate BEFORE TRUNCATE ON hr_letter_templates FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letter_counters_no_delete BEFORE DELETE ON hr_letter_counters FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letter_counters_truncate BEFORE TRUNCATE ON hr_letter_counters FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letters_no_delete BEFORE DELETE ON hr_letters FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_letters_truncate BEFORE TRUNCATE ON hr_letters FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- A template keeps its type and author; only a new version (one step up) or a retirement (once) changes it.
CREATE OR REPLACE FUNCTION guard_hr_letter_template() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.type <> OLD.type
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.current_version NOT IN (OLD.current_version, OLD.current_version + 1)
   OR (OLD.retired_at IS NOT NULL AND (NEW.retired_at IS DISTINCT FROM OLD.retired_at OR NEW.current_version <> OLD.current_version))
   OR NEW.lock_version <> OLD.lock_version + 1 THEN
  RAISE EXCEPTION 'A letter template keeps its history' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_letter_templates_guard BEFORE UPDATE ON hr_letter_templates FOR EACH ROW EXECUTE FUNCTION guard_hr_letter_template();

-- A version is the template's current one, added right after the template moved to it, and never for a retired template.
CREATE OR REPLACE FUNCTION guard_hr_letter_template_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_letter_templates t WHERE t.id = NEW.template_id AND t.organisation_id = NEW.organisation_id
     AND t.current_version = NEW.version AND t.retired_at IS NULL) THEN
  RAISE EXCEPTION 'A template version follows its template' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_letter_tpl_versions_insert_guard BEFORE INSERT ON hr_letter_template_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_letter_template_version();

-- The yearly counter only moves forward, one step at a time.
CREATE OR REPLACE FUNCTION guard_hr_letter_counter() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.organisation_id <> OLD.organisation_id OR NEW.year <> OLD.year OR NEW.last_number <> OLD.last_number + 1 THEN
  RAISE EXCEPTION 'A letter number is never reused' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_letter_counters_guard BEFORE UPDATE ON hr_letter_counters FOR EACH ROW EXECUTE FUNCTION guard_hr_letter_counter();
CREATE OR REPLACE FUNCTION guard_hr_letter_counter_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.last_number <> 0 THEN RAISE EXCEPTION 'A letter counter starts at zero' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_letter_counters_insert_guard BEFORE INSERT ON hr_letter_counters FOR EACH ROW EXECUTE FUNCTION guard_hr_letter_counter_insert();

-- A letter is issued current, with the counter's own number, from a version of a template of the same type and
-- organisation; a replacement points at an earlier letter of the same staff file that is not void.
CREATE OR REPLACE FUNCTION guard_hr_letter_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE old RECORD;
BEGIN
 IF NEW.voided_at IS NOT NULL OR NEW.given_on IS NOT NULL OR NEW.lock_version <> 1 THEN
  RAISE EXCEPTION 'A letter is issued current' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_letter_counters c WHERE c.organisation_id = NEW.organisation_id AND c.year = NEW.year AND c.last_number = NEW.number) THEN
  RAISE EXCEPTION 'A letter takes the counter''s number' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_letter_template_versions v JOIN hr_letter_templates t ON t.id = v.template_id
     WHERE v.id = NEW.template_version_id AND t.organisation_id = NEW.organisation_id AND t.type = NEW.type) THEN
  RAISE EXCEPTION 'A letter comes from a template of its type' USING ERRCODE = '23514';
 END IF;
 IF NEW.replaces_letter_id IS NOT NULL THEN
  SELECT id, worker_id, voided_at INTO old FROM hr_letters WHERE id = NEW.replaces_letter_id AND organisation_id = NEW.organisation_id FOR UPDATE;
  IF NOT FOUND OR old.id >= NEW.id OR old.worker_id <> NEW.worker_id OR old.voided_at IS NOT NULL THEN
   RAISE EXCEPTION 'A letter replaces an earlier letter of the same person that is not void' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_letters_insert_guard BEFORE INSERT ON hr_letters FOR EACH ROW EXECUTE FUNCTION guard_hr_letter_insert();

-- An issued letter keeps everything it was issued with. Only "given on" (once) and a void (once) may change.
CREATE OR REPLACE FUNCTION guard_hr_letter() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.template_version_id <> OLD.template_version_id OR NEW.type <> OLD.type OR NEW.language <> OLD.language
   OR NEW.year <> OLD.year OR NEW.number <> OLD.number OR NEW.reference <> OLD.reference
   OR NEW.snapshot_encrypted <> OLD.snapshot_encrypted OR NEW.snapshot_hash <> OLD.snapshot_hash
   OR NEW.replaces_letter_id IS DISTINCT FROM OLD.replaces_letter_id OR NEW.issued_by_user_id <> OLD.issued_by_user_id
   OR NEW.issued_at <> OLD.issued_at OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.lock_version <> OLD.lock_version + 1 THEN
  RAISE EXCEPTION 'An issued letter cannot be changed' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.given_on IS NOT NULL AND (NEW.given_on IS DISTINCT FROM OLD.given_on OR NEW.given_recorded_by_user_id IS DISTINCT FROM OLD.given_recorded_by_user_id) THEN
  RAISE EXCEPTION 'When a letter was given is recorded once' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.voided_at IS NOT NULL AND (NEW.voided_at IS DISTINCT FROM OLD.voided_at OR NEW.voided_by_user_id IS DISTINCT FROM OLD.voided_by_user_id
   OR NEW.void_reason IS DISTINCT FROM OLD.void_reason OR NEW.given_on IS DISTINCT FROM OLD.given_on) THEN
  RAISE EXCEPTION 'A void letter stays void' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_letters_guard BEFORE UPDATE ON hr_letters FOR EACH ROW EXECUTE FUNCTION guard_hr_letter();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
