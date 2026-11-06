<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-3b overtime (owner, 2026-11-06: "approve the HR-3b note with the defaults"). A staff member asks for overtime
 * minutes on one of their closed clock sessions; someone other than the subject and the requester approves, lowers or
 * rejects it. Minutes and day type only: no money. Settings are clinic-entered versions (nothing is seeded), kept apart
 * from HR-3a's append-only attendance settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_overtime_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->date('effective_from');
            $t->decimal('normal_multiplier', 4, 2);
            $t->decimal('rest_day_multiplier', 4, 2);
            $t->decimal('public_holiday_multiplier', 4, 2);
            $t->unsignedSmallInteger('nearing_hours');
            $t->unsignedSmallInteger('limit_hours');
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_overtime_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_overtime_settings_creator_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_overtime_settings_scope_unique');
            $t->index(['organisation_id', 'effective_from'], 'hr_overtime_settings_lookup');
        });

        Schema::create('hr_overtime_setting_roles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('overtime_setting_id');
            $t->string('role_name', 64);
            $t->foreign('overtime_setting_id', 'hr_overtime_setting_roles_setting_fk')->references('id')->on('hr_overtime_settings')->restrictOnDelete();
            $t->unique(['overtime_setting_id', 'role_name'], 'hr_overtime_setting_roles_unique');
        });

        Schema::create('hr_overtime_requests', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('user_id'); // the subject
            $t->unsignedBigInteger('requested_by_user_id');
            $t->unsignedBigInteger('clock_session_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('local_date');
            $t->unsignedSmallInteger('minutes_requested');
            $t->string('reason_code', 16);
            $t->text('note_encrypted')->nullable();
            $t->string('day_type', 16); // normal | rest_day | public_holiday (as worked out at the ask, then at the decision)
            $t->string('status', 12)->default('pending');
            $t->unsignedSmallInteger('minutes_approved')->nullable();
            $t->unsignedBigInteger('overtime_setting_id')->nullable();
            $t->decimal('multiplier', 4, 2)->nullable();
            $t->unsignedSmallInteger('worked_minutes_at_decision')->nullable(); // so a later correction can be shown as "recorded → now"
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('decision_reason', 16)->nullable();
            $t->unsignedBigInteger('reversed_by_user_id')->nullable();
            $t->timestampTz('reversed_at')->nullable();
            $t->string('reverse_reason', 16)->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_overtime_requests_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['clock_session_id', 'organisation_id'], 'hr_overtime_requests_session_fk')->references(['id', 'organisation_id'])->on('hr_clock_sessions')->restrictOnDelete();
            $t->foreign(['overtime_setting_id', 'organisation_id'], 'hr_overtime_requests_setting_fk')->references(['id', 'organisation_id'])->on('hr_overtime_settings')->restrictOnDelete();
            foreach (['user_id', 'requested_by_user_id', 'decided_by_user_id', 'reversed_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_overtime_requests_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['organisation_id', 'status'], 'hr_overtime_requests_status');
            $t->index(['user_id', 'local_date'], 'hr_overtime_requests_user_day');
        });

        DB::statement(<<<'SQL'
ALTER TABLE hr_overtime_settings ADD CONSTRAINT hr_overtime_settings_shape CHECK (
 normal_multiplier BETWEEN 1 AND 5 AND rest_day_multiplier BETWEEN 1 AND 5 AND public_holiday_multiplier BETWEEN 1 AND 5
 AND nearing_hours BETWEEN 1 AND 167 AND limit_hours BETWEEN 2 AND 168 AND nearing_hours < limit_hours
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_overtime_requests ADD CONSTRAINT hr_overtime_requests_shape CHECK (
 status IN ('pending', 'approved', 'rejected', 'withdrawn', 'reversed')
 AND reason_code IN ('patient_load', 'cover_absence', 'stayed_to_finish', 'asked_by_manager', 'other')
 AND day_type IN ('normal', 'rest_day', 'public_holiday')
 AND minutes_requested BETWEEN 1 AND 1440
 AND (status IN ('pending', 'withdrawn')) = (decided_by_user_id IS NULL)
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (status IN ('approved', 'reversed')) = (minutes_approved IS NOT NULL)
 AND (status IN ('approved', 'reversed')) = (overtime_setting_id IS NOT NULL)
 AND (status IN ('approved', 'reversed')) = (multiplier IS NOT NULL)
 AND (status IN ('approved', 'reversed')) = (worked_minutes_at_decision IS NOT NULL)
 AND (worked_minutes_at_decision IS NULL OR worked_minutes_at_decision BETWEEN 0 AND 1440)
 AND (status IN ('approved', 'rejected', 'reversed') OR decision_reason IS NULL)
 AND (minutes_approved IS NULL OR (minutes_approved >= 1 AND minutes_approved <= minutes_requested))
 AND (decided_by_user_id IS NULL OR (decided_by_user_id <> user_id AND decided_by_user_id <> requested_by_user_id))
 AND (status <> 'rejected' OR decision_reason IN ('not_agreed', 'not_overtime', 'wrong_day', 'other'))
 AND (status NOT IN ('approved', 'reversed') OR (minutes_approved = minutes_requested AND decision_reason IS NULL)
   OR (minutes_approved < minutes_requested AND decision_reason IN ('not_needed', 'more_than_agreed', 'other')))
 AND (status = 'reversed') = (reversed_by_user_id IS NOT NULL)
 AND (reversed_by_user_id IS NULL) = (reversed_at IS NULL)
 AND (status <> 'reversed' OR (reversed_by_user_id <> user_id AND reverse_reason IN ('mistake', 'hours_changed', 'other')))
 AND (status = 'reversed' OR reverse_reason IS NULL)
)
SQL);
        DB::statement("CREATE UNIQUE INDEX hr_overtime_requests_one_active ON hr_overtime_requests (clock_session_id) WHERE status IN ('pending', 'approved')");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_overtime_settings_append BEFORE UPDATE OR DELETE ON hr_overtime_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_overtime_settings_truncate BEFORE TRUNCATE ON hr_overtime_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_overtime_setting_roles_append BEFORE UPDATE OR DELETE ON hr_overtime_setting_roles FOR EACH ROW EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_overtime_setting_roles_truncate BEFORE TRUNCATE ON hr_overtime_setting_roles FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();
CREATE TRIGGER hr_overtime_requests_truncate BEFORE TRUNCATE ON hr_overtime_requests FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_attendance_append_only();

-- A request starts pending on a closed session of the same person and organisation. It is decided as asked: only the
-- decision columns (and the day type, worked out again at the decision) change, once; an approved one may be reversed
-- once; nothing else ever changes and nothing is deleted.
CREATE OR REPLACE FUNCTION guard_hr_overtime_request() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s record;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'HR overtime requests are never deleted' USING ERRCODE = 'P0001'; END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'pending' THEN RAISE EXCEPTION 'An overtime request starts as pending' USING ERRCODE = '23514'; END IF;
  SELECT user_id, organisation_id, branch_id, local_date, status INTO s FROM hr_clock_sessions WHERE id = NEW.clock_session_id;
  IF s.user_id IS DISTINCT FROM NEW.user_id OR s.organisation_id IS DISTINCT FROM NEW.organisation_id OR s.branch_id IS DISTINCT FROM NEW.branch_id
    OR s.local_date IS DISTINCT FROM NEW.local_date THEN
   RAISE EXCEPTION 'An overtime request belongs to a session of the same person' USING ERRCODE = '23514';
  END IF;
  IF s.status <> 'closed' THEN RAISE EXCEPTION 'Overtime is asked for on a closed session' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.user_id <> OLD.user_id
   OR NEW.requested_by_user_id <> OLD.requested_by_user_id OR NEW.clock_session_id <> OLD.clock_session_id OR NEW.branch_id <> OLD.branch_id
   OR NEW.local_date <> OLD.local_date OR NEW.minutes_requested <> OLD.minutes_requested OR NEW.reason_code <> OLD.reason_code
   OR NEW.note_encrypted IS DISTINCT FROM OLD.note_encrypted OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'An overtime request is decided as asked; withdraw it and ask again' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'pending' AND NEW.status IN ('approved', 'rejected', 'withdrawn') THEN
  IF NEW.status <> 'approved' AND NEW.day_type <> OLD.day_type THEN
   RAISE EXCEPTION 'Only an approval works the day type out again' USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status = 'approved' AND NEW.status = 'reversed' THEN
  IF NEW.day_type <> OLD.day_type OR NEW.minutes_approved <> OLD.minutes_approved OR NEW.overtime_setting_id <> OLD.overtime_setting_id
    OR NEW.multiplier <> OLD.multiplier OR NEW.worked_minutes_at_decision <> OLD.worked_minutes_at_decision OR NEW.decided_by_user_id <> OLD.decided_by_user_id OR NEW.decided_at <> OLD.decided_at
    OR NEW.decision_reason IS DISTINCT FROM OLD.decision_reason THEN
   RAISE EXCEPTION 'A reversal keeps the approval as it was' USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
 END IF;
 RAISE EXCEPTION 'A decided overtime request never changes' USING ERRCODE = 'P0001';
END $$;
CREATE TRIGGER hr_overtime_requests_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_overtime_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_overtime_request();

SQL);
    }

    public function down(): void
    {
        // Overtime evidence is retained, following the evidence convention.
    }
};
