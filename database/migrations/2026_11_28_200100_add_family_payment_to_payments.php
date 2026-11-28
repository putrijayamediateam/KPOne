<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-39 (owner, 28 Nov 2026: migrations approved, option A): the receipts of one family payment share a
 * family_payment_id, so they show together and a retry returns them; family_payload_hash is the whole family request's
 * fingerprint, so a changed retry is refused (Kumar R3, R4). Both are written once with the receipt; receipts stay
 * immutable (kpone_payment_immutable), so they can never be changed later. Additive and nullable: single payments leave
 * both empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->uuid('family_payment_id')->nullable();
            $t->char('family_payload_hash', 64)->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE INDEX payments_family_payment_idx ON payments (organisation_id, family_payment_id) WHERE family_payment_id IS NOT NULL;
ALTER TABLE payments ADD CONSTRAINT payments_family_payment_pair
  CHECK ((family_payment_id IS NULL) = (family_payload_hash IS NULL));
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_family_payment_pair; DROP INDEX IF EXISTS payments_family_payment_idx;');
        }
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropColumn(['family_payment_id', 'family_payload_hash']);
        });
    }
};
