<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR-7b (HR-PLAN 16.5): a staff claim refunded through a locked payroll run is recorded with HR-6's own payment row,
 * method "payroll", so it is never paid twice. This only adds that method to the HR-6 payment shape; every other part of
 * the shape, and every HR-6 trigger, is unchanged (B.E.R.N.I.S.S for the owner, 2026-11-06).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hr_staff_claim_payments DROP CONSTRAINT hr_staff_claim_payments_shape');
        DB::statement(<<<'SQL'
ALTER TABLE hr_staff_claim_payments ADD CONSTRAINT hr_staff_claim_payments_shape CHECK (
 (kind = 'payment' AND reverses_payment_id IS NULL AND method IN ('bank_transfer', 'cash', 'cheque', 'payroll') AND paid_on IS NOT NULL AND reason_code IS NULL
   AND (reference_encrypted IS NULL) = (reference_last4 IS NULL))
 OR (kind = 'reversal' AND reverses_payment_id IS NOT NULL AND method IS NULL AND paid_on IS NULL AND reference_encrypted IS NULL AND reference_last4 IS NULL
   AND reason_code IN ('wrong_claim', 'wrong_amount', 'payment_failed', 'other'))
)
SQL);
    }

    public function down(): void
    {
        // Payment evidence is retained, following the evidence convention.
    }
};
