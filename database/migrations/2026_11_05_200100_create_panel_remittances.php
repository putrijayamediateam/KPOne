<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PNL-3: one Panel payment (remittance) split across several accepted claims of that Panel.
 * Each split is an ordinary panel_claim_payments row linked to its remittance, so claim pages,
 * the Panel summary and Insights keep reading the same evidence. Both tables stay append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_remittances', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_id');
            $t->bigInteger('amount_sen');
            $t->string('currency', 3)->default('MYR');
            $t->string('reference', 100)->nullable();
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestamp('received_at');
            $t->unsignedBigInteger('recorded_by_user_id');
            $t->timestamps();
            $t->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            $t->foreign(['branch_id', 'organisation_id'], 'panel_remittances_branch_owner_fk')
                ->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['panel_id', 'organisation_id'], 'panel_remittances_panel_owner_fk')
                ->references(['id', 'organisation_id'])->on('panels')->restrictOnDelete();
            $t->foreign(['recorded_by_user_id', 'organisation_id'], 'panel_remittances_recorder_fk')
                ->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key']);
            $t->unique(['id', 'organisation_id', 'branch_id']);
            $t->index(['organisation_id', 'branch_id', 'received_at'], 'panel_remittances_received_idx');
        });

        Schema::table('panel_claim_payments', function (Blueprint $t): void {
            $t->unsignedBigInteger('panel_remittance_id')->nullable()->after('panel_claim_id');
            $t->foreign(['panel_remittance_id', 'organisation_id', 'branch_id'], 'panel_claim_payment_remittance_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_remittances')->restrictOnDelete();
            $t->index('panel_remittance_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE panel_remittances ADD CONSTRAINT panel_remittances_shape CHECK (amount_sen BETWEEN 1 AND 999999999999 AND currency='MYR')");
            DB::unprepared(<<<'SQL'
CREATE TRIGGER panel_remittances_immutable BEFORE UPDATE OR DELETE ON panel_remittances FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_child_immutable();

CREATE OR REPLACE FUNCTION kpone_panel_remittance_split_panel() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.panel_remittance_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM panel_remittances r JOIN panel_claims c ON c.id = NEW.panel_claim_id
        WHERE r.id = NEW.panel_remittance_id AND r.panel_id = c.panel_id
          AND r.organisation_id = NEW.organisation_id AND r.branch_id = NEW.branch_id
    ) THEN
        RAISE EXCEPTION 'A Panel remittance can only pay claims of its own Panel' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER panel_claim_payments_remittance_panel BEFORE INSERT ON panel_claim_payments FOR EACH ROW EXECUTE FUNCTION kpone_panel_remittance_split_panel();

-- Checked at commit, both when a remittance is added and whenever a split is added to one, so the splits
-- always add up to the payment.
CREATE OR REPLACE FUNCTION kpone_panel_remittance_balanced() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    remittance_id bigint;
    remittance_amount bigint;
    split_total bigint;
BEGIN
    -- Separate statements: NEW has no panel_remittance_id field on panel_remittances.
    IF TG_TABLE_NAME = 'panel_remittances' THEN
        remittance_id := NEW.id;
    ELSE
        remittance_id := NEW.panel_remittance_id;
    END IF;
    SELECT amount_sen INTO remittance_amount FROM panel_remittances WHERE id = remittance_id;
    SELECT COALESCE(SUM(amount_sen), 0) INTO split_total FROM panel_claim_payments WHERE panel_remittance_id = remittance_id;
    IF split_total <> remittance_amount THEN
        RAISE EXCEPTION 'A Panel remittance must be split in full across its claims' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER panel_remittances_balanced AFTER INSERT ON panel_remittances
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_panel_remittance_balanced();
CREATE CONSTRAINT TRIGGER panel_claim_payments_remittance_balanced AFTER INSERT ON panel_claim_payments
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (NEW.panel_remittance_id IS NOT NULL)
    EXECUTE FUNCTION kpone_panel_remittance_balanced();
SQL);
        }
    }

    public function down(): void {}
};
