<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-10c discipline (HR-PLAN 20.5; owner, 2026-11-10). A restricted case per staff file: the allegation (encrypted), a
 * timeline of notes, linked show-cause letters, the staff member's recorded reply and files, then one outcome. KPOne
 * never ends employment or changes an account. Files live in their own table, not the staff file's document vault, so
 * nothing about a case shows on the staff file, the person's own records or any export.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_discipline_cases', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('subject_user_id')->nullable(); // the staff file's account; null for a locum file
            $t->text('allegation_encrypted');
            $t->date('incident_on');
            $t->unsignedBigInteger('opened_by_user_id');
            $t->timestampTz('opened_at');
            $t->string('status', 6); // open | closed
            $t->string('outcome', 20)->nullable();
            $t->unsignedBigInteger('outcome_letter_id')->nullable();
            $t->text('outcome_note_encrypted')->nullable();
            $t->unsignedBigInteger('closed_by_user_id')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->unique(['id', 'organisation_id'], 'hr_discipline_cases_id_org_unique');
            $t->foreign('organisation_id', 'hr_discipline_cases_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_discipline_cases_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['subject_user_id', 'opened_by_user_id', 'closed_by_user_id'] as $column) {
                $t->foreign($column, "hr_discipline_cases_{$column}_fk")->references('id')->on('users')->restrictOnDelete();
            }
            $t->foreign('outcome_letter_id', 'hr_discipline_cases_letter_fk')->references('id')->on('hr_letters')->restrictOnDelete();
            $t->index(['organisation_id', 'status', 'opened_at'], 'hr_discipline_cases_list_idx');
            $t->index('worker_id', 'hr_discipline_cases_worker_idx');
        });
        DB::statement("ALTER TABLE hr_discipline_cases ADD CONSTRAINT hr_discipline_cases_shape CHECK (
            status IN ('open', 'closed') AND lock_version >= 1
            AND (outcome IS NULL OR outcome IN ('no_action', 'verbal_warning', 'written_warning', 'final_warning', 'refer_termination'))
            AND ((status = 'closed') = (outcome IS NOT NULL)) AND ((outcome IS NULL) = (closed_at IS NULL)) AND ((outcome IS NULL) = (closed_by_user_id IS NULL))
            AND ((outcome_letter_id IS NOT NULL) = (outcome IN ('written_warning', 'final_warning')) OR (outcome IS NULL AND outcome_letter_id IS NULL))
            AND opened_by_user_id IS DISTINCT FROM subject_user_id AND closed_by_user_id IS DISTINCT FROM subject_user_id)");

        Schema::create('hr_discipline_files', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('case_id');
            $t->string('kind', 4); // pdf | jpeg | png
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 200);
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->timestampTz('created_at');
            $t->unique(['id', 'organisation_id'], 'hr_discipline_files_id_org_unique');
            $t->unique(['case_id', 'sha256'], 'hr_discipline_files_once');
            $t->foreign(['case_id', 'organisation_id'], 'hr_discipline_files_case_fk')->references(['id', 'organisation_id'])->on('hr_discipline_cases')->restrictOnDelete();
            $t->foreign('uploaded_by_user_id', 'hr_discipline_files_user_fk')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_discipline_files ADD CONSTRAINT hr_discipline_files_shape CHECK (kind IN ('pdf', 'jpeg', 'png') AND bytes > 0)");

        Schema::create('hr_discipline_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('case_id');
            $t->string('kind', 12); // opened | note | show_cause | reply | file | outcome
            $t->text('body_encrypted')->nullable();
            $t->unsignedBigInteger('letter_id')->nullable();
            $t->unsignedBigInteger('file_id')->nullable();
            $t->date('occurred_on')->nullable();
            $t->unsignedBigInteger('actor_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['case_id', 'organisation_id'], 'hr_discipline_events_case_fk')->references(['id', 'organisation_id'])->on('hr_discipline_cases')->restrictOnDelete();
            $t->foreign('letter_id', 'hr_discipline_events_letter_fk')->references('id')->on('hr_letters')->restrictOnDelete();
            $t->foreign(['file_id', 'organisation_id'], 'hr_discipline_events_file_fk')->references(['id', 'organisation_id'])->on('hr_discipline_files')->restrictOnDelete();
            $t->foreign('actor_user_id', 'hr_discipline_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $t->index(['case_id', 'id'], 'hr_discipline_events_case_idx');
        });
        DB::statement("ALTER TABLE hr_discipline_events ADD CONSTRAINT hr_discipline_events_shape CHECK (
            kind IN ('opened', 'note', 'show_cause', 'reply', 'file', 'outcome')
            AND (kind <> 'show_cause' OR letter_id IS NOT NULL) AND (letter_id IS NULL OR kind IN ('show_cause', 'outcome'))
            AND ((kind = 'file') = (file_id IS NOT NULL))
            AND (kind NOT IN ('note', 'reply') OR body_encrypted IS NOT NULL)
            AND (kind <> 'reply' OR occurred_on IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX hr_discipline_events_letter_once ON hr_discipline_events (case_id, letter_id) WHERE kind = \'show_cause\'');

        DB::unprepared(<<<'SQL'
-- Shared rule: a Director's or HR Manager's case is opened and closed only by a Director.
CREATE OR REPLACE FUNCTION hr_discipline_director_only(subject bigint, actor bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT NOT EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = subject AND mr.model_type = 'App\Models\User' AND r.name IN ('director', 'hr_manager'))
   OR EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id
     WHERE mr.model_id = actor AND mr.model_type = 'App\Models\User' AND r.name = 'director')
$$;

CREATE OR REPLACE FUNCTION guard_hr_discipline_case_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> 'open' OR NEW.outcome IS NOT NULL OR NEW.lock_version <> 1 THEN
  RAISE EXCEPTION 'A discipline case starts open, with no outcome' USING ERRCODE = 'P0001';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_workers w WHERE w.id = NEW.worker_id AND w.organisation_id = NEW.organisation_id
     AND w.user_id IS NOT DISTINCT FROM NEW.subject_user_id) THEN
  RAISE EXCEPTION 'A discipline case belongs to the staff file''s own account' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.subject_user_id IS NOT NULL AND NOT hr_discipline_director_only(NEW.subject_user_id, NEW.opened_by_user_id) THEN
  RAISE EXCEPTION 'A Director''s or HR Manager''s case is opened only by a Director' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_discipline_cases_insert_guard BEFORE INSERT ON hr_discipline_cases FOR EACH ROW EXECUTE FUNCTION guard_hr_discipline_case_insert();

-- The only change to a case is its one outcome, which closes it. The allegation and dates never change.
CREATE OR REPLACE FUNCTION guard_hr_discipline_case() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.worker_id <> OLD.worker_id
   OR NEW.subject_user_id IS DISTINCT FROM OLD.subject_user_id OR NEW.allegation_encrypted <> OLD.allegation_encrypted
   OR NEW.incident_on <> OLD.incident_on OR NEW.opened_by_user_id <> OLD.opened_by_user_id OR NEW.opened_at <> OLD.opened_at
   OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.lock_version <> OLD.lock_version + 1
   OR OLD.status <> 'open' OR NEW.status <> 'closed' THEN
  RAISE EXCEPTION 'A discipline case keeps its history; it changes only by its one outcome' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.subject_user_id IS NOT NULL AND NOT hr_discipline_director_only(NEW.subject_user_id, NEW.closed_by_user_id) THEN
  RAISE EXCEPTION 'A Director''s or HR Manager''s case is closed only by a Director' USING ERRCODE = '23514';
 END IF;
 IF NEW.outcome_letter_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM hr_letters l WHERE l.id = NEW.outcome_letter_id
     AND l.worker_id = NEW.worker_id AND l.organisation_id = NEW.organisation_id AND l.type = 'warning' AND l.voided_at IS NULL) THEN
  RAISE EXCEPTION 'A warning outcome needs a current warning letter for the same person' USING ERRCODE = '23514';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_discipline_events e WHERE e.case_id = NEW.id AND e.kind = 'outcome') THEN
  RAISE EXCEPTION 'A case is closed with its outcome event' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_discipline_cases_guard BEFORE UPDATE ON hr_discipline_cases FOR EACH ROW EXECUTE FUNCTION guard_hr_discipline_case();

-- Events and files are added only while the case is open; a show-cause link is a current show-cause letter of the same person.
CREATE OR REPLACE FUNCTION guard_hr_discipline_event_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c record;
BEGIN
 SELECT * INTO c FROM hr_discipline_cases WHERE id = NEW.case_id;
 IF c.status <> 'open' THEN
  RAISE EXCEPTION 'A closed case takes no more entries' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.kind = 'opened' AND EXISTS (SELECT 1 FROM hr_discipline_events e WHERE e.case_id = NEW.case_id) THEN
  RAISE EXCEPTION 'A case is opened once' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.kind = 'outcome' AND EXISTS (SELECT 1 FROM hr_discipline_events e WHERE e.case_id = NEW.case_id AND e.kind = 'outcome') THEN
  RAISE EXCEPTION 'A case has one outcome' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.actor_user_id IS NOT DISTINCT FROM c.subject_user_id THEN
  RAISE EXCEPTION 'Nobody records their own discipline case' USING ERRCODE = '23514';
 END IF;
 IF NEW.kind = 'show_cause' AND NOT EXISTS (SELECT 1 FROM hr_letters l WHERE l.id = NEW.letter_id AND l.worker_id = c.worker_id
     AND l.organisation_id = c.organisation_id AND l.type = 'show_cause' AND l.voided_at IS NULL) THEN
  RAISE EXCEPTION 'Link a current show-cause letter for the same person' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_discipline_events_insert_guard BEFORE INSERT ON hr_discipline_events FOR EACH ROW EXECUTE FUNCTION guard_hr_discipline_event_insert();

CREATE OR REPLACE FUNCTION guard_hr_discipline_file_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM hr_discipline_cases c WHERE c.id = NEW.case_id AND c.status = 'open') THEN
  RAISE EXCEPTION 'A closed case takes no more files' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_discipline_files_insert_guard BEFORE INSERT ON hr_discipline_files FOR EACH ROW EXECUTE FUNCTION guard_hr_discipline_file_insert();

CREATE TRIGGER hr_discipline_events_append_only BEFORE UPDATE OR DELETE ON hr_discipline_events FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_discipline_files_append_only BEFORE UPDATE OR DELETE ON hr_discipline_files FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_discipline_cases_no_delete BEFORE DELETE ON hr_discipline_cases FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_discipline_cases_no_truncate BEFORE TRUNCATE ON hr_discipline_cases FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_discipline_events_no_truncate BEFORE TRUNCATE ON hr_discipline_events FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_discipline_files_no_truncate BEFORE TRUNCATE ON hr_discipline_files FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
SQL);
    }

    public function down(): void
    {
        foreach (['hr_discipline_events', 'hr_discipline_files', 'hr_discipline_cases'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS guard_hr_discipline_file_insert(); DROP FUNCTION IF EXISTS guard_hr_discipline_event_insert();
            DROP FUNCTION IF EXISTS guard_hr_discipline_case(); DROP FUNCTION IF EXISTS guard_hr_discipline_case_insert();
            DROP FUNCTION IF EXISTS hr_discipline_director_only(bigint, bigint);');
    }
};
