<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-22f locum availability (HR-PLAN 29.7). A locum with a linked sign-in proposes a time they are free at a branch they
 * are assigned to; the Director or HR Manager (roster planners) uses it, which adds a draft roster shift inside the
 * offered time, or declines it with a fixed reason; the locum may withdraw it while it waits. A used, declined or
 * withdrawn proposal never changes again, and no proposal is ever deleted. At most 30 waiting proposals per locum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_locum_availability', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('locum_user_id');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('timezone', 64);
            $t->text('note_encrypted')->nullable();
            $t->string('status', 10); // waiting | used | declined | withdrawn
            $t->string('decline_reason', 20)->nullable();
            $t->timestampTz('proposed_at');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->unsignedBigInteger('roster_shift_id')->nullable(); // the draft roster shift a use added
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_locum_availability_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            foreach (['locum_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_locum_availability_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            // No foreign key to the roster shift: like an open-shift confirm, the draft shift stays HR's to change before approval.
            $t->index(['organisation_id', 'status', 'starts_at'], 'hr_locum_availability_list_idx');
            $t->index(['locum_user_id', 'status'], 'hr_locum_availability_locum_idx');
        });
        DB::statement("ALTER TABLE hr_locum_availability ADD CONSTRAINT hr_locum_availability_shape CHECK (
            ends_at > starts_at AND ends_at - starts_at <= interval '16 hours' AND length(timezone) > 0
            AND status IN ('waiting', 'used', 'declined', 'withdrawn') AND lock_version >= 1
            AND ((status IN ('used', 'declined')) = (decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL))
            AND ((status = 'declined') = (decline_reason IS NOT NULL))
            AND (decline_reason IS NULL OR decline_reason IN ('not_needed', 'outside_hours', 'other'))
            AND ((status = 'used') = (roster_shift_id IS NOT NULL))
            AND decided_by_user_id IS DISTINCT FROM locum_user_id)");

        DB::unprepared(<<<'SQL'
-- A proposal is made by a linked locum sign-in that holds the propose permission, assigned to the branch on its first
-- and last day, for a time that has not started, with at most 30 waiting at once. It is used, declined or withdrawn
-- once; only a roster planner, never the locum, uses or declines it; a use points at a roster shift of the locum at the
-- same branch inside the offered time.
CREATE OR REPLACE FUNCTION guard_hr_locum_availability() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'waiting' OR NEW.lock_version <> 1 OR NEW.starts_at <= NEW.proposed_at
    OR NOT hr_open_shift_assigned(NEW.locum_user_id, NEW.branch_id, (NEW.starts_at AT TIME ZONE NEW.timezone)::date)
    OR NOT hr_open_shift_assigned(NEW.locum_user_id, NEW.branch_id, ((NEW.ends_at - interval '1 second') AT TIME ZONE NEW.timezone)::date)
    OR NOT EXISTS (SELECT 1 FROM hr_locum_sign_ins l WHERE l.linked_user_id = NEW.locum_user_id AND l.status = 'done')
    OR NOT hr_user_holds(NEW.locum_user_id, 'hr.locum_availability.propose.own') THEN
   RAISE EXCEPTION 'A linked locum assigned to the branch proposes a time that has not started' USING ERRCODE = '23514';
  END IF;
  IF (SELECT count(*) FROM hr_locum_availability a WHERE a.locum_user_id = NEW.locum_user_id AND a.status = 'waiting' AND a.ends_at > NEW.proposed_at) >= 30 THEN
   RAISE EXCEPTION 'At most 30 waiting proposals per locum' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id
   OR NEW.locum_user_id <> OLD.locum_user_id OR NEW.starts_at <> OLD.starts_at OR NEW.ends_at <> OLD.ends_at OR NEW.timezone <> OLD.timezone
   OR NEW.note_encrypted IS DISTINCT FROM OLD.note_encrypted OR NEW.proposed_at <> OLD.proposed_at OR NEW.created_at IS DISTINCT FROM OLD.created_at
   OR NEW.lock_version <> OLD.lock_version + 1 OR OLD.status <> 'waiting' OR NEW.status = 'waiting' THEN
  RAISE EXCEPTION 'A proposal is used, declined or withdrawn once' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status IN ('used', 'declined') AND NOT hr_user_holds(NEW.decided_by_user_id, 'hr.roster.manage.organisation') THEN
  RAISE EXCEPTION 'Only a roster planner uses or declines a proposal' USING ERRCODE = '23514';
 END IF;
 IF NEW.status = 'used' AND NOT EXISTS (SELECT 1 FROM hr_roster_shifts r WHERE r.id = NEW.roster_shift_id AND r.staff_user_id = NEW.locum_user_id
     AND r.branch_id = NEW.branch_id AND r.starts_at >= NEW.starts_at AND r.ends_at <= NEW.ends_at) THEN
  RAISE EXCEPTION 'A used proposal points at the locum''s roster shift inside the offered time' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_locum_availability_guard BEFORE INSERT OR UPDATE ON hr_locum_availability FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_availability();
CREATE TRIGGER hr_locum_availability_no_delete BEFORE DELETE ON hr_locum_availability FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_locum_availability_no_truncate BEFORE TRUNCATE ON hr_locum_availability FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
SQL);
    }

    public function down(): void {} // HR record evidence is retained, following the evidence convention.
};
