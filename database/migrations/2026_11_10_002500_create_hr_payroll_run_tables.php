<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-7b payroll runs (HR-PLAN section 16; B.E.R.N.I.S.S for the owner, 2026-11-06). A monthly run per pay group goes
 * Draft, Sent for approval (frozen), Approved by someone other than its preparer, and Locked by its approver. A returned
 * run goes back to Draft. A sent, approved or locked run and its lines never change; an adjustment run for a locked
 * month holds only manual difference lines. Amounts are encrypted. KPOne pays nothing and files nothing. Nothing is
 * deleted or truncated, except a draft's own people and calculated lines, which "Prepare again" replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_line_types', function (Blueprint $t): void {
            // Which pay item types a run uses for overtime, unpaid leave and staff claim refunds, so 16.3 item 3 knows which
            // schemes those lines count for. Append-only versions.
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('overtime_type_id')->nullable();
            $t->unsignedBigInteger('unpaid_leave_type_id')->nullable();
            $t->unsignedBigInteger('claim_type_id')->nullable();
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_payroll_line_types_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_payroll_line_types_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            foreach (['overtime_type_id', 'unpaid_leave_type_id', 'claim_type_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_payroll_line_types_{$column}_fk")->references(['id', 'organisation_id'])->on('hr_pay_item_types')->restrictOnDelete();
            }
            $t->unique(['organisation_id', 'version'], 'hr_payroll_line_types_once');
        });

        Schema::create('hr_payroll_runs', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('pay_group_id');
            $t->date('month'); // the first day of the month
            $t->string('kind', 10); // regular | adjustment
            $t->unsignedBigInteger('adjusts_run_id')->nullable();
            $t->string('status', 10)->default('draft'); // draft | sent | approved | locked
            $t->string('group_name', 60); // copied when prepared (Suresh, HR-7a M3)
            $t->unsignedSmallInteger('group_pay_day')->nullable();
            $t->unsignedBigInteger('prepared_by_user_id');
            $t->timestampTz('prepared_at');
            $t->unsignedBigInteger('sent_by_user_id')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->unsignedBigInteger('approved_by_user_id')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->unsignedBigInteger('locked_by_user_id')->nullable();
            $t->timestampTz('locked_at')->nullable();
            $t->jsonb('versions')->nullable(); // every version a sent run used
            $t->char('fingerprint', 64)->nullable(); // of the calculated lines, to refuse sending a stale draft
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['pay_group_id', 'organisation_id'], 'hr_payroll_runs_group_fk')->references(['id', 'organisation_id'])->on('hr_pay_groups')->restrictOnDelete();
            foreach (['prepared_by_user_id', 'sent_by_user_id', 'approved_by_user_id', 'locked_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_payroll_runs_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->unique(['id', 'organisation_id'], 'hr_payroll_runs_scope_unique');
            $t->index(['organisation_id', 'month'], 'hr_payroll_runs_month');
        });
        Schema::table('hr_payroll_runs', function (Blueprint $t): void {
            $t->foreign(['adjusts_run_id', 'organisation_id'], 'hr_payroll_runs_adjusts_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
        });

        Schema::create('hr_payroll_run_people', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('profile_id')->nullable(); // null on an adjustment run
            $t->text('mtd_encrypted')->nullable(); // entered per person, in sen
            $t->text('totals_encrypted')->nullable(); // earnings, deductions, refunds, employee, employer, mtd, net
            $t->jsonb('errors'); // codes only
            $t->jsonb('warnings'); // codes only
            $t->foreign(['run_id', 'organisation_id'], 'hr_payroll_run_people_run_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_payroll_run_people_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['profile_id', 'organisation_id'], 'hr_payroll_run_people_profile_fk')->references(['id', 'organisation_id'])->on('hr_pay_profiles')->restrictOnDelete();
            $t->unique(['run_id', 'worker_id'], 'hr_payroll_run_people_once');
            $t->unique(['id', 'organisation_id'], 'hr_payroll_run_people_scope_unique');
        });

        Schema::create('hr_payroll_manual_lines', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('item_type_id');
            $t->unsignedInteger('item_type_version');
            $t->text('amount_encrypted');
            $t->string('reason_code', 16); // correction | bonus | recovery | other
            $t->unsignedBigInteger('added_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['run_id', 'organisation_id'], 'hr_payroll_manual_lines_run_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_payroll_manual_lines_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['item_type_id', 'organisation_id'], 'hr_payroll_manual_lines_type_fk')->references(['id', 'organisation_id'])->on('hr_pay_item_types')->restrictOnDelete();
            $t->foreign(['added_by_user_id', 'organisation_id'], 'hr_payroll_manual_lines_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        Schema::create('hr_payroll_lines', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('run_person_id');
            $t->unsignedSmallInteger('position');
            $t->string('kind', 20); // basic | allowance | overtime | unpaid_leave | hourly | claim | manual | employee | employer | mtd
            $t->string('section', 12); // earnings | deduction | refund | employee | employer | mtd
            $t->unsignedBigInteger('item_type_id')->nullable();
            $t->unsignedInteger('item_type_version')->nullable();
            $t->string('scheme', 6)->nullable(); // epf | socso | eis | hrd, for statutory lines
            $t->string('source_kind', 20)->nullable(); // profile | overtime | leave | clock | claim | manual | table
            $t->unsignedBigInteger('source_id')->nullable();
            $t->text('amount_encrypted');
            $t->text('explanation_encrypted');
            $t->foreign(['run_id', 'organisation_id'], 'hr_payroll_lines_run_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
            $t->foreign(['run_person_id', 'organisation_id'], 'hr_payroll_lines_person_fk')->references(['id', 'organisation_id'])->on('hr_payroll_run_people')->restrictOnDelete();
            $t->index(['run_person_id', 'position'], 'hr_payroll_lines_person');
        });

        Schema::create('hr_payroll_run_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('run_id');
            $t->string('action', 12); // prepared | sent | returned | approved | locked
            $t->unsignedBigInteger('actor_user_id');
            $t->string('reason_code', 16)->nullable();
            $t->timestampTz('created_at');
            $t->foreign(['run_id', 'organisation_id'], 'hr_payroll_run_actions_run_fk')->references(['id', 'organisation_id'])->on('hr_payroll_runs')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_payroll_run_actions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['run_id', 'id'], 'hr_payroll_run_actions_run');
        });

        DB::statement('ALTER TABLE hr_payroll_line_types ADD CONSTRAINT hr_payroll_line_types_shape CHECK (version >= 1)');
        DB::statement(<<<'SQL'
ALTER TABLE hr_payroll_runs ADD CONSTRAINT hr_payroll_runs_shape CHECK (
 kind IN ('regular', 'adjustment')
 AND (kind = 'adjustment') = (adjusts_run_id IS NOT NULL)
 AND status IN ('draft', 'sent', 'approved', 'locked')
 AND EXTRACT(DAY FROM month) = 1
 AND (group_pay_day IS NULL OR group_pay_day BETWEEN 1 AND 31)
 AND (status IN ('sent', 'approved', 'locked')) = (sent_by_user_id IS NOT NULL AND versions IS NOT NULL)
 AND (status IN ('approved', 'locked')) = (approved_by_user_id IS NOT NULL)
 AND (status = 'locked') = (locked_by_user_id IS NOT NULL)
 AND (sent_by_user_id IS NULL) = (sent_at IS NULL)
 AND (approved_by_user_id IS NULL) = (approved_at IS NULL)
 AND (locked_by_user_id IS NULL) = (locked_at IS NULL)
 AND (approved_by_user_id IS NULL OR (approved_by_user_id <> prepared_by_user_id AND approved_by_user_id <> sent_by_user_id))
 AND (locked_by_user_id IS NULL OR locked_by_user_id = approved_by_user_id)
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_payroll_runs_regular_once ON hr_payroll_runs (pay_group_id, month) WHERE kind = 'regular'");
        DB::statement("CREATE UNIQUE INDEX hr_payroll_runs_one_open_adjustment ON hr_payroll_runs (adjusts_run_id) WHERE kind = 'adjustment' AND status <> 'locked'");
        DB::statement("ALTER TABLE hr_payroll_run_people ADD CONSTRAINT hr_payroll_run_people_shape CHECK (jsonb_typeof(errors) = 'array' AND jsonb_typeof(warnings) = 'array')");
        DB::statement("ALTER TABLE hr_payroll_manual_lines ADD CONSTRAINT hr_payroll_manual_lines_shape CHECK (reason_code IN ('correction', 'bonus', 'recovery', 'other') AND item_type_version >= 1)");
        DB::statement(<<<'SQL'
ALTER TABLE hr_payroll_lines ADD CONSTRAINT hr_payroll_lines_shape CHECK (
 kind IN ('basic', 'allowance', 'overtime', 'unpaid_leave', 'hourly', 'claim', 'manual', 'employee', 'employer', 'mtd')
 AND section IN ('earnings', 'deduction', 'refund', 'employee', 'employer', 'mtd')
 AND (scheme IS NULL OR scheme IN ('epf', 'socso', 'eis', 'hrd'))
 AND (kind IN ('employee', 'employer')) = (scheme IS NOT NULL)
)
SQL);
        DB::statement("ALTER TABLE hr_payroll_run_actions ADD CONSTRAINT hr_payroll_run_actions_shape CHECK (action IN ('prepared', 'sent', 'returned', 'approved', 'locked')
            AND (action = 'returned') = (reason_code IS NOT NULL) AND (reason_code IS NULL OR reason_code IN ('wrong_amount', 'wrong_person', 'missing_record', 'other')))");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_payroll_line_types_append BEFORE UPDATE OR DELETE ON hr_payroll_line_types FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_run_actions_append BEFORE UPDATE OR DELETE ON hr_payroll_run_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_runs_no_delete BEFORE DELETE ON hr_payroll_runs FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_line_types_truncate BEFORE TRUNCATE ON hr_payroll_line_types FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_runs_truncate BEFORE TRUNCATE ON hr_payroll_runs FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_run_people_truncate BEFORE TRUNCATE ON hr_payroll_run_people FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_manual_lines_truncate BEFORE TRUNCATE ON hr_payroll_manual_lines FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_lines_truncate BEFORE TRUNCATE ON hr_payroll_lines FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_payroll_run_actions_truncate BEFORE TRUNCATE ON hr_payroll_run_actions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

CREATE OR REPLACE FUNCTION guard_hr_payroll_line_types_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE top integer;
BEGIN
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_payroll_line_types WHERE organisation_id = NEW.organisation_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Run line type versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_payroll_line_types_gapless BEFORE INSERT ON hr_payroll_line_types FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_line_types_version();

-- A run starts as a draft; it moves draft -> sent -> approved -> locked, and sent -> draft when returned. What it is for
-- never changes, and a sent, approved or locked run keeps what it froze.
CREATE OR REPLACE FUNCTION guard_hr_payroll_run() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A payroll run starts as a draft' USING ERRCODE = '23514'; END IF;
  IF NEW.kind = 'adjustment' AND NOT EXISTS (SELECT 1 FROM hr_payroll_runs WHERE id = NEW.adjusts_run_id AND status = 'locked'
    AND kind = 'regular' AND pay_group_id = NEW.pay_group_id AND month = NEW.month) THEN
   RAISE EXCEPTION 'An adjustment run corrects a locked regular run of the same group and month' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.pay_group_id <> OLD.pay_group_id
   OR NEW.month <> OLD.month OR NEW.kind <> OLD.kind OR NEW.adjusts_run_id IS DISTINCT FROM OLD.adjusts_run_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A payroll run keeps what it is for' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status AND NOT ((OLD.status = 'draft' AND NEW.status = 'sent') OR (OLD.status = 'sent' AND NEW.status IN ('approved', 'draft'))
   OR (OLD.status = 'approved' AND NEW.status IN ('locked', 'draft'))) THEN
  RAISE EXCEPTION 'A payroll run moves draft to sent, sent to approved, approved to locked, and sent or approved back to draft' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status IN ('sent', 'approved', 'locked') AND NEW.status <> 'draft' AND (NEW.versions IS DISTINCT FROM OLD.versions OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
   OR NEW.group_name <> OLD.group_name OR NEW.group_pay_day IS DISTINCT FROM OLD.group_pay_day OR NEW.prepared_by_user_id <> OLD.prepared_by_user_id
   OR NEW.prepared_at IS DISTINCT FROM OLD.prepared_at OR NEW.sent_by_user_id IS DISTINCT FROM OLD.sent_by_user_id OR NEW.sent_at IS DISTINCT FROM OLD.sent_at) THEN
  RAISE EXCEPTION 'A sent payroll run never changes' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'locked' THEN RAISE EXCEPTION 'A locked payroll run never changes' USING ERRCODE = 'P0001'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_payroll_runs_guard BEFORE INSERT OR UPDATE ON hr_payroll_runs FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_run();

-- A run's people, lines and manual lines are written, changed or removed only while the run is a draft.
CREATE OR REPLACE FUNCTION guard_hr_payroll_draft_child() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE rid bigint;
BEGIN
 rid := CASE WHEN TG_OP = 'DELETE' THEN OLD.run_id ELSE NEW.run_id END;
 IF TG_OP = 'UPDATE' AND NEW.run_id <> OLD.run_id THEN RAISE EXCEPTION 'A payroll line stays on its run' USING ERRCODE = 'P0001'; END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_payroll_runs WHERE id = rid AND status = 'draft') THEN
  RAISE EXCEPTION 'Only a draft payroll run changes; a sent, approved or locked run never does' USING ERRCODE = 'P0001';
 END IF;
 RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER hr_payroll_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON hr_payroll_lines FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_draft_child();
CREATE TRIGGER hr_payroll_manual_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON hr_payroll_manual_lines FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_draft_child();
CREATE TRIGGER hr_payroll_run_people_draft BEFORE INSERT OR UPDATE OR DELETE ON hr_payroll_run_people FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_draft_child();

-- Managers' own pay and locums stay outside KPOne in v1, so neither is ever in a run (option (a)).
CREATE OR REPLACE FUNCTION guard_hr_payroll_run_person() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE w RECORD;
BEGIN
 SELECT kind, user_id INTO w FROM hr_workers WHERE id = NEW.worker_id;
 IF w.kind <> 'staff' THEN RAISE EXCEPTION 'Locums are paid outside KPOne in this version' USING ERRCODE = '23514'; END IF;
 IF EXISTS (SELECT 1 FROM model_has_roles mr JOIN roles r ON r.id = mr.role_id WHERE mr.model_id = w.user_id
   AND mr.model_type = 'App\Models\User' AND r.name IN ('director', 'hr_manager')) THEN
  RAISE EXCEPTION 'Managers'' own pay stays outside KPOne in this version' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_payroll_run_people_manager BEFORE INSERT ON hr_payroll_run_people FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_run_person();

-- Approve, return and lock are never by anyone who prepared or sent the run (every prepare step counts, so a person who
-- typed a manual line or an MTD figure never approves it), and a run holding a staff claim is never locked by that
-- claim's claimant or approver. The service inserts the action row before it changes the run.
CREATE OR REPLACE FUNCTION guard_hr_payroll_run_action() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE r RECORD;
BEGIN
 SELECT prepared_by_user_id, sent_by_user_id, approved_by_user_id INTO r FROM hr_payroll_runs WHERE id = NEW.run_id;
 IF NEW.action IN ('approved', 'returned', 'locked') AND (NEW.actor_user_id = r.prepared_by_user_id OR NEW.actor_user_id = r.sent_by_user_id
   OR EXISTS (SELECT 1 FROM hr_payroll_run_actions a WHERE a.run_id = NEW.run_id AND a.action = 'prepared' AND a.actor_user_id = NEW.actor_user_id)) THEN
  RAISE EXCEPTION 'Someone other than the preparer approves, returns and locks a payroll run' USING ERRCODE = '23514';
 END IF;
 IF NEW.action = 'locked' AND EXISTS (SELECT 1 FROM hr_payroll_lines l JOIN hr_staff_claims c ON c.id = l.source_id
   WHERE l.run_id = NEW.run_id AND l.kind = 'claim' AND (c.claimant_user_id = NEW.actor_user_id OR c.decided_by_user_id = NEW.actor_user_id)) THEN
  RAISE EXCEPTION 'A staff claim''s claimant or approver never locks the run that pays it' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_payroll_run_actions_guard BEFORE INSERT ON hr_payroll_run_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_payroll_run_action();
SQL);
    }

    public function down(): void
    {
        // HR payroll evidence is retained, following the evidence convention.
    }
};
