<?php

use App\Domain\Visit\Billing\Services\ExactMoney;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-30 (owner item 13, round 2 P6, P7 and 18 = A, BIL-30 answers 1A, 2 and 3): monthly sales targets per branch and,
 * optionally, per doctor at that branch. A target is read against existing net sales; nothing here moves money.
 *
 * - One target row per (organisation, branch, month, doctor or none). A clear is soft (K3): the amount becomes null
 *   and the row keeps its lock version, so a later set continues the same history.
 * - Past months are fully locked (answer 1A, K5): insert or change of a month before the branch's current local month
 *   is refused here, by the database clock, inside the transaction. The domain checks the same first with a plain
 *   message.
 * - Every set, change or clear appends one revision row; revisions are never changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('month');
            $t->unsignedBigInteger('doctor_user_id')->nullable();
            $t->bigInteger('amount_sen')->nullable();
            $t->timestampTz('cleared_at')->nullable();
            $t->unsignedInteger('lock_version')->default(0);
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedBigInteger('updated_by_user_id');
            $t->timestampsTz();
            $t->foreign(['branch_id', 'organisation_id'], 'sales_targets_branch_owner_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['doctor_user_id', 'organisation_id'], 'sales_targets_doctor_owner_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'sales_targets_creator_owner_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['updated_by_user_id', 'organisation_id'], 'sales_targets_updater_owner_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id', 'branch_id'], 'sales_targets_tenant_unique');
            $t->index(['organisation_id', 'month', 'branch_id'], 'sales_targets_month_index');
        });

        Schema::create('sales_target_revisions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('sales_target_id');
            $t->date('month');
            $t->unsignedBigInteger('doctor_user_id')->nullable();
            $t->string('action', 16);
            $t->bigInteger('old_amount_sen')->nullable();
            $t->bigInteger('new_amount_sen')->nullable();
            $t->string('reason', 200)->nullable();
            $t->unsignedInteger('target_version');
            $t->unsignedBigInteger('actor_user_id');
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->foreign(['sales_target_id', 'organisation_id', 'branch_id'], 'sales_target_revisions_target_fk')->references(['id', 'organisation_id', 'branch_id'])->on('sales_targets')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'sales_target_revisions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['doctor_user_id', 'organisation_id'], 'sales_target_revisions_doctor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'sales_target_revisions_key_unique');
            $t->index(['sales_target_id', 'id'], 'sales_target_revisions_target_index');
        });

        $max = ExactMoney::MAX_SEN;
        DB::unprepared(<<<SQL
CREATE UNIQUE INDEX sales_targets_one_per_scope ON sales_targets (organisation_id, branch_id, month, COALESCE(doctor_user_id, 0));
ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_month_first CHECK (month = date_trunc('month', month)::date);
ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_amount CHECK (
  (cleared_at IS NULL AND amount_sen BETWEEN 1 AND {$max}) OR (cleared_at IS NOT NULL AND amount_sen IS NULL));
ALTER TABLE sales_target_revisions ADD CONSTRAINT sales_target_revisions_action CHECK (action IN ('set', 'changed', 'cleared'));
ALTER TABLE sales_target_revisions ADD CONSTRAINT sales_target_revisions_amounts CHECK (
  (action = 'set' AND new_amount_sen BETWEEN 1 AND {$max})
  OR (action = 'changed' AND old_amount_sen IS NOT NULL AND new_amount_sen BETWEEN 1 AND {$max} AND reason IS NOT NULL)
  OR (action = 'cleared' AND old_amount_sen IS NOT NULL AND new_amount_sen IS NULL AND reason IS NOT NULL));
ALTER TABLE sales_target_revisions ADD CONSTRAINT sales_target_revisions_month_first CHECK (month = date_trunc('month', month)::date);
SQL);

        DB::unprepared(<<<'SQL'
-- A target month is open while it is the branch's current local month or later (answer 1A). The database clock is
-- read inside the transaction, so a set that commits after local midnight on the 1st is refused.
CREATE OR REPLACE FUNCTION kpone_sales_target_month_open(p_branch bigint, p_month date) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT p_month >= date_trunc('month', now() AT TIME ZONE b.timezone)::date FROM branches b WHERE b.id = p_branch
$$;

CREATE OR REPLACE FUNCTION guard_sales_target() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' OR TG_OP = 'TRUNCATE' THEN
   RAISE EXCEPTION 'Sales targets are cleared, never deleted' USING ERRCODE = 'P0001';
 END IF;
 IF TG_OP = 'UPDATE' AND (NEW.organisation_id <> OLD.organisation_id OR NEW.branch_id <> OLD.branch_id
     OR NEW.month <> OLD.month OR NEW.doctor_user_id IS DISTINCT FROM OLD.doctor_user_id
     OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.lock_version <> OLD.lock_version + 1) THEN
   RAISE EXCEPTION 'A sales target keeps its scope and moves one version at a time' USING ERRCODE = '23514';
 END IF;
 IF NOT COALESCE(kpone_sales_target_month_open(NEW.branch_id, NEW.month), false) THEN
   RAISE EXCEPTION 'Sales targets for a past month cannot be changed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_targets_guard BEFORE INSERT OR UPDATE OR DELETE ON sales_targets FOR EACH ROW EXECUTE FUNCTION guard_sales_target();
CREATE TRIGGER sales_targets_truncate BEFORE TRUNCATE ON sales_targets FOR EACH STATEMENT EXECUTE FUNCTION guard_sales_target();

CREATE OR REPLACE FUNCTION guard_sales_target_revision() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Sales target revisions are append-only' USING ERRCODE = 'P0001'; END IF;
 IF NOT EXISTS (SELECT 1 FROM sales_targets t WHERE t.id = NEW.sales_target_id AND t.organisation_id = NEW.organisation_id
     AND t.branch_id = NEW.branch_id AND t.month = NEW.month AND t.doctor_user_id IS NOT DISTINCT FROM NEW.doctor_user_id
     AND t.lock_version = NEW.target_version AND t.amount_sen IS NOT DISTINCT FROM NEW.new_amount_sen) THEN
   RAISE EXCEPTION 'A sales target revision must match its target as saved' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER sales_target_revisions_append BEFORE INSERT OR UPDATE OR DELETE ON sales_target_revisions FOR EACH ROW EXECUTE FUNCTION guard_sales_target_revision();
CREATE TRIGGER sales_target_revisions_truncate BEFORE TRUNCATE ON sales_target_revisions FOR EACH STATEMENT EXECUTE FUNCTION guard_sales_target_revision();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_target_revisions');
        Schema::dropIfExists('sales_targets');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_sales_target_revision(); DROP FUNCTION IF EXISTS guard_sales_target(); DROP FUNCTION IF EXISTS kpone_sales_target_month_open(bigint, date);');
    }
};
