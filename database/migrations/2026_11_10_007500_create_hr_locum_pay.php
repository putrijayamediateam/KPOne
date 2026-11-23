<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-15g locum pay statement (owner, 18 Nov 2026). KPOne works out what a locum is owed for a month from their approved
 * roster shifts and a confirmed rate, and records that the clinic paid it outside KPOne. KPOne pays nothing.
 *
 * - A rate (per hour or per session) is entered by the Director or HR Manager and counts only once a Finance Officer or
 *   Director who did not enter it confirms it. Amounts are encrypted. A rate row is never changed except for its one
 *   decision, and never removed.
 * - A shift marked "Not worked" (a reason code) pays nothing. The mark can be removed while the month is unpaid.
 * - A correction (a signed amount) belongs to an unpaid month; a correction for a paid month goes into a later month.
 * - A paid month stores its statement as it was paid and locks the month: nothing for that locum and month can be added
 *   or removed afterwards. There is no undo in v1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_locum_pay_rates', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->string('kind', 8); // hour | session
            $t->text('amount_encrypted'); // whole sen
            $t->date('effective_from');
            $t->string('status', 10); // waiting | confirmed | rejected | withdrawn
            $t->string('reject_reason', 20)->nullable();
            $t->unsignedBigInteger('entered_by_user_id');
            $t->timestampTz('entered_at');
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_locum_pay_rates_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['entered_by_user_id', 'decided_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_locum_pay_rates_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['worker_id', 'status', 'effective_from'], 'hr_locum_pay_rates_worker_idx');
        });
        DB::statement("ALTER TABLE hr_locum_pay_rates ADD CONSTRAINT hr_locum_pay_rates_shape CHECK (
            kind IN ('hour', 'session') AND status IN ('waiting', 'confirmed', 'rejected', 'withdrawn') AND lock_version >= 1
            AND length(amount_encrypted) > 0
            AND ((status IN ('confirmed', 'rejected')) = (decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL))
            AND ((status = 'rejected') = (reject_reason IS NOT NULL))
            AND (reject_reason IS NULL OR reject_reason IN ('wrong_amount', 'wrong_kind', 'wrong_date', 'not_agreed', 'other'))
            AND decided_by_user_id IS DISTINCT FROM entered_by_user_id)");
        // One rate waiting for confirmation per locum at a time.
        DB::statement("CREATE UNIQUE INDEX hr_locum_pay_rates_one_waiting ON hr_locum_pay_rates (worker_id) WHERE status = 'waiting'");

        Schema::create('hr_locum_pay_exclusions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->unsignedBigInteger('roster_shift_id');
            $t->date('month'); // the first day of the shift's branch-local month
            $t->string('reason', 20);
            $t->unsignedBigInteger('entered_by_user_id');
            $t->timestampTz('entered_at');
            $t->unsignedBigInteger('removed_by_user_id')->nullable();
            $t->timestampTz('removed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_locum_pay_exclusions_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign('roster_shift_id', 'hr_locum_pay_exclusions_shift_fk')->references('id')->on('hr_roster_shifts')->restrictOnDelete();
            foreach (['entered_by_user_id', 'removed_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_locum_pay_exclusions_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['worker_id', 'month'], 'hr_locum_pay_exclusions_month_idx');
        });
        DB::statement("ALTER TABLE hr_locum_pay_exclusions ADD CONSTRAINT hr_locum_pay_exclusions_shape CHECK (
            reason IN ('did_not_come', 'cancelled_by_clinic', 'covered_by_other', 'other') AND lock_version >= 1
            AND month = date_trunc('month', month)::date
            AND ((removed_at IS NULL) = (removed_by_user_id IS NULL)))");
        DB::statement('CREATE UNIQUE INDEX hr_locum_pay_exclusions_one_active ON hr_locum_pay_exclusions (roster_shift_id) WHERE removed_at IS NULL');

        Schema::create('hr_locum_pay_adjustments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->date('month');
            $t->text('amount_encrypted'); // signed whole sen
            $t->string('reason', 24);
            $t->date('for_month')->nullable(); // the earlier month a correction is for, when it is one
            $t->unsignedBigInteger('entered_by_user_id');
            $t->timestampTz('entered_at');
            $t->unsignedBigInteger('removed_by_user_id')->nullable();
            $t->timestampTz('removed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['worker_id', 'organisation_id'], 'hr_locum_pay_adjustments_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            foreach (['entered_by_user_id', 'removed_by_user_id'] as $column) {
                $t->foreign([$column, 'organisation_id'], "hr_locum_pay_adjustments_{$column}_fk")->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
            $t->index(['worker_id', 'month'], 'hr_locum_pay_adjustments_month_idx');
        });
        DB::statement("ALTER TABLE hr_locum_pay_adjustments ADD CONSTRAINT hr_locum_pay_adjustments_shape CHECK (
            reason IN ('earlier_month', 'extra_payment', 'deduction', 'other') AND lock_version >= 1 AND length(amount_encrypted) > 0
            AND month = date_trunc('month', month)::date
            AND (for_month IS NULL OR (for_month = date_trunc('month', for_month)::date AND for_month < month))
            AND ((reason = 'earlier_month') = (for_month IS NOT NULL))
            AND ((removed_at IS NULL) = (removed_by_user_id IS NULL)))");

        Schema::create('hr_locum_pay_months', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('worker_id');
            $t->date('month');
            $t->text('total_encrypted');
            $t->text('statement_encrypted'); // the statement as paid (JSON)
            $t->date('paid_on');
            $t->text('reference_encrypted')->nullable();
            $t->string('reference_last4', 4)->nullable();
            $t->unsignedBigInteger('paid_by_user_id');
            $t->timestampTz('recorded_at');
            $t->timestampTz('created_at');
            $t->foreign(['worker_id', 'organisation_id'], 'hr_locum_pay_months_worker_fk')->references(['id', 'organisation_id'])->on('hr_workers')->restrictOnDelete();
            $t->foreign(['paid_by_user_id', 'organisation_id'], 'hr_locum_pay_months_payer_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['worker_id', 'month'], 'hr_locum_pay_months_once');
        });
        DB::statement("ALTER TABLE hr_locum_pay_months ADD CONSTRAINT hr_locum_pay_months_shape CHECK (
            month = date_trunc('month', month)::date AND paid_on >= month
            AND length(total_encrypted) > 0 AND length(statement_encrypted) > 0
            AND ((reference_encrypted IS NULL) = (reference_last4 IS NULL)))");

        DB::unprepared(<<<'SQL'
-- A rate belongs to a locum file, and changes only by its one decision (waiting to confirmed, rejected or withdrawn).
CREATE OR REPLACE FUNCTION guard_hr_locum_pay_rate() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
  RAISE EXCEPTION 'Locum pay rates are never removed' USING ERRCODE = 'P0001';
 END IF;
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'waiting' THEN
   RAISE EXCEPTION 'A locum pay rate starts waiting for confirmation' USING ERRCODE = '23514';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM hr_workers w WHERE w.id = NEW.worker_id AND w.organisation_id = NEW.organisation_id AND w.kind = 'locum') THEN
   RAISE EXCEPTION 'A locum pay rate belongs to a locum file' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF OLD.status <> 'waiting' OR NEW.status = 'waiting'
   OR NEW.organisation_id IS DISTINCT FROM OLD.organisation_id OR NEW.worker_id IS DISTINCT FROM OLD.worker_id
   OR NEW.public_id IS DISTINCT FROM OLD.public_id OR NEW.kind IS DISTINCT FROM OLD.kind
   OR NEW.amount_encrypted IS DISTINCT FROM OLD.amount_encrypted OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
   OR NEW.entered_by_user_id IS DISTINCT FROM OLD.entered_by_user_id OR NEW.entered_at IS DISTINCT FROM OLD.entered_at THEN
  RAISE EXCEPTION 'A locum pay rate changes only by its one decision' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_locum_pay_rates_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_locum_pay_rates FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_pay_rate();
CREATE TRIGGER hr_locum_pay_rates_no_truncate BEFORE TRUNCATE ON hr_locum_pay_rates FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_locum_pay_rate();

-- A mark or a correction is added or removed only while its month is unpaid; otherwise nothing about it changes.
CREATE OR REPLACE FUNCTION guard_hr_locum_pay_item() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
  RAISE EXCEPTION 'Locum pay marks and corrections are never deleted' USING ERRCODE = 'P0001';
 END IF;
 IF TG_OP = 'UPDATE' AND (OLD.removed_at IS NOT NULL OR NEW.removed_at IS NULL
   OR (to_jsonb(NEW) - ARRAY['removed_at', 'removed_by_user_id', 'lock_version', 'updated_at'])
      IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['removed_at', 'removed_by_user_id', 'lock_version', 'updated_at'])) THEN
  RAISE EXCEPTION 'A locum pay mark or correction changes only by being removed' USING ERRCODE = 'P0001';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_workers w WHERE w.id = NEW.worker_id AND w.organisation_id = NEW.organisation_id AND w.kind = 'locum') THEN
  RAISE EXCEPTION 'A locum pay mark or correction belongs to a locum file' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM hr_locum_pay_months m WHERE m.worker_id = NEW.worker_id AND m.month = NEW.month) THEN
  RAISE EXCEPTION 'This month is paid and locked' USING ERRCODE = '23514';
 END IF;
 IF TG_TABLE_NAME = 'hr_locum_pay_exclusions' AND TG_OP = 'INSERT' AND NOT EXISTS (
   SELECT 1 FROM hr_roster_shifts s JOIN hr_roster_versions v ON v.id = s.roster_version_id
   -- The locum's account is the one linked by the newest done sign-in, as the statement reads it (Suresh, m1).
   JOIN hr_locum_sign_ins l ON l.id = (SELECT max(x.id) FROM hr_locum_sign_ins x WHERE x.worker_id = NEW.worker_id AND x.status = 'done'
     AND x.linked_user_id IS NOT NULL) AND l.linked_user_id = s.staff_user_id
   WHERE s.id = (to_jsonb(NEW) ->> 'roster_shift_id')::bigint AND s.organisation_id = NEW.organisation_id AND v.status = 'approved'
     AND date_trunc('month', s.starts_at AT TIME ZONE s.timezone)::date = NEW.month) THEN
  RAISE EXCEPTION 'A shift marked not worked is an approved shift of this locum in this month' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_locum_pay_exclusions_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_locum_pay_exclusions FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_pay_item();
CREATE TRIGGER hr_locum_pay_exclusions_no_truncate BEFORE TRUNCATE ON hr_locum_pay_exclusions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_locum_pay_item();
CREATE TRIGGER hr_locum_pay_adjustments_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_locum_pay_adjustments FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_pay_item();
CREATE TRIGGER hr_locum_pay_adjustments_no_truncate BEFORE TRUNCATE ON hr_locum_pay_adjustments FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_locum_pay_item();

-- A paid month is recorded once, for a locum file, and never changed or removed.
CREATE OR REPLACE FUNCTION guard_hr_locum_pay_month() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN
  RAISE EXCEPTION 'A paid locum month is never changed or removed' USING ERRCODE = 'P0001';
 END IF;
 IF NOT EXISTS (SELECT 1 FROM hr_workers w WHERE w.id = NEW.worker_id AND w.organisation_id = NEW.organisation_id AND w.kind = 'locum') THEN
  RAISE EXCEPTION 'A paid locum month belongs to a locum file' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_locum_pay_months_guard BEFORE INSERT OR UPDATE OR DELETE ON hr_locum_pay_months FOR EACH ROW EXECUTE FUNCTION guard_hr_locum_pay_month();
CREATE TRIGGER hr_locum_pay_months_no_truncate BEFORE TRUNCATE ON hr_locum_pay_months FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_locum_pay_month();
SQL);
    }

    public function down(): void
    {
        foreach (['hr_locum_pay_months', 'hr_locum_pay_rates', 'hr_locum_pay_adjustments', 'hr_locum_pay_exclusions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Locum pay records exist; this migration cannot be rolled back without losing them.');
            }
        }
        DB::unprepared('DROP TABLE IF EXISTS hr_locum_pay_months; DROP TABLE IF EXISTS hr_locum_pay_adjustments; DROP TABLE IF EXISTS hr_locum_pay_exclusions;
            DROP TABLE IF EXISTS hr_locum_pay_rates; DROP FUNCTION IF EXISTS guard_hr_locum_pay_month(); DROP FUNCTION IF EXISTS guard_hr_locum_pay_item();
            DROP FUNCTION IF EXISTS guard_hr_locum_pay_rate();');
    }
};
