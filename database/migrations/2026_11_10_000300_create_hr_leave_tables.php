<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-2a leave (B.E.R.N.I.S.S for the owner, 2026-11-05). Clinic-entered leave types, entitlements, working days and
 * public holidays (nothing is seeded); requests decided by someone other than the applicant; opening balances and
 * adjustments confirmed by a second manager; an append-only ledger in half days. No evidence files (HR-2b).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->string('code', 20);
            $t->string('name', 60);
            $t->boolean('is_paid');
            $t->boolean('uses_balance');
            $t->boolean('allow_half_days');
            $t->boolean('evidence_needed');
            $t->boolean('allow_negative')->default(false);
            $t->unsignedSmallInteger('carry_max_halves')->nullable();
            $t->string('carry_expires_on', 5)->nullable(); // MM-DD in the following year
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('updated_by_user_id');
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_leave_types_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['updated_by_user_id', 'organisation_id'], 'hr_leave_types_updater_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'code'], 'hr_leave_types_code_unique');
            $t->unique(['id', 'organisation_id'], 'hr_leave_types_scope_unique');
        });

        Schema::create('hr_leave_entitlements', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('leave_type_id');
            $t->unsignedSmallInteger('year');
            $t->string('role', 32)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedSmallInteger('halves');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['leave_type_id', 'organisation_id'], 'hr_leave_entitlements_type_fk')->references(['id', 'organisation_id'])->on('hr_leave_types')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_leave_entitlements_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_leave_entitlements_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'year', 'leave_type_id'], 'hr_leave_entitlements_lookup');
        });

        Schema::create('hr_branch_working_days', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id')->unique();
            $t->jsonb('weekdays'); // ISO weekday numbers 1 (Monday) to 7 (Sunday)
            $t->unsignedInteger('lock_version')->default(1);
            $t->unsignedBigInteger('updated_by_user_id');
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_branch_working_days_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['updated_by_user_id', 'organisation_id'], 'hr_branch_working_days_updater_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        Schema::create('hr_public_holidays', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('holiday_date');
            $t->string('name', 80);
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_public_holidays_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_public_holidays_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['branch_id', 'holiday_date'], 'hr_public_holidays_branch_date_unique');
        });

        foreach (['hr_leave_openings', 'hr_leave_adjustments'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table): void {
                $t->id();
                $t->uuid('public_id')->unique();
                $t->unsignedBigInteger('organisation_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('leave_type_id');
                $t->unsignedSmallInteger('year');
                $t->integer('halves');
                if ($table === 'hr_leave_adjustments') {
                    $t->string('reason_code', 20);
                    $t->text('note_encrypted')->nullable();
                }
                $t->string('status', 12)->default('pending');
                $t->unsignedBigInteger('entered_by_user_id');
                $t->unsignedBigInteger('decided_by_user_id')->nullable();
                $t->timestampTz('decided_at')->nullable();
                $t->unsignedInteger('lock_version')->default(1);
                $t->timestampsTz();
                $t->foreign(['leave_type_id', 'organisation_id'], "{$table}_type_fk")->references(['id', 'organisation_id'])->on('hr_leave_types')->restrictOnDelete();
                foreach (['user_id', 'entered_by_user_id', 'decided_by_user_id'] as $column) {
                    $t->foreign([$column, 'organisation_id'], "{$table}_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
                }
            });
        }

        Schema::create('hr_leave_requests', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('leave_type_id');
            $t->unsignedBigInteger('branch_id'); // the applicant's primary branch on the start date
            $t->date('start_date');
            $t->date('end_date');
            $t->string('start_part', 4); // full | am | pm
            $t->string('end_part', 4); // full | am
            $t->timestamp('slot_start'); // branch-local, half-day steps
            $t->timestamp('slot_end');
            $t->unsignedSmallInteger('year');
            $t->unsignedSmallInteger('halves');
            $t->string('status', 12);
            $t->text('reason_encrypted')->nullable();
            $t->text('evidence_reference_encrypted')->nullable();
            $t->boolean('late')->default(false);
            $t->boolean('evidence_seen')->default(false);
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 24)->nullable();
            $t->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['leave_type_id', 'organisation_id'], 'hr_leave_requests_type_fk')->references(['id', 'organisation_id'])->on('hr_leave_types')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_leave_requests_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            foreach (['user_id', 'decided_by_user_id', 'cancelled_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_leave_requests_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['user_id', 'slot_start', 'slot_end'], 'hr_leave_requests_user_slots');
            $t->index(['organisation_id', 'status'], 'hr_leave_requests_status');
        });

        Schema::create('hr_leave_request_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('leave_request_id');
            $t->string('action', 12);
            $t->unsignedBigInteger('actor_user_id');
            $t->string('reason', 24)->nullable();
            $t->timestampTz('occurred_at');
            $t->foreign('leave_request_id', 'hr_leave_request_actions_request_fk')->references('id')->on('hr_leave_requests')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_leave_request_actions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        Schema::create('hr_leave_ledger', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('leave_type_id');
            $t->unsignedSmallInteger('year');
            $t->string('kind', 16);
            $t->integer('halves');
            $t->unsignedBigInteger('leave_request_id')->nullable();
            $t->unsignedBigInteger('leave_opening_id')->nullable();
            $t->unsignedBigInteger('leave_adjustment_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['leave_type_id', 'organisation_id'], 'hr_leave_ledger_type_fk')->references(['id', 'organisation_id'])->on('hr_leave_types')->restrictOnDelete();
            $t->foreign(['user_id', 'organisation_id'], 'hr_leave_ledger_user_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_leave_ledger_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign('leave_request_id', 'hr_leave_ledger_request_fk')->references('id')->on('hr_leave_requests')->restrictOnDelete();
            $t->foreign('leave_opening_id', 'hr_leave_ledger_opening_fk')->references('id')->on('hr_leave_openings')->restrictOnDelete();
            $t->foreign('leave_adjustment_id', 'hr_leave_ledger_adjustment_fk')->references('id')->on('hr_leave_adjustments')->restrictOnDelete();
            $t->index(['user_id', 'leave_type_id', 'year'], 'hr_leave_ledger_balance');
        });

        Schema::create('hr_leave_retries', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->uuid('idempotency_key');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('action', 24);
            $t->string('payload_hash', 64);
            $t->string('result', 64);
            $t->timestampTz('created_at');
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_leave_retries_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'hr_leave_retries_key_unique');
        });

        DB::statement("ALTER TABLE hr_leave_types ADD CONSTRAINT hr_leave_types_shape CHECK (code ~ '^[A-Z][A-Z0-9_]{0,19}$' AND length(trim(name)) > 0 AND (carry_expires_on IS NULL OR carry_expires_on ~ '^(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$') AND (carry_max_halves IS NULL OR carry_max_halves BETWEEN 1 AND 730))");
        DB::statement('ALTER TABLE hr_leave_entitlements ADD CONSTRAINT hr_leave_entitlements_shape CHECK ((role IS NULL) <> (user_id IS NULL) AND halves <= 730 AND year BETWEEN 2000 AND 2100)');
        DB::statement("ALTER TABLE hr_branch_working_days ADD CONSTRAINT hr_branch_working_days_shape CHECK (jsonb_typeof(weekdays) = 'array' AND jsonb_array_length(weekdays) BETWEEN 1 AND 7)");
        foreach (['hr_leave_openings', 'hr_leave_adjustments'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_shape CHECK (status IN ('pending', 'confirmed', 'rejected') AND halves BETWEEN -730 AND 730 AND (status = 'pending') = (decided_by_user_id IS NULL) AND (decided_by_user_id IS NULL OR decided_by_user_id <> entered_by_user_id))");
        }
        DB::statement('ALTER TABLE hr_leave_openings ADD CONSTRAINT hr_leave_openings_positive CHECK (halves >= 0)');
        DB::statement("ALTER TABLE hr_leave_adjustments ADD CONSTRAINT hr_leave_adjustments_reason CHECK (reason_code IN ('correction', 'in_lieu', 'other') AND halves <> 0)");
        DB::statement("CREATE UNIQUE INDEX hr_leave_openings_one_live ON hr_leave_openings (user_id, leave_type_id, year) WHERE status IN ('pending', 'confirmed')");
        DB::statement(<<<'SQL'
ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_requests_shape CHECK (
 status IN ('pending', 'approved', 'rejected', 'cancelled')
 AND start_part IN ('full', 'am', 'pm') AND end_part IN ('full', 'am')
 AND end_date >= start_date AND slot_end > slot_start AND halves >= 0
 AND (decision_reason IS NULL OR decision_reason IN ('not_enough_cover', 'dates_not_allowed', 'wrong_type', 'other', 'staff_changed_plans', 'entered_in_error'))
 AND (status NOT IN ('approved', 'rejected') OR (decided_by_user_id IS NOT NULL AND decided_at IS NOT NULL))
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> user_id)
 AND (status <> 'rejected' OR decision_reason IS NOT NULL)
 AND (status <> 'cancelled' OR (cancelled_by_user_id IS NOT NULL AND cancelled_at IS NOT NULL))
)
SQL);
        DB::statement("ALTER TABLE hr_leave_request_actions ADD CONSTRAINT hr_leave_request_actions_shape CHECK (action IN ('applied', 'approved', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE hr_leave_ledger ADD CONSTRAINT hr_leave_ledger_shape CHECK (kind IN ('grant', 'carry_forward', 'opening', 'adjustment', 'taken', 'reversal', 'expiry') AND halves <> 0 AND (kind <> 'taken' OR (halves < 0 AND leave_request_id IS NOT NULL)) AND (kind <> 'reversal' OR (halves > 0 AND leave_request_id IS NOT NULL)) AND (kind <> 'opening' OR leave_opening_id IS NOT NULL) AND (kind <> 'adjustment' OR leave_adjustment_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX hr_leave_ledger_one_per_request ON hr_leave_ledger (leave_request_id, kind) WHERE leave_request_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX hr_leave_ledger_one_carry ON hr_leave_ledger (user_id, leave_type_id, year, kind) WHERE kind IN ('carry_forward', 'expiry')");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_hr_leave_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'HR leave evidence is append-only' USING ERRCODE = 'P0001';
END $$;
CREATE TRIGGER hr_leave_ledger_append BEFORE UPDATE OR DELETE ON hr_leave_ledger FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_ledger_truncate BEFORE TRUNCATE ON hr_leave_ledger FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_entitlements_append BEFORE UPDATE OR DELETE ON hr_leave_entitlements FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_entitlements_truncate BEFORE TRUNCATE ON hr_leave_entitlements FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_request_actions_append BEFORE UPDATE OR DELETE ON hr_leave_request_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_request_actions_truncate BEFORE TRUNCATE ON hr_leave_request_actions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_retries_append BEFORE UPDATE OR DELETE ON hr_leave_retries FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_retries_truncate BEFORE TRUNCATE ON hr_leave_retries FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();

-- Requests and openings/adjustments are never deleted; a decided row never changes again.
CREATE OR REPLACE FUNCTION guard_hr_leave_decided() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'UPDATE' THEN RAISE EXCEPTION 'HR leave records are never deleted' USING ERRCODE = 'P0001'; END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.user_id <> OLD.user_id OR NEW.leave_type_id <> OLD.leave_type_id THEN
  RAISE EXCEPTION 'HR leave identity is immutable' USING ERRCODE = 'P0001';
 END IF;
 IF TG_TABLE_NAME = 'hr_leave_requests' THEN
  IF OLD.status IN ('rejected', 'cancelled') THEN RAISE EXCEPTION 'A rejected or cancelled leave request never changes' USING ERRCODE = 'P0001'; END IF;
  IF NEW.start_date <> OLD.start_date OR NEW.end_date <> OLD.end_date OR NEW.halves <> OLD.halves OR NEW.slot_start <> OLD.slot_start OR NEW.slot_end <> OLD.slot_end THEN
   RAISE EXCEPTION 'Leave request dates are immutable; cancel and apply again' USING ERRCODE = 'P0001';
  END IF;
  IF NOT ((OLD.status = 'pending' AND NEW.status IN ('pending', 'approved', 'rejected', 'cancelled')) OR (OLD.status = 'approved' AND NEW.status IN ('approved', 'cancelled'))) THEN
   RAISE EXCEPTION 'Invalid leave request transition' USING ERRCODE = '23514';
  END IF;
  IF NEW.status IN ('pending', 'approved') AND EXISTS (
    SELECT 1 FROM hr_leave_requests r WHERE r.user_id = NEW.user_id AND r.id <> NEW.id AND r.status IN ('pending', 'approved')
      AND r.slot_start < NEW.slot_end AND r.slot_end > NEW.slot_start) THEN
   RAISE EXCEPTION 'Overlapping leave request' USING ERRCODE = '23P01';
  END IF;
 ELSE
  IF OLD.status <> 'pending' THEN RAISE EXCEPTION 'A decided HR leave entry never changes' USING ERRCODE = 'P0001'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_leave_requests_guard BEFORE UPDATE OR DELETE ON hr_leave_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_decided();
CREATE TRIGGER hr_leave_requests_truncate BEFORE TRUNCATE ON hr_leave_requests FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_openings_guard BEFORE UPDATE OR DELETE ON hr_leave_openings FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_decided();
CREATE TRIGGER hr_leave_openings_truncate BEFORE TRUNCATE ON hr_leave_openings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();
CREATE TRIGGER hr_leave_adjustments_guard BEFORE UPDATE OR DELETE ON hr_leave_adjustments FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_decided();
CREATE TRIGGER hr_leave_adjustments_truncate BEFORE TRUNCATE ON hr_leave_adjustments FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_leave_append_only();

-- A new pending request may not overlap the same person's pending or approved leave.
CREATE OR REPLACE FUNCTION guard_hr_leave_request_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status <> 'pending' THEN RAISE EXCEPTION 'A leave request starts as pending' USING ERRCODE = '23514'; END IF;
 IF EXISTS (SELECT 1 FROM hr_leave_requests r WHERE r.user_id = NEW.user_id AND r.status IN ('pending', 'approved')
   AND r.slot_start < NEW.slot_end AND r.slot_end > NEW.slot_start) THEN
  RAISE EXCEPTION 'Overlapping leave request' USING ERRCODE = '23P01';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_leave_requests_insert BEFORE INSERT ON hr_leave_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_leave_request_insert();
SQL);
    }

    public function down(): void
    {
        // Leave evidence is retained, following the evidence convention.
    }
};
