<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-1 roster planner (B.E.R.N.I.S.S for the owner, 2026-11-05). One roster week per branch; versions move
 * draft -> submitted -> approved (-> superseded), or a draft is discarded. Shifts change only while their version is a
 * draft. The approver is never the person who sent the week. Actions are append-only. Nothing here touches accounts,
 * roles, branch assignments, rooms or any clinical table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_roster_weeks', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('week_start');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_roster_weeks_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['branch_id', 'week_start'], 'hr_roster_weeks_branch_week_unique');
            $t->unique(['id', 'organisation_id', 'branch_id'], 'hr_roster_weeks_scope_unique');
        });

        Schema::create('hr_roster_versions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('roster_week_id');
            $t->unsignedInteger('version_number');
            $t->string('status', 16);
            $t->unsignedBigInteger('based_on_version_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedBigInteger('submitted_by_user_id')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('return_reason', 32)->nullable();
            $t->unsignedBigInteger('returned_by_user_id')->nullable();
            $t->timestampTz('superseded_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['roster_week_id', 'organisation_id', 'branch_id'], 'hr_roster_versions_week_fk')->references(['id', 'organisation_id', 'branch_id'])->on('hr_roster_weeks')->restrictOnDelete();
            $t->foreign('based_on_version_id', 'hr_roster_versions_based_on_fk')->references('id')->on('hr_roster_versions')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_roster_versions_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['submitted_by_user_id', 'organisation_id'], 'hr_roster_versions_submitter_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['decided_by_user_id', 'organisation_id'], 'hr_roster_versions_decider_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['returned_by_user_id', 'organisation_id'], 'hr_roster_versions_returner_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['roster_week_id', 'version_number'], 'hr_roster_versions_number_unique');
            $t->unique(['id', 'organisation_id', 'branch_id'], 'hr_roster_versions_scope_unique');
        });

        Schema::create('hr_roster_shifts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('roster_version_id');
            $t->unsignedBigInteger('staff_user_id');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('timezone', 64);
            $t->unsignedSmallInteger('break_minutes')->default(0);
            $t->string('session_type', 16);
            $t->unsignedBigInteger('branch_room_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['roster_version_id', 'organisation_id', 'branch_id'], 'hr_roster_shifts_version_fk')->references(['id', 'organisation_id', 'branch_id'])->on('hr_roster_versions')->restrictOnDelete();
            $t->foreign(['staff_user_id', 'organisation_id'], 'hr_roster_shifts_staff_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_roster_shifts_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['branch_room_id', 'organisation_id', 'branch_id'], 'hr_roster_shifts_room_fk')->references(['id', 'organisation_id', 'branch_id'])->on('branch_rooms')->restrictOnDelete();
            $t->index(['staff_user_id', 'starts_at', 'ends_at'], 'hr_roster_shifts_staff_time_index');
            $t->index(['roster_version_id', 'starts_at'], 'hr_roster_shifts_version_time_index');
        });

        Schema::create('hr_roster_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('roster_week_id');
            $t->unsignedBigInteger('roster_version_id');
            $t->string('action', 32);
            $t->unsignedBigInteger('actor_user_id');
            $t->uuid('shift_public_id')->nullable();
            $t->unsignedBigInteger('subject_user_id')->nullable();
            $t->string('reason', 32)->nullable();
            $t->timestampTz('occurred_at');
            $t->unsignedBigInteger('branch_id');
            $t->foreign(['roster_week_id', 'organisation_id', 'branch_id'], 'hr_roster_actions_week_fk')->references(['id', 'organisation_id', 'branch_id'])->on('hr_roster_weeks')->restrictOnDelete();
            $t->foreign(['roster_version_id', 'organisation_id', 'branch_id'], 'hr_roster_actions_version_fk')->references(['id', 'organisation_id', 'branch_id'])->on('hr_roster_versions')->restrictOnDelete();
            $t->foreign(['subject_user_id', 'organisation_id'], 'hr_roster_actions_subject_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_roster_actions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['roster_version_id', 'id'], 'hr_roster_actions_version_index');
        });

        Schema::create('hr_roster_requests', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->uuid('idempotency_key');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('action', 32);
            $t->string('payload_hash', 64);
            $t->unsignedBigInteger('roster_version_id');
            $t->timestampTz('created_at');
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_roster_requests_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign('roster_version_id', 'hr_roster_requests_version_fk')->references('id')->on('hr_roster_versions')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'hr_roster_requests_key_unique');
        });

        Schema::create('hr_minimum_staffing', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id')->unique();
            $t->jsonb('minimums');
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('updated_by_user_id');
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_minimum_staffing_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['updated_by_user_id', 'organisation_id'], 'hr_minimum_staffing_updater_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE hr_roster_weeks ADD CONSTRAINT hr_roster_weeks_monday CHECK (EXTRACT(ISODOW FROM week_start) = 1 AND lock_version > 0)');
        DB::statement(<<<'SQL'
ALTER TABLE hr_roster_versions ADD CONSTRAINT hr_roster_versions_shape CHECK (
 version_number > 0 AND lock_version > 0
 AND status IN ('draft', 'submitted', 'approved', 'superseded', 'discarded')
 AND (status <> 'submitted' OR (submitted_by_user_id IS NOT NULL AND submitted_at IS NOT NULL))
 AND (status NOT IN ('approved', 'superseded') OR (submitted_by_user_id IS NOT NULL AND decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL AND decided_by_user_id <> submitted_by_user_id))
 AND (status <> 'superseded' OR superseded_at IS NOT NULL)
 AND (return_reason IS NULL OR return_reason IN ('needs_changes', 'wrong_staff', 'wrong_times', 'other'))
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_roster_versions_one_open ON hr_roster_versions (roster_week_id) WHERE status IN ('draft', 'submitted')");
        DB::statement("CREATE UNIQUE INDEX hr_roster_versions_one_approved ON hr_roster_versions (roster_week_id) WHERE status = 'approved'");
        DB::statement(<<<'SQL'
ALTER TABLE hr_roster_shifts ADD CONSTRAINT hr_roster_shifts_shape CHECK (
 ends_at > starts_at AND ends_at - starts_at <= interval '16 hours'
 AND break_minutes <= 180 AND break_minutes * interval '1 minute' < ends_at - starts_at
 AND session_type IN ('morning', 'afternoon', 'evening', 'night', 'full_day', 'other')
 AND lock_version > 0 AND length(timezone) > 0
)
SQL);
        DB::statement("ALTER TABLE hr_roster_actions ADD CONSTRAINT hr_roster_actions_shape CHECK (action IN ('draft_started', 'week_copied', 'shift_added', 'shift_updated', 'shift_removed', 'submitted', 'returned', 'approved', 'superseded', 'discarded') AND (reason IS NULL OR reason IN ('needs_changes', 'wrong_staff', 'wrong_times', 'other')))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_roster_version() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'TRUNCATE' OR TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Roster versions are never deleted' USING ERRCODE = 'P0001'; END IF;
 IF TG_OP = 'UPDATE' THEN
  IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id
     OR NEW.roster_week_id <> OLD.roster_week_id OR NEW.version_number <> OLD.version_number OR NEW.created_by_user_id <> OLD.created_by_user_id
     OR NEW.based_on_version_id IS DISTINCT FROM OLD.based_on_version_id THEN
   RAISE EXCEPTION 'Roster version identity is immutable' USING ERRCODE = 'P0001';
  END IF;
  IF OLD.status IN ('superseded', 'discarded') THEN RAISE EXCEPTION 'A replaced or discarded roster version never changes' USING ERRCODE = 'P0001'; END IF;
  IF OLD.status = 'approved' AND (NEW.status <> 'superseded' OR NEW.submitted_by_user_id IS DISTINCT FROM OLD.submitted_by_user_id
     OR NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id OR NEW.decided_at IS DISTINCT FROM OLD.decided_at) THEN
   RAISE EXCEPTION 'An approved roster version can only be replaced' USING ERRCODE = 'P0001';
  END IF;
  -- Two-person rule for a return: the person who sent the week never returns it.
  IF OLD.status = 'submitted' AND NEW.status = 'draft' AND (NEW.returned_by_user_id IS NULL OR NEW.returned_by_user_id = OLD.submitted_by_user_id) THEN
   RAISE EXCEPTION 'The person who sent a roster week cannot return it' USING ERRCODE = '23514';
  END IF;
  IF NOT ((OLD.status = 'draft' AND NEW.status IN ('draft', 'submitted', 'discarded'))
       OR (OLD.status = 'submitted' AND NEW.status IN ('draft', 'approved'))
       OR (OLD.status = 'approved' AND NEW.status = 'superseded')) THEN
   RAISE EXCEPTION 'Invalid roster version transition' USING ERRCODE = '23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_roster_versions_guard BEFORE UPDATE OR DELETE ON hr_roster_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_roster_version();
CREATE TRIGGER hr_roster_versions_truncate BEFORE TRUNCATE ON hr_roster_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_roster_version();

CREATE OR REPLACE FUNCTION guard_hr_roster_shift() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_status text;
BEGIN
 IF TG_OP = 'TRUNCATE' THEN RAISE EXCEPTION 'Roster shifts are never truncated' USING ERRCODE = 'P0001'; END IF;
 SELECT status INTO v_status FROM hr_roster_versions WHERE id = CASE WHEN TG_OP = 'DELETE' THEN OLD.roster_version_id ELSE NEW.roster_version_id END;
 IF v_status IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'Shifts change only while their roster version is a draft' USING ERRCODE = 'P0001'; END IF;
 IF TG_OP = 'UPDATE' AND (NEW.roster_version_id <> OLD.roster_version_id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id) THEN
  RAISE EXCEPTION 'Roster shift identity is immutable' USING ERRCODE = 'P0001';
 END IF;
 IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_roster_shifts_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_roster_shifts FOR EACH ROW EXECUTE FUNCTION guard_hr_roster_shift();
CREATE TRIGGER hr_roster_shifts_truncate BEFORE TRUNCATE ON hr_roster_shifts FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_roster_shift();

CREATE OR REPLACE FUNCTION guard_hr_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'HR roster evidence is append-only' USING ERRCODE = 'P0001';
END $$;
CREATE TRIGGER hr_roster_actions_append BEFORE UPDATE OR DELETE ON hr_roster_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_append_only();
CREATE TRIGGER hr_roster_actions_truncate BEFORE TRUNCATE ON hr_roster_actions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_append_only();
CREATE TRIGGER hr_roster_requests_append BEFORE UPDATE OR DELETE ON hr_roster_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_append_only();
CREATE TRIGGER hr_roster_requests_truncate BEFORE TRUNCATE ON hr_roster_requests FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_append_only();
SQL);
    }

    public function down(): void
    {
        // Roster evidence is retained, following the evidence convention.
    }
};
