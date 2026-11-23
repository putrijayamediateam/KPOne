<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-32 (owner decisions 2026-11-20 to 2026-11-22): an approved daily close locks its branch-day, and money actions
 * pause on a counted day while its count waits for a decision.
 *
 * Locked day (judged on the original day of what is changed): no void, receipt reversal, receipt correction or invoice
 * adjustment. A reversal of a locked day's receipt is let through only inside a refund (owner Q5 = A); that check runs
 * at commit, because the refund row can only be written after its reversal. Rejecting such a refund is refused, as it
 * would take the receipt out of the locked day's figure. Refunds, later-day payments, Panel and pay-later approvals,
 * Panel claim adjustments and Panel remittances are not locked.
 *
 * Paused day (latest count of the day has no decision): no new receipt, finalise, void, reversal, correction, invoice
 * adjustment, Panel or pay-later proposal or approval that belongs to that day (by its own timestamps). The domain's
 * BranchDayGate also checks today's date and gives the plain message; these triggers are the floor for direct writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_closing_locks', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->date('business_date');
            $t->unsignedBigInteger('cash_closing_decision_id')->unique();
            $t->timestampTz('created_at');
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->unique(['organisation_id', 'branch_id', 'business_date'], 'cash_closing_locks_day_unique');
        });

        DB::unprepared(<<<'SQL'
-- Stored times are UTC "timestamp without time zone"; read them as UTC so the session TimeZone never matters.
CREATE OR REPLACE FUNCTION kpone_branch_local_date(p_branch bigint, p_ts timestamp) RETURNS date LANGUAGE sql STABLE AS $$
  SELECT ((p_ts AT TIME ZONE 'UTC') AT TIME ZONE b.timezone)::date FROM branches b WHERE b.id = p_branch
$$;
CREATE OR REPLACE FUNCTION kpone_branch_day_locked(p_branch bigint, p_date date) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT p_date IS NOT NULL AND EXISTS (SELECT 1 FROM cash_closing_locks l WHERE l.branch_id = p_branch AND l.business_date = p_date)
$$;
CREATE OR REPLACE FUNCTION kpone_branch_day_paused(p_branch bigint, p_date date) RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT p_date IS NOT NULL AND EXISTS (
    SELECT 1 FROM cash_closings c
    WHERE c.branch_id = p_branch AND c.business_date = p_date
      AND c.revision = (SELECT max(n.revision) FROM cash_closings n WHERE n.organisation_id = c.organisation_id AND n.branch_id = c.branch_id AND n.business_date = c.business_date)
      AND NOT EXISTS (SELECT 1 FROM cash_closing_decisions d WHERE d.cash_closing_id = c.id))
$$;
CREATE OR REPLACE FUNCTION kpone_branch_day_refuse(p_branch bigint, p_date date, p_lockable boolean) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
 IF p_lockable AND kpone_branch_day_locked(p_branch, p_date) THEN
   RAISE EXCEPTION 'This day''s cash is closed and locked' USING ERRCODE = 'P0001';
 END IF;
 IF kpone_branch_day_paused(p_branch, p_date) THEN
   RAISE EXCEPTION 'This day''s cash count is waiting for approval; money actions are paused' USING ERRCODE = 'P0001';
 END IF;
END $$;

CREATE OR REPLACE FUNCTION guard_cash_closing_lock() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'Cash closing locks are append-only' USING ERRCODE = 'P0001'; END IF;
 IF NOT EXISTS (SELECT 1 FROM cash_closing_decisions d JOIN cash_closings c ON c.id = d.cash_closing_id
   WHERE d.id = NEW.cash_closing_decision_id AND d.decision = 'approved' AND c.organisation_id = NEW.organisation_id
     AND c.branch_id = NEW.branch_id AND c.business_date = NEW.business_date) THEN
   RAISE EXCEPTION 'A cash closing lock must come from that day''s approval' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER cash_closing_locks_append BEFORE INSERT OR UPDATE OR DELETE ON cash_closing_locks FOR EACH ROW EXECUTE FUNCTION guard_cash_closing_lock();
CREATE TRIGGER cash_closing_locks_truncate BEFORE TRUNCATE ON cash_closing_locks FOR EACH STATEMENT EXECUTE FUNCTION guard_cash_closing_lock();

CREATE OR REPLACE FUNCTION guard_branch_day_payment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, NEW.received_at), false);
 RETURN NEW;
END $$;
CREATE TRIGGER payments_branch_day BEFORE INSERT ON payments FOR EACH ROW EXECUTE FUNCTION guard_branch_day_payment();

CREATE OR REPLACE FUNCTION guard_branch_day_invoice() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.status = 'finalized' AND OLD.status IS DISTINCT FROM 'finalized' THEN
   PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, NEW.finalized_at), false);
 ELSIF NEW.status = 'voided' AND OLD.status = 'finalized' THEN
   PERFORM kpone_branch_day_refuse(OLD.branch_id, kpone_branch_local_date(OLD.branch_id, OLD.finalized_at), true);
   PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, NEW.voided_at), false);
 END IF;
 RETURN NEW;
END $$;
-- Named to fire before invoice_immutable (BEFORE triggers run in name order), so a direct void gets this message.
CREATE TRIGGER invoice_branch_day BEFORE UPDATE OF status ON invoices FOR EACH ROW EXECUTE FUNCTION guard_branch_day_invoice();

CREATE OR REPLACE FUNCTION guard_branch_day_reversal() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE received timestamp;
BEGIN
 SELECT p.received_at INTO received FROM payments p WHERE p.id = NEW.payment_id;
 -- The lock part runs at commit (guard_branch_day_reversal_lock), so here only the pause.
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, received), false);
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, NEW.reversed_at), false);
 RETURN NEW;
END $$;
CREATE TRIGGER payment_reversals_branch_day BEFORE INSERT ON payment_reversals FOR EACH ROW EXECUTE FUNCTION guard_branch_day_reversal();

CREATE OR REPLACE FUNCTION guard_branch_day_reversal_lock() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE received timestamp;
BEGIN
 SELECT p.received_at INTO received FROM payments p WHERE p.id = NEW.payment_id;
 IF kpone_branch_day_locked(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, received))
   AND NOT EXISTS (SELECT 1 FROM payment_refunds r WHERE r.payment_id = NEW.payment_id
     AND NOT EXISTS (SELECT 1 FROM payment_refund_decisions d WHERE d.payment_refund_id = r.id AND d.decision = 'rejected')) THEN
   RAISE EXCEPTION 'This day''s cash is closed and locked; a receipt of that day can only be reversed together with its refund' USING ERRCODE = 'P0001';
 END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER payment_reversals_branch_day_lock AFTER INSERT ON payment_reversals DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION guard_branch_day_reversal_lock();

CREATE OR REPLACE FUNCTION guard_branch_day_correction() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE received timestamp;
BEGIN
 SELECT p.received_at INTO received FROM payments p WHERE p.id = NEW.original_payment_id;
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, received), true);
 RETURN NEW;
END $$;
CREATE TRIGGER payment_corrections_branch_day BEFORE INSERT ON payment_corrections FOR EACH ROW EXECUTE FUNCTION guard_branch_day_correction();

CREATE OR REPLACE FUNCTION guard_branch_day_adjustment() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE finalized timestamp;
BEGIN
 SELECT i.finalized_at INTO finalized FROM invoices i WHERE i.id = NEW.invoice_id;
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, finalized), true);
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, NEW.created_at), false);
 RETURN NEW;
END $$;
CREATE TRIGGER invoice_adjustments_branch_day BEFORE INSERT ON invoice_adjustments FOR EACH ROW EXECUTE FUNCTION guard_branch_day_adjustment();
CREATE TRIGGER invoice_adjustment_reversals_branch_day BEFORE INSERT ON invoice_adjustment_reversals FOR EACH ROW EXECUTE FUNCTION guard_branch_day_adjustment();

CREATE OR REPLACE FUNCTION guard_branch_day_responsibility() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE finalized timestamp;
BEGIN
 IF TG_OP = 'UPDATE' AND NOT (NEW.status = 'approved' AND OLD.status IS DISTINCT FROM 'approved') THEN RETURN NEW; END IF;
 SELECT i.finalized_at INTO finalized FROM invoices i WHERE i.id = NEW.invoice_id;
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, finalized), false);
 PERFORM kpone_branch_day_refuse(NEW.branch_id, kpone_branch_local_date(NEW.branch_id, CASE WHEN TG_OP = 'INSERT' THEN NEW.created_at ELSE NEW.approved_at END), false);
 RETURN NEW;
END $$;
CREATE TRIGGER coverage_allocations_branch_day BEFORE INSERT OR UPDATE OF status ON coverage_allocations FOR EACH ROW EXECUTE FUNCTION guard_branch_day_responsibility();
CREATE TRIGGER patient_receivables_branch_day BEFORE INSERT OR UPDATE OF status ON patient_receivables FOR EACH ROW EXECUTE FUNCTION guard_branch_day_responsibility();

CREATE OR REPLACE FUNCTION guard_branch_day_refund_rejection() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE p payments%ROWTYPE;
BEGIN
 IF NEW.decision <> 'rejected' THEN RETURN NEW; END IF;
 SELECT pay.* INTO p FROM payments pay JOIN payment_refunds r ON r.payment_id = pay.id WHERE r.id = NEW.payment_refund_id;
 IF p.status = 'reversed' AND kpone_branch_day_locked(p.branch_id, kpone_branch_local_date(p.branch_id, p.received_at)) THEN
   RAISE EXCEPTION 'This day''s cash is closed and locked; the refund of its reversed receipt cannot be rejected' USING ERRCODE = 'P0001';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER payment_refund_decisions_branch_day BEFORE INSERT ON payment_refund_decisions FOR EACH ROW EXECUTE FUNCTION guard_branch_day_refund_rejection();
SQL);

        // Days approved before this change are locked too: the lock is derived from the approval.
        DB::statement(<<<'SQL'
INSERT INTO cash_closing_locks (organisation_id, branch_id, business_date, cash_closing_decision_id, created_at)
SELECT c.organisation_id, c.branch_id, c.business_date, d.id, now()
FROM cash_closing_decisions d JOIN cash_closings c ON c.id = d.cash_closing_id
WHERE d.decision = 'approved'
ON CONFLICT DO NOTHING
SQL);
    }

    public function down(): void
    {
        // Retain lock evidence and its guards, following the append-only evidence convention.
    }
};
