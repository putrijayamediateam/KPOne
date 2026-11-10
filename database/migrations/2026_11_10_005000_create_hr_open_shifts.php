<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-15b open shifts (HR-PLAN 21.4). The Director or HR Manager posts an open shift at a branch; a locum with a linked
 * sign-in (HR-15a) asks for it; a manager who is not the locum confirms one locum, which adds the shift to that
 * branch's next draft roster week (it still needs the HR-1 roster approval) and declines the other asks. First come is
 * not first served. An open shift and its asks are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_open_shifts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('timezone', 64);
            $t->unsignedSmallInteger('break_minutes')->default(0);
            $t->string('session_type', 16);
            $t->string('role_needed', 16); // resident_doctor | ca | other
            $t->string('status', 10); // open | filled | withdrawn
            $t->unsignedBigInteger('posted_by_user_id');
            $t->timestampTz('posted_at');
            $t->unsignedBigInteger('closed_by_user_id')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->unique(['id', 'organisation_id'], 'hr_open_shifts_id_org_unique');
            $t->foreign(['branch_id', 'organisation_id'], 'hr_open_shifts_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            foreach (['posted_by_user_id', 'closed_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_open_shifts_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['organisation_id', 'status', 'starts_at'], 'hr_open_shifts_list_idx');
        });
        DB::statement("ALTER TABLE hr_open_shifts ADD CONSTRAINT hr_open_shifts_shape CHECK (
            ends_at > starts_at AND ends_at - starts_at <= interval '16 hours'
            AND break_minutes <= 180 AND break_minutes * interval '1 minute' < ends_at - starts_at
            AND session_type IN ('morning', 'afternoon', 'evening', 'night', 'full_day', 'other')
            AND role_needed IN ('resident_doctor', 'ca', 'other') AND length(timezone) > 0
            AND status IN ('open', 'filled', 'withdrawn') AND lock_version >= 1
            AND ((status = 'open') = (closed_at IS NULL AND closed_by_user_id IS NULL)))");

        Schema::create('hr_open_shift_requests', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('open_shift_id');
            $t->unsignedBigInteger('locum_user_id');
            $t->string('status', 10); // asked | confirmed | declined | withdrawn
            $t->string('decline_reason', 20)->nullable();
            $t->timestampTz('asked_at');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->unsignedBigInteger('roster_shift_id')->nullable(); // the draft roster shift the confirm added
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->unique(['open_shift_id', 'locum_user_id'], 'hr_open_shift_requests_once');
            $t->foreign(['open_shift_id', 'organisation_id'], 'hr_open_shift_requests_shift_fk')->references(['id', 'organisation_id'])->on('hr_open_shifts')->restrictOnDelete();
            foreach (['locum_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_open_shift_requests_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            // No foreign key: a confirmed shift is an ordinary draft shift, which HR may still change or remove in the
            // planner before approval. The guard checks it at the moment of the confirm; the ask keeps the record.
            $t->index(['locum_user_id', 'status'], 'hr_open_shift_requests_locum_idx');
        });
        DB::statement("ALTER TABLE hr_open_shift_requests ADD CONSTRAINT hr_open_shift_requests_shape CHECK (
            status IN ('asked', 'confirmed', 'declined', 'withdrawn') AND lock_version >= 1
            AND ((status IN ('confirmed', 'declined')) = (decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL))
            AND ((status = 'declined') = (decline_reason IS NOT NULL))
            AND (decline_reason IS NULL OR decline_reason IN ('filled', 'shift_withdrawn', 'not_suitable', 'not_assigned', 'other'))
            AND decided_by_user_id IS DISTINCT FROM locum_user_id)");
        // One confirmed locum per open shift.
        DB::statement("CREATE UNIQUE INDEX hr_open_shift_requests_one_confirmed ON hr_open_shift_requests (open_shift_id) WHERE status = 'confirmed'");

        DB::unprepared(<<<'SQL'
-- An open shift keeps what was posted; it is filled or withdrawn once. A manager who handles open shifts posts and
-- closes it (hr_user_holds() comes with HR-15a).
CREATE OR REPLACE FUNCTION guard_hr_open_shift() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'open' OR NEW.lock_version <> 1 OR NOT hr_user_holds(NEW.posted_by_user_id, 'hr.open_shifts.manage.organisation') THEN
   RAISE EXCEPTION 'An open shift is posted open, by someone who handles open shifts' USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id
   OR NEW.starts_at <> OLD.starts_at OR NEW.ends_at <> OLD.ends_at OR NEW.timezone <> OLD.timezone OR NEW.break_minutes <> OLD.break_minutes
   OR NEW.session_type <> OLD.session_type OR NEW.role_needed <> OLD.role_needed OR NEW.posted_by_user_id <> OLD.posted_by_user_id
   OR NEW.posted_at <> OLD.posted_at OR NEW.created_at IS DISTINCT FROM OLD.created_at OR NEW.lock_version <> OLD.lock_version + 1
   OR OLD.status <> 'open' OR NEW.status = 'open' THEN
  RAISE EXCEPTION 'An open shift keeps what was posted and is filled or withdrawn once' USING ERRCODE = 'P0001';
 END IF;
 IF NOT hr_user_holds(NEW.closed_by_user_id, 'hr.open_shifts.manage.organisation') THEN
  RAISE EXCEPTION 'Only someone who handles open shifts fills or withdraws one' USING ERRCODE = '23514';
 END IF;
 IF NEW.status = 'filled' AND NOT EXISTS (SELECT 1 FROM hr_open_shift_requests r WHERE r.open_shift_id = NEW.id AND r.status = 'confirmed') THEN
  RAISE EXCEPTION 'An open shift is filled with its confirmed locum' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_open_shifts_guard BEFORE INSERT OR UPDATE ON hr_open_shifts FOR EACH ROW EXECUTE FUNCTION guard_hr_open_shift();

-- Whether a person has a branch assignment covering a branch-local date (the rule RosterRules::assertAssigned checks).
CREATE OR REPLACE FUNCTION hr_open_shift_assigned(p_user bigint, p_branch bigint, p_date date) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM staff_branch_assignments a JOIN staff_profiles p ON p.id = a.staff_profile_id
  WHERE p.user_id = p_user AND a.branch_id = p_branch AND a.valid_from <= p_date AND (a.valid_until IS NULL OR a.valid_until >= p_date))
$$;

-- An ask is made by a linked locum sign-in, assigned to the branch on the shift's first and last day, while the shift is
-- open and before it starts. It is confirmed, declined or withdrawn once; only
-- someone who handles open shifts, never the locum, confirms or declines it; a confirm points at a roster shift of the
-- locum at the same branch and time.
CREATE OR REPLACE FUNCTION guard_hr_open_shift_request() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE s record;
BEGIN
 SELECT * INTO s FROM hr_open_shifts WHERE id = NEW.open_shift_id;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'asked' OR NEW.lock_version <> 1 OR s.status <> 'open' OR s.starts_at <= clock_timestamp()
    OR NOT hr_open_shift_assigned(NEW.locum_user_id, s.branch_id, (s.starts_at AT TIME ZONE s.timezone)::date)
    OR NOT hr_open_shift_assigned(NEW.locum_user_id, s.branch_id, ((s.ends_at - interval '1 second') AT TIME ZONE s.timezone)::date)
    OR NOT EXISTS (SELECT 1 FROM hr_locum_sign_ins l WHERE l.linked_user_id = NEW.locum_user_id AND l.status = 'done')
    OR NOT hr_user_holds(NEW.locum_user_id, 'hr.open_shifts.request.own') THEN
   RAISE EXCEPTION 'A linked locum assigned to the branch asks for an open shift before it starts' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.open_shift_id <> OLD.open_shift_id
   OR NEW.locum_user_id <> OLD.locum_user_id OR NEW.asked_at <> OLD.asked_at OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.lock_version <> OLD.lock_version + 1 OR OLD.status <> 'asked' OR NEW.status = 'asked' THEN
  RAISE EXCEPTION 'An ask is confirmed, declined or withdrawn once' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status IN ('confirmed', 'declined') AND NOT hr_user_holds(NEW.decided_by_user_id, 'hr.open_shifts.manage.organisation') THEN
  RAISE EXCEPTION 'Only someone who handles open shifts confirms or declines an ask' USING ERRCODE = '23514';
 END IF;
 IF (NEW.status = 'confirmed') <> (NEW.roster_shift_id IS NOT NULL) OR (NEW.status = 'confirmed' AND (s.status <> 'open' OR NOT EXISTS (
     SELECT 1 FROM hr_roster_shifts r WHERE r.id = NEW.roster_shift_id AND r.staff_user_id = NEW.locum_user_id AND r.branch_id = s.branch_id
     AND r.starts_at = s.starts_at AND r.ends_at = s.ends_at))) THEN
  RAISE EXCEPTION 'A confirmed ask points at the locum''s roster shift for the same branch and time' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_open_shift_requests_guard BEFORE INSERT OR UPDATE ON hr_open_shift_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_open_shift_request();

CREATE TRIGGER hr_open_shifts_no_delete BEFORE DELETE ON hr_open_shifts FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_open_shifts_no_truncate BEFORE TRUNCATE ON hr_open_shifts FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_open_shift_requests_no_delete BEFORE DELETE ON hr_open_shift_requests FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_open_shift_requests_no_truncate BEFORE TRUNCATE ON hr_open_shift_requests FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
SQL);
    }

    public function down(): void {} // HR record evidence is retained, following the evidence convention.
};
