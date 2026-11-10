<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-16 (owner decisions 2026-11-10): a receipt recorded in error on a completed visit is corrected in one step by
 * a Finance Officer who did not record it. The wrong receipt is reversed (the existing reversal evidence) and, when
 * money was really received, a replacement receipt for what was received is recorded in the same transaction. This
 * table links the two. It is append-only evidence; the receipt, allocation and reversal rules are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_corrections', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('original_payment_id')->unique();
            $t->unsignedBigInteger('payment_reversal_id')->unique();
            $t->unsignedBigInteger('replacement_payment_id')->nullable()->unique();
            $t->bigInteger('received_sen');
            $t->unsignedBigInteger('corrected_by_user_id');
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->timestampTz('created_at');
            $t->unique(['organisation_id', 'idempotency_key']);
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('original_payment_id')->references('id')->on('payments')->restrictOnDelete();
            $t->foreign('payment_reversal_id')->references('id')->on('payment_reversals')->restrictOnDelete();
            $t->foreign('replacement_payment_id')->references('id')->on('payments')->restrictOnDelete();
            $t->foreign('corrected_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index('invoice_id');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_payment_correction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o payments%ROWTYPE; r payment_reversals%ROWTYPE; n payments%ROWTYPE;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Receipt corrections are append-only' USING ERRCODE = 'P0001'; END IF;
 SELECT * INTO o FROM payments WHERE id = NEW.original_payment_id;
 SELECT * INTO r FROM payment_reversals WHERE id = NEW.payment_reversal_id;
 -- The wrong receipt: reversed by this correction's author, who did not record it, on a completed visit's invoice.
 IF o.id IS NULL OR r.id IS NULL OR r.payment_id <> o.id OR r.invoice_id <> NEW.invoice_id OR o.status <> 'reversed'
   OR o.organisation_id <> NEW.organisation_id OR o.branch_id <> NEW.branch_id
   OR r.approved_by_user_id <> NEW.corrected_by_user_id OR o.recorded_by_user_id = NEW.corrected_by_user_id
   OR NOT EXISTS (SELECT 1 FROM invoices i JOIN visits v ON v.id = i.visit_id WHERE i.id = NEW.invoice_id
     AND i.organisation_id = NEW.organisation_id AND i.branch_id = NEW.branch_id AND i.status = 'finalized' AND v.status = 'completed') THEN
  RAISE EXCEPTION 'A receipt correction needs the reversal of a receipt someone else recorded on a completed visit' USING ERRCODE = '23514';
 END IF;
 IF NEW.received_sen < 0 OR NEW.received_sen > o.amount_sen THEN
  RAISE EXCEPTION 'A correction cannot record more than the reversed receipt' USING ERRCODE = '23514';
 END IF;
 IF NEW.replacement_payment_id IS NULL THEN
  IF NEW.received_sen <> 0 THEN RAISE EXCEPTION 'A correction with money received needs its replacement receipt' USING ERRCODE = '23514'; END IF;
  RETURN NEW;
 END IF;
 SELECT * INTO n FROM payments WHERE id = NEW.replacement_payment_id;
 IF n.id IS NULL OR n.status <> 'posted' OR n.amount_sen <> NEW.received_sen OR n.recorded_by_user_id <> NEW.corrected_by_user_id
   OR n.organisation_id <> NEW.organisation_id OR n.branch_id <> NEW.branch_id OR n.patient_id <> o.patient_id
   OR NOT EXISTS (SELECT 1 FROM payment_allocations a WHERE a.payment_id = n.id AND a.invoice_id = NEW.invoice_id) THEN
  RAISE EXCEPTION 'The replacement receipt must be the corrector''s posted receipt for what was received, on the same invoice' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_corrections_append BEFORE INSERT OR UPDATE OR DELETE ON payment_corrections FOR EACH ROW EXECUTE FUNCTION guard_payment_correction();
CREATE TRIGGER payment_corrections_truncate BEFORE TRUNCATE ON payment_corrections FOR EACH STATEMENT EXECUTE FUNCTION guard_payment_correction();
SQL);
    }

    public function down(): void
    {
        // Retain correction evidence, following the evidence convention.
    }
};
