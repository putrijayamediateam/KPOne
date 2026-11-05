<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PNL-6 (owner decision 2026-11-05): a wrongly recorded Panel payment is reversed, never edited. One row per
 * reversed claim payment; the payment itself stays as it was. A reversal always covers the whole payment, a
 * split Panel payment (PNL-3) is reversed with all of its splits, and the person who reverses is never the person
 * who recorded the payment. Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_claim_payment_reversals', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_claim_id');
            $t->unsignedBigInteger('panel_claim_payment_id')->unique();
            $t->unsignedBigInteger('panel_remittance_id')->nullable();
            $t->bigInteger('amount_sen');
            $t->string('reason_code', 40);
            $t->string('note', 200);
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestamp('reversed_at');
            $t->unsignedBigInteger('reversed_by_user_id');
            $t->timestamps();
            $t->foreign(['panel_claim_payment_id', 'organisation_id', 'branch_id'], 'panel_payment_reversal_payment_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_claim_payments')->restrictOnDelete();
            $t->foreign(['panel_claim_id', 'organisation_id', 'branch_id'], 'panel_payment_reversal_claim_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            $t->foreign(['panel_remittance_id', 'organisation_id', 'branch_id'], 'panel_payment_reversal_remittance_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_remittances')->restrictOnDelete();
            $t->foreign(['reversed_by_user_id', 'organisation_id'], 'panel_payment_reversal_user_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->index(['organisation_id', 'idempotency_key'], 'panel_payment_reversals_key_idx');
            $t->index('panel_claim_id');
            $t->index('panel_remittance_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE panel_claim_payment_reversals ADD CONSTRAINT panel_claim_payment_reversals_shape CHECK (amount_sen BETWEEN 1 AND 999999999999 AND reason_code IN ('wrong_amount','wrong_claim','wrong_date_or_reference','recorded_twice','not_received','other') AND char_length(btrim(note)) BETWEEN 1 AND 200)");
        DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_claim_payment_reversals_immutable BEFORE UPDATE OR DELETE ON panel_claim_payment_reversals
    FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_child_immutable();
CREATE TRIGGER panel_claim_payment_reversals_no_truncate BEFORE TRUNCATE ON panel_claim_payment_reversals
    FOR EACH STATEMENT EXECUTE FUNCTION kpone_panel_claim_child_immutable();

-- A reversal copies the payment it reverses: same claim, same Panel payment, the whole amount, and a
-- different person from the one who recorded it.
CREATE OR REPLACE FUNCTION kpone_panel_payment_reversal_matches() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM panel_claim_payments p
        WHERE p.id = NEW.panel_claim_payment_id AND p.organisation_id = NEW.organisation_id AND p.branch_id = NEW.branch_id
          AND p.panel_claim_id = NEW.panel_claim_id AND p.amount_sen = NEW.amount_sen
          AND p.panel_remittance_id IS NOT DISTINCT FROM NEW.panel_remittance_id
          AND p.recorded_by_user_id <> NEW.reversed_by_user_id
    ) THEN
        RAISE EXCEPTION 'A Panel payment reversal must match its payment and be made by a different person' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER panel_claim_payment_reversals_matches BEFORE INSERT ON panel_claim_payment_reversals
    FOR EACH ROW EXECUTE FUNCTION kpone_panel_payment_reversal_matches();

-- Checked at commit: a split Panel payment is reversed whole, and the claim's status matches what is still
-- received after the reversal.
CREATE OR REPLACE FUNCTION kpone_panel_payment_reversal_complete() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    claim panel_claims%ROWTYPE;
    received bigint;
BEGIN
    IF NEW.panel_remittance_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM panel_claim_payments p
        WHERE p.panel_remittance_id = NEW.panel_remittance_id
          AND NOT EXISTS (SELECT 1 FROM panel_claim_payment_reversals r WHERE r.panel_claim_payment_id = p.id)
    ) THEN
        RAISE EXCEPTION 'A split Panel payment must be reversed with all of its splits' USING ERRCODE='23514';
    END IF;
    SELECT * INTO claim FROM panel_claims WHERE id = NEW.panel_claim_id;
    SELECT COALESCE(SUM(p.amount_sen), 0) INTO received FROM panel_claim_payments p
    WHERE p.panel_claim_id = claim.id
      AND NOT EXISTS (SELECT 1 FROM panel_claim_payment_reversals r WHERE r.panel_claim_payment_id = p.id);
    IF NOT ((received = 0 AND claim.status IN ('accepted', 'partially_accepted'))
         OR (received > 0 AND received < claim.accepted_amount_sen AND claim.status = 'partially_paid')
         OR (received > 0 AND received = claim.accepted_amount_sen AND claim.status = 'paid')) THEN
        RAISE EXCEPTION 'A Panel claim status must match its payments after a reversal' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER panel_claim_payment_reversals_complete AFTER INSERT ON panel_claim_payment_reversals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_panel_payment_reversal_complete();
SQL);
    }

    public function down(): void {}
};
