<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-10d incident reports (HR-PLAN 20.6; owner, 2026-11-10). Any human staff member reports what happened (encrypted),
 * when, at which branch and of which kind, with optional photos (checked, EXIF removed). The Director or HR Manager
 * acknowledges it, adds actions with an owner and a due date, and closes it with an outcome. The reporter sees the
 * status only, never the managers' notes. Patient-care reports are flagged for clinical review. A report can be linked
 * to an HR-10c discipline case only by someone who may handle discipline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_incidents', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('reporter_user_id');
            $t->timestampTz('occurred_at');
            $t->string('category', 20);
            $t->text('description_encrypted');
            $t->string('status', 12); // reported | acknowledged | closed
            $t->unsignedBigInteger('acknowledged_by_user_id')->nullable();
            $t->timestampTz('acknowledged_at')->nullable();
            $t->text('outcome_encrypted')->nullable();
            $t->unsignedBigInteger('closed_by_user_id')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedBigInteger('discipline_case_id')->nullable();
            $t->unsignedBigInteger('linked_by_user_id')->nullable();
            $t->timestampTz('linked_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->unique(['id', 'organisation_id'], 'hr_incidents_id_org_unique');
            $t->foreign('organisation_id', 'hr_incidents_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign('branch_id', 'hr_incidents_branch_fk')->references('id')->on('branches')->restrictOnDelete();
            foreach (['reporter_user_id', 'acknowledged_by_user_id', 'closed_by_user_id', 'linked_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_incidents_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->foreign(['discipline_case_id', 'organisation_id'], 'hr_incidents_case_fk')->references(['id', 'organisation_id'])->on('hr_discipline_cases')->restrictOnDelete();
            $t->index(['organisation_id', 'status', 'occurred_at'], 'hr_incidents_list_idx');
            $t->index('reporter_user_id', 'hr_incidents_reporter_idx');
        });
        DB::statement("ALTER TABLE hr_incidents ADD CONSTRAINT hr_incidents_shape CHECK (
            status IN ('reported', 'acknowledged', 'closed') AND lock_version >= 1
            AND category IN ('workplace_safety', 'staff_conduct', 'equipment_premises', 'near_miss', 'patient_care')
            AND ((acknowledged_at IS NULL) = (acknowledged_by_user_id IS NULL)) AND ((status = 'reported') = (acknowledged_at IS NULL))
            AND ((status = 'closed') = (closed_at IS NOT NULL)) AND ((closed_at IS NULL) = (closed_by_user_id IS NULL))
            AND ((closed_at IS NULL) = (outcome_encrypted IS NULL))
            AND ((discipline_case_id IS NULL) = (linked_at IS NULL)) AND ((linked_at IS NULL) = (linked_by_user_id IS NULL))
            AND acknowledged_by_user_id IS DISTINCT FROM reporter_user_id AND closed_by_user_id IS DISTINCT FROM reporter_user_id
            AND linked_by_user_id IS DISTINCT FROM reporter_user_id)");

        Schema::create('hr_incident_photos', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('incident_id');
            $t->string('kind', 4); // jpeg | png
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 200);
            $t->timestampTz('created_at');
            $t->unique(['incident_id', 'sha256'], 'hr_incident_photos_once');
            $t->foreign(['incident_id', 'organisation_id'], 'hr_incident_photos_incident_fk')->references(['id', 'organisation_id'])->on('hr_incidents')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE hr_incident_photos ADD CONSTRAINT hr_incident_photos_shape CHECK (kind IN ('jpeg', 'png') AND bytes > 0)");

        Schema::create('hr_incident_actions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('incident_id');
            $t->text('description_encrypted');
            $t->unsignedBigInteger('owner_user_id');
            $t->date('due_on');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->unsignedBigInteger('done_by_user_id')->nullable();
            $t->timestampTz('done_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->foreign(['incident_id', 'organisation_id'], 'hr_incident_actions_incident_fk')->references(['id', 'organisation_id'])->on('hr_incidents')->restrictOnDelete();
            foreach (['owner_user_id', 'created_by_user_id', 'done_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_incident_actions_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index('incident_id', 'hr_incident_actions_incident_idx');
        });
        DB::statement('ALTER TABLE hr_incident_actions ADD CONSTRAINT hr_incident_actions_shape CHECK (lock_version >= 1 AND ((done_at IS NULL) = (done_by_user_id IS NULL)))');

        // Managers' notes: never shown to the reporter.
        Schema::create('hr_incident_notes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('incident_id');
            $t->text('body_encrypted');
            $t->unsignedBigInteger('author_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['incident_id', 'organisation_id'], 'hr_incident_notes_incident_fk')->references(['id', 'organisation_id'])->on('hr_incidents')->restrictOnDelete();
            $t->foreign(['author_user_id', 'organisation_id'], 'hr_incident_notes_author_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['incident_id', 'id'], 'hr_incident_notes_incident_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_incident_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> 'reported' OR NEW.lock_version <> 1 OR NEW.discipline_case_id IS NOT NULL THEN
  RAISE EXCEPTION 'An incident report starts as reported' USING ERRCODE = 'P0001';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM branches b WHERE b.id = NEW.branch_id AND b.organisation_id = NEW.organisation_id) THEN
  RAISE EXCEPTION 'An incident report belongs to a branch of its organisation' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_incidents_insert_guard BEFORE INSERT ON hr_incidents FOR EACH ROW EXECUTE FUNCTION guard_hr_incident_insert();

-- What the reporter wrote never changes. The status moves reported -> acknowledged -> closed, each once; a discipline
-- link is set once and never removed.
CREATE OR REPLACE FUNCTION guard_hr_incident() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id
   OR NEW.reporter_user_id <> OLD.reporter_user_id OR NEW.occurred_at <> OLD.occurred_at OR NEW.category <> OLD.category
   OR NEW.description_encrypted <> OLD.description_encrypted OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.lock_version <> OLD.lock_version + 1
   OR (OLD.acknowledged_at IS NOT NULL AND (NEW.acknowledged_at IS DISTINCT FROM OLD.acknowledged_at OR NEW.acknowledged_by_user_id IS DISTINCT FROM OLD.acknowledged_by_user_id))
   OR (OLD.status = 'closed' AND (NEW.status <> 'closed' OR NEW.closed_at IS DISTINCT FROM OLD.closed_at OR NEW.outcome_encrypted IS DISTINCT FROM OLD.outcome_encrypted))
   OR (OLD.status = 'acknowledged' AND NEW.status = 'reported')
   OR (OLD.discipline_case_id IS NOT NULL AND (NEW.discipline_case_id IS DISTINCT FROM OLD.discipline_case_id OR NEW.linked_at IS DISTINCT FROM OLD.linked_at)) THEN
  RAISE EXCEPTION 'An incident report keeps its history' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'reported' AND NEW.status = 'closed' THEN
  RAISE EXCEPTION 'Acknowledge an incident report before closing it' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.discipline_case_id IS NOT NULL AND OLD.discipline_case_id IS NULL AND NOT EXISTS (SELECT 1 FROM model_has_permissions mp
     JOIN permissions p ON p.id = mp.permission_id WHERE mp.model_id = NEW.linked_by_user_id AND mp.model_type = 'App\Models\User'
     AND p.name = 'hr.discipline.manage.organisation'
   UNION SELECT 1 FROM model_has_roles mr JOIN role_has_permissions rp ON rp.role_id = mr.role_id JOIN permissions p ON p.id = rp.permission_id
     WHERE mr.model_id = NEW.linked_by_user_id AND mr.model_type = 'App\Models\User' AND p.name = 'hr.discipline.manage.organisation') THEN
  RAISE EXCEPTION 'Only someone who handles discipline links an incident to a case' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_incidents_guard BEFORE UPDATE ON hr_incidents FOR EACH ROW EXECUTE FUNCTION guard_hr_incident();

-- Actions: only the done mark changes, once. Actions, notes and photos are added only while the report is open,
-- and never by the reporter (photos come only with the report itself). The incident row is read without a lock:
-- every write path in IncidentService already holds it FOR UPDATE.
CREATE OR REPLACE FUNCTION guard_hr_incident_action() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.incident_id <> OLD.incident_id OR NEW.description_encrypted <> OLD.description_encrypted
   OR NEW.owner_user_id <> OLD.owner_user_id OR NEW.due_on <> OLD.due_on OR NEW.created_by_user_id <> OLD.created_by_user_id
   OR NEW.created_at <> OLD.created_at OR NEW.lock_version <> OLD.lock_version + 1 OR OLD.done_at IS NOT NULL OR NEW.done_at IS NULL THEN
  RAISE EXCEPTION 'An incident action changes only by being marked done, once' USING ERRCODE = 'P0001';
 END IF;
 IF EXISTS (SELECT 1 FROM hr_incidents i WHERE i.id = NEW.incident_id AND i.status = 'closed') THEN
  RAISE EXCEPTION 'A closed incident takes no more changes' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_incident_actions_guard BEFORE UPDATE ON hr_incident_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_incident_action();

CREATE OR REPLACE FUNCTION guard_hr_incident_child_insert() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE i record; actor bigint;
BEGIN
 SELECT * INTO i FROM hr_incidents WHERE id = NEW.incident_id;
 IF TG_TABLE_NAME = 'hr_incident_photos' THEN
  IF i.status <> 'reported' OR EXISTS (SELECT 1 FROM hr_incident_photos p WHERE p.incident_id = NEW.incident_id HAVING count(*) >= 3) THEN
   RAISE EXCEPTION 'Photos come with the report, at most 3' USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
 END IF;
 IF i.status = 'closed' THEN
  RAISE EXCEPTION 'A closed incident takes no more changes' USING ERRCODE = 'P0001';
 END IF;
 actor := (to_jsonb(NEW) ->> CASE TG_TABLE_NAME WHEN 'hr_incident_actions' THEN 'created_by_user_id' ELSE 'author_user_id' END)::bigint;
 IF actor = i.reporter_user_id THEN
  RAISE EXCEPTION 'Nobody handles their own incident report' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_incident_photos_insert_guard BEFORE INSERT ON hr_incident_photos FOR EACH ROW EXECUTE FUNCTION guard_hr_incident_child_insert();
CREATE TRIGGER hr_incident_notes_insert_guard BEFORE INSERT ON hr_incident_notes FOR EACH ROW EXECUTE FUNCTION guard_hr_incident_child_insert();
CREATE TRIGGER hr_incident_actions_insert_guard BEFORE INSERT ON hr_incident_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_incident_child_insert();

CREATE TRIGGER hr_incident_photos_append_only BEFORE UPDATE OR DELETE ON hr_incident_photos FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_incident_notes_append_only BEFORE UPDATE OR DELETE ON hr_incident_notes FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_incident_actions_no_delete BEFORE DELETE ON hr_incident_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_incidents_no_delete BEFORE DELETE ON hr_incidents FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_incidents_no_truncate BEFORE TRUNCATE ON hr_incidents FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_incident_photos_no_truncate BEFORE TRUNCATE ON hr_incident_photos FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_incident_actions_no_truncate BEFORE TRUNCATE ON hr_incident_actions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_incident_notes_no_truncate BEFORE TRUNCATE ON hr_incident_notes FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
SQL);
    }

    public function down(): void {} // HR record evidence is retained, following the evidence convention.
};
