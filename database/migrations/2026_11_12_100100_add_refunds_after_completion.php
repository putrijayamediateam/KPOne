<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-10 (owner decisions 2026-11-06): a refund after the visit is completed gives back part or all of what the
 * patient paid on one posted receipt, never more than that receipt less its earlier refunds that are not rejected.
 * It always comes with an invoice credit of the same amount, so nothing becomes due again. Both are recorded by one
 * person and approved or rejected together by another (the BIL-6 decision row). Additive: existing refunds keep
 * kind 'reversal' and the BIL-6 rule. The payable and reconciliation functions are unchanged; the ledger subtracts
 * approved credits from the invoice total and their refunds from what was paid, so the amount due never moves.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE payment_refunds ADD COLUMN kind varchar(20) NOT NULL DEFAULT 'reversal'");
        DB::statement('ALTER TABLE payment_refunds DROP CONSTRAINT payment_refunds_shape');
        DB::statement("ALTER TABLE payment_refunds ADD CONSTRAINT payment_refunds_shape CHECK (amount_sen BETWEEN 1 AND 999999999999
            AND kind IN ('reversal', 'after_completion')
            AND ((kind = 'reversal' AND reason_code IN ('overpaid', 'service_not_given', 'invoice_corrected', 'other'))
              OR (kind = 'after_completion' AND reason_code IN ('medicine_returned', 'service_not_given', 'invoice_corrected', 'goodwill', 'other'))))");

        Schema::create('invoice_credits', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('payment_refund_id')->unique();
            $t->bigInteger('amount_sen');
            $t->timestampTz('created_at');
            $t->foreign('invoice_id')->references('id')->on('invoices')->restrictOnDelete();
            $t->foreign('payment_refund_id')->references('id')->on('payment_refunds')->restrictOnDelete();
            $t->index('invoice_id');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_payment_refund() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p payments%ROWTYPE; refunded bigint;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Refund evidence is append-only' USING ERRCODE = 'P0001'; END IF;
 -- Serialise refunds of one receipt here too, not only in the service: lock the receipt row first.
 SELECT * INTO p FROM payments WHERE id = NEW.payment_id FOR UPDATE;
 IF NEW.kind = 'reversal' THEN
  IF NOT EXISTS (SELECT 1 FROM payments p2 JOIN payment_reversals r ON r.payment_id = p2.id
    WHERE p2.id = NEW.payment_id AND p2.organisation_id = NEW.organisation_id AND p2.branch_id = NEW.branch_id
      AND p2.status = 'reversed' AND r.amount_sen = NEW.amount_sen) THEN
    RAISE EXCEPTION 'A refund must be the full amount of a reversed receipt of the same branch' USING ERRCODE = '23514';
  END IF;
  IF EXISTS (SELECT 1 FROM payment_refunds f WHERE f.payment_id = NEW.payment_id
    AND NOT EXISTS (SELECT 1 FROM payment_refund_decisions d WHERE d.payment_refund_id = f.id AND d.decision = 'rejected')) THEN
    RAISE EXCEPTION 'This receipt already has a refund that is not rejected' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
 END IF;
 -- BIL-10: after completion, a posted receipt of the same branch whose invoice's visit is completed.
 IF p.id IS NULL OR p.organisation_id <> NEW.organisation_id OR p.branch_id <> NEW.branch_id OR p.status <> 'posted'
   OR NOT EXISTS (SELECT 1 FROM payment_allocations a JOIN invoices i ON i.id = a.invoice_id JOIN visits v ON v.id = i.visit_id
     WHERE a.payment_id = p.id AND i.status = 'finalized' AND v.status = 'completed') THEN
  RAISE EXCEPTION 'A refund after completion needs a posted receipt of a completed visit of the same branch' USING ERRCODE = '23514';
 END IF;
 SELECT coalesce(sum(f.amount_sen), 0) INTO refunded FROM payment_refunds f WHERE f.payment_id = NEW.payment_id
   AND NOT EXISTS (SELECT 1 FROM payment_refund_decisions d WHERE d.payment_refund_id = f.id AND d.decision = 'rejected');
 -- One receipt pays one invoice today (PaymentService); a receipt split across invoices is refused here rather than
 -- credited beyond what it paid on the credited invoice.
 IF (SELECT count(*) FROM payment_allocations a WHERE a.payment_id = p.id) <> 1
   OR (SELECT a.amount_sen FROM payment_allocations a WHERE a.payment_id = p.id) <> p.amount_sen THEN
  RAISE EXCEPTION 'A refund after completion needs a receipt that pays exactly one invoice' USING ERRCODE = '23514';
 END IF;
 IF refunded + NEW.amount_sen > p.amount_sen THEN
  RAISE EXCEPTION 'A refund cannot be more than the receipt less its earlier refunds' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;

-- Every refund after completion carries its invoice credit, checked when the transaction commits.
CREATE OR REPLACE FUNCTION guard_payment_refund_has_credit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.kind = 'after_completion' AND NOT EXISTS (SELECT 1 FROM invoice_credits c WHERE c.payment_refund_id = NEW.id) THEN
  RAISE EXCEPTION 'A refund after completion must have its invoice credit' USING ERRCODE = '23514';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER payment_refunds_need_credit AFTER INSERT ON payment_refunds DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW WHEN (NEW.kind = 'after_completion') EXECUTE FUNCTION guard_payment_refund_has_credit();

CREATE OR REPLACE FUNCTION guard_invoice_credit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE f payment_refunds%ROWTYPE;
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Invoice credits are append-only' USING ERRCODE = 'P0001'; END IF;
 SELECT * INTO f FROM payment_refunds WHERE id = NEW.payment_refund_id;
 IF f.id IS NULL OR f.kind <> 'after_completion' OR f.organisation_id <> NEW.organisation_id OR f.branch_id <> NEW.branch_id
   OR f.amount_sen <> NEW.amount_sen
   OR NOT EXISTS (SELECT 1 FROM payment_allocations a JOIN invoices i ON i.id = a.invoice_id
     WHERE a.payment_id = f.payment_id AND a.invoice_id = NEW.invoice_id AND i.organisation_id = NEW.organisation_id AND i.branch_id = NEW.branch_id
       AND i.status = 'finalized') THEN
  RAISE EXCEPTION 'An invoice credit must match its refund after completion, amount and invoice' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER invoice_credits_append BEFORE INSERT OR UPDATE OR DELETE ON invoice_credits FOR EACH ROW EXECUTE FUNCTION guard_invoice_credit();
CREATE TRIGGER invoice_credits_truncate BEFORE TRUNCATE ON invoice_credits FOR EACH STATEMENT EXECUTE FUNCTION guard_invoice_credit();
SQL);
    }

    public function down(): void
    {
        // Retain refund and credit evidence, following the evidence convention.
    }
};
