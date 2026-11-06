<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR-6 staff claims (HR-PLAN 13.3). Staff claim a refund of what they paid for the clinic. KPOne ships no category,
 * limit or rule: categories and settings are clinic-entered versions. A claim is decided as sent and never edited; the
 * approver is never the claimant, and the payer is never the claimant or the approver. KPOne pays nothing: a payment
 * row records that someone paid outside KPOne, and a mistake is corrected by a reversal row. Staff claims share nothing
 * with Panel Claims or clinic money. Nothing is deleted or truncated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_claim_settings', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('director_level_sen')->nullable(); // claims above it need a Director
            $t->unsignedSmallInteger('days_back')->nullable(); // how far back a claim may go; null: no limit
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign('organisation_id', 'hr_claim_settings_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_claim_settings_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'version'], 'hr_claim_settings_once');
        });

        Schema::create('hr_claim_categories', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedInteger('current_version');
            $t->unsignedBigInteger('created_by_user_id');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign('organisation_id', 'hr_claim_categories_org_fk')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'], 'hr_claim_categories_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_claim_categories_scope_unique');
        });

        Schema::create('hr_claim_category_versions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('category_id');
            $t->unsignedInteger('version');
            $t->string('name', 60);
            $t->boolean('needs_receipt');
            $t->unsignedBigInteger('per_claim_limit_sen')->nullable();
            $t->unsignedBigInteger('monthly_limit_sen')->nullable();
            $t->boolean('is_active');
            $t->unsignedBigInteger('set_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['category_id', 'organisation_id'], 'hr_claim_category_versions_category_fk')->references(['id', 'organisation_id'])->on('hr_claim_categories')->restrictOnDelete();
            $t->foreign(['set_by_user_id', 'organisation_id'], 'hr_claim_category_versions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['category_id', 'version'], 'hr_claim_category_versions_once');
        });

        Schema::create('hr_claim_counters', function (Blueprint $t): void {
            $t->unsignedBigInteger('organisation_id')->primary();
            $t->unsignedInteger('last_number');
            $t->foreign('organisation_id', 'hr_claim_counters_org_fk')->references('id')->on('organisations')->restrictOnDelete();
        });

        Schema::create('hr_staff_claims', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->string('number', 9); // SC-000001
            $t->unsignedBigInteger('claimant_user_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('category_id');
            $t->unsignedInteger('category_version');
            $t->unsignedInteger('settings_version')->nullable(); // null: no settings saved when sent
            $t->date('spent_on');
            $t->unsignedBigInteger('amount_sen');
            $t->text('purpose_encrypted');
            $t->string('status', 10)->default('waiting'); // waiting | approved | rejected | withdrawn | paid
            $t->unsignedBigInteger('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->string('reject_reason', 16)->nullable();
            $t->unsignedBigInteger('copied_from_claim_id')->nullable(); // "Send a new claim" after a Wrong amount rejection
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestampsTz();
            $t->foreign(['claimant_user_id', 'organisation_id'], 'hr_staff_claims_claimant_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['decided_by_user_id', 'organisation_id'], 'hr_staff_claims_decider_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'hr_staff_claims_branch_fk')->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['category_id', 'organisation_id'], 'hr_staff_claims_category_fk')->references(['id', 'organisation_id'])->on('hr_claim_categories')->restrictOnDelete();
            $t->unique(['id', 'organisation_id'], 'hr_staff_claims_scope_unique');
            $t->unique(['organisation_id', 'number'], 'hr_staff_claims_number_unique');
            $t->index(['organisation_id', 'status'], 'hr_staff_claims_status');
            $t->index(['claimant_user_id', 'status'], 'hr_staff_claims_claimant');
        });
        Schema::table('hr_staff_claims', function (Blueprint $t): void {
            $t->foreign(['copied_from_claim_id', 'organisation_id'], 'hr_staff_claims_copied_fk')->references(['id', 'organisation_id'])->on('hr_staff_claims')->restrictOnDelete();
        });

        Schema::create('hr_staff_claim_receipts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('claim_id');
            $t->unsignedSmallInteger('position');
            $t->string('kind', 8); // pdf | jpeg | png
            $t->unsignedInteger('bytes');
            $t->char('sha256', 64);
            $t->string('stored_path', 200);
            $t->timestampTz('created_at');
            $t->foreign(['claim_id', 'organisation_id'], 'hr_staff_claim_receipts_claim_fk')->references(['id', 'organisation_id'])->on('hr_staff_claims')->restrictOnDelete();
            $t->unique(['claim_id', 'position'], 'hr_staff_claim_receipts_position');
            $t->index(['organisation_id', 'sha256'], 'hr_staff_claim_receipts_hash');
        });

        Schema::create('hr_staff_claim_actions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('claim_id');
            $t->string('action', 18); // sent | approved | rejected | withdrawn | paid | payment_reversed | receipt_opened
            $t->unsignedBigInteger('actor_user_id');
            $t->string('reason_code', 16)->nullable();
            $t->text('note_encrypted')->nullable();
            $t->unsignedInteger('settings_version')->nullable();
            $t->unsignedInteger('category_version')->nullable();
            $t->timestampTz('created_at');
            $t->foreign(['claim_id', 'organisation_id'], 'hr_staff_claim_actions_claim_fk')->references(['id', 'organisation_id'])->on('hr_staff_claims')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'], 'hr_staff_claim_actions_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['claim_id', 'id'], 'hr_staff_claim_actions_claim');
        });

        Schema::create('hr_staff_claim_payments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('claim_id');
            $t->string('kind', 8); // payment | reversal
            $t->unsignedBigInteger('reverses_payment_id')->nullable();
            $t->string('method', 14)->nullable(); // bank_transfer | cash | cheque
            $t->date('paid_on')->nullable();
            $t->text('reference_encrypted')->nullable();
            $t->string('reference_last4', 4)->nullable();
            $t->string('reason_code', 16)->nullable(); // reversals only
            $t->unsignedBigInteger('recorded_by_user_id');
            $t->timestampTz('created_at');
            $t->foreign(['claim_id', 'organisation_id'], 'hr_staff_claim_payments_claim_fk')->references(['id', 'organisation_id'])->on('hr_staff_claims')->restrictOnDelete();
            $t->foreign(['recorded_by_user_id', 'organisation_id'], 'hr_staff_claim_payments_actor_fk')->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->foreign('reverses_payment_id', 'hr_staff_claim_payments_reverses_fk')->references('id')->on('hr_staff_claim_payments')->restrictOnDelete();
            $t->unique('reverses_payment_id', 'hr_staff_claim_payments_reversed_once');
            $t->index(['claim_id', 'id'], 'hr_staff_claim_payments_claim');
        });

        DB::statement('ALTER TABLE hr_claim_settings ADD CONSTRAINT hr_claim_settings_shape CHECK (version >= 1 AND (director_level_sen IS NULL OR director_level_sen BETWEEN 1 AND 99999999) AND (days_back IS NULL OR days_back BETWEEN 1 AND 366))');
        DB::statement('ALTER TABLE hr_claim_categories ADD CONSTRAINT hr_claim_categories_shape CHECK (current_version >= 1)');
        DB::statement('ALTER TABLE hr_claim_category_versions ADD CONSTRAINT hr_claim_category_versions_shape CHECK (version >= 1 AND char_length(btrim(name)) BETWEEN 2 AND 60
            AND (per_claim_limit_sen IS NULL OR per_claim_limit_sen BETWEEN 1 AND 99999999) AND (monthly_limit_sen IS NULL OR monthly_limit_sen BETWEEN 1 AND 99999999))');
        DB::statement('ALTER TABLE hr_claim_counters ADD CONSTRAINT hr_claim_counters_shape CHECK (last_number BETWEEN 0 AND 999999)');
        DB::statement(<<<'SQL'
ALTER TABLE hr_staff_claims ADD CONSTRAINT hr_staff_claims_shape CHECK (
 number ~ '^SC-[0-9]{6}$'
 AND amount_sen BETWEEN 1 AND 99999999
 AND status IN ('waiting', 'approved', 'rejected', 'withdrawn', 'paid')
 AND (decided_by_user_id IS NULL) = (decided_at IS NULL)
 AND (status IN ('waiting', 'withdrawn')) = (decided_by_user_id IS NULL)
 AND (status = 'rejected') = (reject_reason IS NOT NULL)
 AND (reject_reason IS NULL OR reject_reason IN ('no_receipt', 'not_work', 'over_limit', 'wrong_amount', 'duplicate', 'other'))
 AND (decided_by_user_id IS NULL OR decided_by_user_id <> claimant_user_id)
 AND (copied_from_claim_id IS NULL OR copied_from_claim_id <> id)
)
SQL);
        DB::statement("ALTER TABLE hr_staff_claim_receipts ADD CONSTRAINT hr_staff_claim_receipts_shape CHECK (position BETWEEN 1 AND 5 AND kind IN ('pdf', 'jpeg', 'png') AND bytes > 0 AND sha256 ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE hr_staff_claim_actions ADD CONSTRAINT hr_staff_claim_actions_shape CHECK (action IN ('sent', 'approved', 'rejected', 'withdrawn', 'paid', 'payment_reversed', 'receipt_opened'))");
        DB::statement(<<<'SQL'
ALTER TABLE hr_staff_claim_payments ADD CONSTRAINT hr_staff_claim_payments_shape CHECK (
 (kind = 'payment' AND reverses_payment_id IS NULL AND method IN ('bank_transfer', 'cash', 'cheque') AND paid_on IS NOT NULL AND reason_code IS NULL
   AND (reference_encrypted IS NULL) = (reference_last4 IS NULL))
 OR (kind = 'reversal' AND reverses_payment_id IS NOT NULL AND method IS NULL AND paid_on IS NULL AND reference_encrypted IS NULL AND reference_last4 IS NULL
   AND reason_code IN ('wrong_claim', 'wrong_amount', 'payment_failed', 'other'))
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hr_claim_settings_append BEFORE UPDATE OR DELETE ON hr_claim_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_claim_category_versions_append BEFORE UPDATE OR DELETE ON hr_claim_category_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claim_receipts_append BEFORE UPDATE OR DELETE ON hr_staff_claim_receipts FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claim_actions_append BEFORE UPDATE OR DELETE ON hr_staff_claim_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claim_payments_append BEFORE UPDATE OR DELETE ON hr_staff_claim_payments FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_claim_categories_no_delete BEFORE DELETE ON hr_claim_categories FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claims_no_delete BEFORE DELETE ON hr_staff_claims FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_claim_counters_no_delete BEFORE DELETE ON hr_claim_counters FOR EACH ROW EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_claim_settings_truncate BEFORE TRUNCATE ON hr_claim_settings FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_claim_categories_truncate BEFORE TRUNCATE ON hr_claim_categories FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_claim_category_versions_truncate BEFORE TRUNCATE ON hr_claim_category_versions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_claim_counters_truncate BEFORE TRUNCATE ON hr_claim_counters FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claims_truncate BEFORE TRUNCATE ON hr_staff_claims FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claim_receipts_truncate BEFORE TRUNCATE ON hr_staff_claim_receipts FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claim_actions_truncate BEFORE TRUNCATE ON hr_staff_claim_actions FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();
CREATE TRIGGER hr_staff_claim_payments_truncate BEFORE TRUNCATE ON hr_staff_claim_payments FOR EACH STATEMENT EXECUTE FUNCTION guard_hr_records_append_only();

-- Settings and category versions are numbered without gaps; a category moves only to its next version.
CREATE OR REPLACE FUNCTION guard_hr_claim_settings_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE top integer;
BEGIN
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_claim_settings WHERE organisation_id = NEW.organisation_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Claim settings versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_claim_settings_gapless BEFORE INSERT ON hr_claim_settings FOR EACH ROW EXECUTE FUNCTION guard_hr_claim_settings_version();

CREATE OR REPLACE FUNCTION guard_hr_claim_category_version() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE top integer;
BEGIN
 SELECT COALESCE(MAX(version), 0) INTO top FROM hr_claim_category_versions WHERE category_id = NEW.category_id;
 IF NEW.version <> top + 1 THEN RAISE EXCEPTION 'Claim category versions are numbered without gaps' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_claim_category_versions_gapless BEFORE INSERT ON hr_claim_category_versions FOR EACH ROW EXECUTE FUNCTION guard_hr_claim_category_version();

CREATE OR REPLACE FUNCTION guard_hr_claim_category() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id
   OR NEW.created_by_user_id <> OLD.created_by_user_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A claim category keeps who made it' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.current_version <> OLD.current_version AND (NEW.current_version <> OLD.current_version + 1
   OR NOT EXISTS (SELECT 1 FROM hr_claim_category_versions WHERE category_id = NEW.id AND version = NEW.current_version)) THEN
  RAISE EXCEPTION 'A claim category moves to its next saved version only' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_claim_categories_guard BEFORE UPDATE ON hr_claim_categories FOR EACH ROW EXECUTE FUNCTION guard_hr_claim_category();

-- A claim number only goes up, by one.
CREATE OR REPLACE FUNCTION guard_hr_claim_counter() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.organisation_id <> OLD.organisation_id OR NEW.last_number <> OLD.last_number + 1 THEN
  RAISE EXCEPTION 'Staff claim numbers only go up by one' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_claim_counters_guard BEFORE UPDATE ON hr_claim_counters FOR EACH ROW EXECUTE FUNCTION guard_hr_claim_counter();

-- A claim is sent as waiting, for its claimant's own organisation and a category version that exists; what was sent
-- never changes; it moves waiting -> approved | rejected | withdrawn, approved -> paid, paid -> approved (reversal);
-- and a decider is never the claimant.
CREATE OR REPLACE FUNCTION guard_hr_staff_claim() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'INSERT' THEN
  IF NEW.status <> 'waiting' THEN RAISE EXCEPTION 'A staff claim is sent as waiting for approval' USING ERRCODE = '23514'; END IF;
  IF NOT EXISTS (SELECT 1 FROM hr_claim_category_versions v JOIN hr_claim_categories c ON c.id = v.category_id
    WHERE v.category_id = NEW.category_id AND v.version = NEW.category_version AND v.version = c.current_version AND v.is_active) THEN
   RAISE EXCEPTION 'A staff claim uses the current, active version of its category' USING ERRCODE = '23514';
  END IF;
  IF NEW.copied_from_claim_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM hr_staff_claims WHERE id = NEW.copied_from_claim_id
    AND claimant_user_id = NEW.claimant_user_id AND status = 'rejected' AND reject_reason = 'wrong_amount') THEN
   RAISE EXCEPTION 'Only your own claim rejected for a wrong amount is sent again' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.id <> OLD.id OR NEW.public_id <> OLD.public_id OR NEW.organisation_id <> OLD.organisation_id OR NEW.number <> OLD.number
   OR NEW.claimant_user_id <> OLD.claimant_user_id OR NEW.branch_id <> OLD.branch_id OR NEW.category_id <> OLD.category_id
   OR NEW.category_version <> OLD.category_version OR NEW.settings_version IS DISTINCT FROM OLD.settings_version
   OR NEW.spent_on <> OLD.spent_on OR NEW.amount_sen <> OLD.amount_sen OR NEW.purpose_encrypted <> OLD.purpose_encrypted
   OR NEW.copied_from_claim_id IS DISTINCT FROM OLD.copied_from_claim_id OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
  RAISE EXCEPTION 'A staff claim is never edited after it is sent' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status = 'paid' AND NEW.status = 'paid' THEN
  RAISE EXCEPTION 'A paid staff claim changes only when its payment is reversed' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status <> OLD.status AND NOT (
   (OLD.status = 'waiting' AND NEW.status IN ('approved', 'rejected', 'withdrawn'))
   OR (OLD.status = 'approved' AND NEW.status = 'paid')
   OR (OLD.status = 'paid' AND NEW.status = 'approved')) THEN
  RAISE EXCEPTION 'A staff claim moves waiting to approved, rejected or withdrawn, approved to paid, and paid back to approved only by a reversal' USING ERRCODE = 'P0001';
 END IF;
 IF OLD.status IN ('approved', 'paid') AND (NEW.decided_by_user_id IS DISTINCT FROM OLD.decided_by_user_id OR NEW.decided_at IS DISTINCT FROM OLD.decided_at) THEN
  RAISE EXCEPTION 'An approved claim keeps who approved it' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status = 'paid' AND OLD.status = 'approved' AND NOT EXISTS (SELECT 1 FROM hr_staff_claim_payments p WHERE p.claim_id = NEW.id AND p.kind = 'payment'
   AND NOT EXISTS (SELECT 1 FROM hr_staff_claim_payments r WHERE r.reverses_payment_id = p.id)) THEN
  RAISE EXCEPTION 'A staff claim is paid only with a payment recorded' USING ERRCODE = 'P0001';
 END IF;
 IF NEW.status = 'approved' AND OLD.status = 'paid' AND EXISTS (SELECT 1 FROM hr_staff_claim_payments p WHERE p.claim_id = NEW.id AND p.kind = 'payment'
   AND NOT EXISTS (SELECT 1 FROM hr_staff_claim_payments r WHERE r.reverses_payment_id = p.id)) THEN
  RAISE EXCEPTION 'A staff claim goes back to approved only when its payment is reversed' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_staff_claims_guard BEFORE INSERT OR UPDATE ON hr_staff_claims FOR EACH ROW EXECUTE FUNCTION guard_hr_staff_claim();

-- A payment is recorded for an approved claim with no live payment, by someone who is neither the claimant nor the
-- approver; a reversal points to a payment of the same claim.
CREATE OR REPLACE FUNCTION guard_hr_staff_claim_payment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c RECORD; p RECORD;
BEGIN
 -- The claim row lock serialises payments in the database itself, so the live-payment check below sees committed rows
 -- even for writes that bypass the service (Suresh, HR-6 S1).
 SELECT status, claimant_user_id, decided_by_user_id, organisation_id INTO c FROM hr_staff_claims WHERE id = NEW.claim_id FOR UPDATE;
 IF NEW.recorded_by_user_id = c.claimant_user_id OR NEW.recorded_by_user_id = c.decided_by_user_id THEN
  RAISE EXCEPTION 'The payer is never the claimant or the approver' USING ERRCODE = '23514';
 END IF;
 IF NEW.kind = 'payment' THEN
  IF c.status <> 'approved' THEN RAISE EXCEPTION 'Only an approved claim is paid' USING ERRCODE = '23514'; END IF;
  IF EXISTS (SELECT 1 FROM hr_staff_claim_payments x WHERE x.claim_id = NEW.claim_id AND x.kind = 'payment'
    AND NOT EXISTS (SELECT 1 FROM hr_staff_claim_payments r WHERE r.reverses_payment_id = x.id)) THEN
   RAISE EXCEPTION 'A staff claim has one live payment' USING ERRCODE = '23514';
  END IF;
 ELSE
  SELECT claim_id, kind INTO p FROM hr_staff_claim_payments WHERE id = NEW.reverses_payment_id;
  IF p.claim_id IS DISTINCT FROM NEW.claim_id OR p.kind <> 'payment' THEN
   RAISE EXCEPTION 'A reversal points to a payment of the same claim' USING ERRCODE = '23514';
  END IF;
  IF c.status <> 'paid' THEN RAISE EXCEPTION 'Only a paid claim has its payment reversed' USING ERRCODE = '23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_staff_claim_payments_guard BEFORE INSERT ON hr_staff_claim_payments FOR EACH ROW EXECUTE FUNCTION guard_hr_staff_claim_payment();

-- Approve and reject actions are never by the claimant, and a paid action never by the claimant or approver.
CREATE OR REPLACE FUNCTION guard_hr_staff_claim_action() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c RECORD;
BEGIN
 SELECT claimant_user_id, decided_by_user_id INTO c FROM hr_staff_claims WHERE id = NEW.claim_id;
 IF NEW.action IN ('approved', 'rejected') AND NEW.actor_user_id = c.claimant_user_id THEN
  RAISE EXCEPTION 'Nobody decides their own staff claim' USING ERRCODE = '23514';
 END IF;
 IF NEW.action IN ('paid', 'payment_reversed') AND (NEW.actor_user_id = c.claimant_user_id OR NEW.actor_user_id = c.decided_by_user_id) THEN
  RAISE EXCEPTION 'The payer is never the claimant or the approver' USING ERRCODE = '23514';
 END IF;
 IF NEW.action IN ('sent', 'withdrawn') AND NEW.actor_user_id <> c.claimant_user_id THEN
  RAISE EXCEPTION 'Only the claimant sends or withdraws a staff claim' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER hr_staff_claim_actions_guard BEFORE INSERT ON hr_staff_claim_actions FOR EACH ROW EXECUTE FUNCTION guard_hr_staff_claim_action();
SQL);
    }

    public function down(): void
    {
        // HR record evidence is retained, following the evidence convention.
    }
};
