<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * BIL-6 (owner decisions 2026-11-05): a refund records money actually handed back to a patient for a receipt
 * that was reversed before visit completion, for the receipt's full amount. One person records it (CA Supervisor,
 * Finance Officer) and a different person approves or rejects it (Finance Officer, Director); there is no amount
 * threshold. Both tables are append-only. Cash closing may now expect a negative day (more refunded than taken).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('payment_id');
            $t->unsignedBigInteger('payment_method_id');
            $t->string('method_snapshot', 100);
            $t->bigInteger('amount_sen');
            $t->string('reason_code', 32);
            $t->unsignedBigInteger('recorded_by_user_id');
            $t->timestampTz('paid_out_at');
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->foreign(['payment_id', 'organisation_id', 'branch_id'])->references(['id', 'organisation_id', 'branch_id'])->on('payments')->restrictOnDelete();
            $t->foreign(['payment_method_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('payment_methods')->restrictOnDelete();
            $t->foreign(['recorded_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'payment_refunds_idempotency_unique');
            $t->index(['organisation_id', 'branch_id', 'paid_out_at'], 'payment_refunds_branch_day_index');
            $t->index('payment_id');
        });
        Schema::create('payment_refund_decisions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('payment_refund_id')->unique();
            $t->string('decision', 16);
            $t->string('reason_code', 32)->nullable();
            $t->unsignedBigInteger('decided_by_user_id');
            $t->timestampTz('decided_at');
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->foreign('payment_refund_id')->references('id')->on('payment_refunds')->restrictOnDelete();
            $t->foreign(['decided_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key'], 'payment_refund_decisions_idempotency_unique');
        });
        DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_shape CHECK (amount_sen BETWEEN 1 AND 999999999999 AND reason_code IN ('overpaid', 'service_not_given', 'invoice_corrected', 'other'))");
        DB::statement("ALTER TABLE payment_refund_decisions ADD CONSTRAINT payment_refund_decisions_shape CHECK ((decision = 'approved' AND reason_code IS NULL) OR (decision = 'rejected' AND reason_code IN ('amount_wrong', 'not_paid_out', 'duplicate', 'other')))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_payment_refund() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Refund evidence is append-only' USING ERRCODE = 'P0001'; END IF;
 -- Serialise refunds of one receipt here too, not only in the service: lock the receipt row first.
 PERFORM 1 FROM payments WHERE id = NEW.payment_id FOR UPDATE;
 IF NOT EXISTS (SELECT 1 FROM payments p JOIN payment_reversals r ON r.payment_id = p.id
   WHERE p.id = NEW.payment_id AND p.organisation_id = NEW.organisation_id AND p.branch_id = NEW.branch_id
     AND p.status = 'reversed' AND r.amount_sen = NEW.amount_sen) THEN
   RAISE EXCEPTION 'A refund must be the full amount of a reversed receipt of the same branch' USING ERRCODE = '23514';
 END IF;
 IF EXISTS (SELECT 1 FROM payment_refunds f WHERE f.payment_id = NEW.payment_id
   AND NOT EXISTS (SELECT 1 FROM payment_refund_decisions d WHERE d.payment_refund_id = f.id AND d.decision = 'rejected')) THEN
   RAISE EXCEPTION 'This receipt already has a refund that is not rejected' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_refunds_append BEFORE INSERT OR UPDATE OR DELETE ON payment_refunds FOR EACH ROW EXECUTE FUNCTION guard_payment_refund();
CREATE TRIGGER payment_refunds_truncate BEFORE TRUNCATE ON payment_refunds FOR EACH STATEMENT EXECUTE FUNCTION guard_payment_refund();
CREATE OR REPLACE FUNCTION guard_payment_refund_decision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE f payment_refunds%ROWTYPE;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Refund decisions are append-only' USING ERRCODE = 'P0001'; END IF;
 SELECT * INTO f FROM payment_refunds WHERE id = NEW.payment_refund_id;
 PERFORM 1 FROM payments WHERE id = f.payment_id FOR UPDATE;
 IF f.id IS NULL OR f.organisation_id <> NEW.organisation_id THEN RAISE EXCEPTION 'Invalid refund decision scope' USING ERRCODE = '23514'; END IF;
 IF NEW.decided_by_user_id = f.recorded_by_user_id THEN RAISE EXCEPTION 'The person who recorded a refund cannot decide it' USING ERRCODE = '23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_refund_decisions_append BEFORE INSERT OR UPDATE OR DELETE ON payment_refund_decisions FOR EACH ROW EXECUTE FUNCTION guard_payment_refund_decision();
CREATE TRIGGER payment_refund_decisions_truncate BEFORE TRUNCATE ON payment_refund_decisions FOR EACH STATEMENT EXECUTE FUNCTION guard_payment_refund_decision();
SQL);
        // Refunds can make a day's expected cash takings negative, and the counted takings with them.
        DB::statement('ALTER TABLE cash_closings DROP CONSTRAINT cash_closings_shape');
        DB::statement('ALTER TABLE cash_closings ADD CONSTRAINT cash_closings_shape CHECK (revision > 0 AND variance_sen = counted_cash_sen - expected_cash_sen AND (variance_sen = 0 OR (variance_reason IS NOT NULL AND length(trim(variance_reason)) > 0)))');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['payments.refunds.record.branch' => ['ca_supervisor', 'finance_officer'], 'payments.refunds.approve.branch' => ['finance_officer', 'director']] as $name => $holders) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            foreach ($holders as $holder) {
                $role = Role::where('name', $holder)->where('guard_name', 'web')->first();
                if ($role && ! $role->permissions()->whereKey($permission->id)->exists()) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Retain refund evidence and the permission grants, following the evidence and additive permission conventions.
    }
};
