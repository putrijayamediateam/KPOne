<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_claims', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('coverage_allocation_id')->unique();
            $t->unsignedBigInteger('invoice_id');
            $t->unsignedBigInteger('patient_id');
            $t->unsignedBigInteger('panel_id');
            $t->string('invoice_number', 30);
            $t->string('visit_number', 30);
            $t->string('patient_name_snapshot', 255);
            $t->string('panel_name_snapshot', 200);
            $t->string('member_reference', 100)->nullable();
            $t->string('branch_name_snapshot', 200);
            $t->date('visit_date');
            $t->bigInteger('invoice_total_sen');
            $t->bigInteger('claim_amount_sen');
            $t->bigInteger('accepted_amount_sen')->nullable();
            $t->string('status', 24)->default('prepared');
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestamps();
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['coverage_allocation_id', 'organisation_id', 'branch_id'], 'panel_claim_allocation_owner_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('coverage_allocations')->restrictOnDelete();
            $t->foreign(['invoice_id', 'organisation_id', 'branch_id'], 'panel_claim_invoice_owner_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('invoices')->restrictOnDelete();
            $t->foreign(['patient_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('patients')->restrictOnDelete();
            $t->foreign(['panel_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('panels')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id', 'branch_id']);
            $t->index(['organisation_id', 'branch_id', 'status', 'created_at'], 'panel_claims_branch_status_created_idx');
        });

        Schema::create('panel_claim_lines', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_claim_id');
            $t->unsignedSmallInteger('line_number');
            $t->string('line_type', 20);
            $t->string('display_name', 500);
            $t->string('code_snapshot', 100);
            $t->string('unit_snapshot', 100);
            $t->decimal('quantity', 12, 3);
            $t->bigInteger('unit_price_sen');
            $t->bigInteger('line_total_sen');
            $t->timestamps();
            $t->foreign(['panel_claim_id', 'organisation_id', 'branch_id'], 'panel_claim_line_claim_owner_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            $t->unique(['panel_claim_id', 'line_number']);
            $t->unique(['id', 'organisation_id', 'branch_id']);
        });

        Schema::create('panel_claim_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_claim_id');
            $t->unsignedBigInteger('actor_user_id');
            $t->string('event_type', 32);
            $t->bigInteger('previous_amount_sen')->nullable();
            $t->bigInteger('amount_sen')->nullable();
            $t->string('external_reference', 100)->nullable();
            $t->string('note', 500)->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->foreign(['panel_claim_id', 'organisation_id', 'branch_id'], 'panel_claim_event_claim_owner_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            $t->foreign(['actor_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['id', 'organisation_id', 'branch_id']);
            $t->index(['panel_claim_id', 'occurred_at']);
        });

        Schema::create('panel_claim_payments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_claim_id');
            $t->bigInteger('amount_sen');
            $t->string('currency', 3)->default('MYR');
            $t->string('reference', 100)->nullable();
            $t->uuid('idempotency_key');
            $t->string('payload_hash', 64);
            $t->timestamp('received_at');
            $t->unsignedBigInteger('recorded_by_user_id');
            $t->timestamps();
            $t->foreign(['panel_claim_id', 'organisation_id', 'branch_id'], 'panel_claim_payment_claim_owner_fk')
                ->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            $t->foreign(['recorded_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            $t->unique(['organisation_id', 'idempotency_key']);
            $t->unique(['id', 'organisation_id', 'branch_id']);
            $t->index(['organisation_id', 'branch_id', 'received_at'], 'panel_claim_payments_received_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE panel_claims ADD CONSTRAINT panel_claims_shape CHECK (invoice_total_sen BETWEEN 0 AND 999999999999 AND claim_amount_sen BETWEEN 1 AND 999999999999 AND lock_version>0 AND ((status IN ('prepared','submitted','queried') AND accepted_amount_sen IS NULL) OR (status='rejected' AND accepted_amount_sen=0) OR (status='accepted' AND accepted_amount_sen=claim_amount_sen) OR (status='partially_accepted' AND accepted_amount_sen BETWEEN 1 AND claim_amount_sen-1) OR (status IN ('partially_paid','paid') AND accepted_amount_sen BETWEEN 1 AND claim_amount_sen)))");
            DB::statement("ALTER TABLE panel_claim_lines ADD CONSTRAINT panel_claim_lines_shape CHECK (line_number>0 AND line_type IN ('consultation','medicine','service') AND quantity>0 AND unit_price_sen BETWEEN 0 AND 999999999999 AND line_total_sen BETWEEN 0 AND 999999999999)");
            DB::statement("ALTER TABLE panel_claim_events ADD CONSTRAINT panel_claim_events_shape CHECK (event_type IN ('prepared','amount_adjusted','submitted','queried','decision_accepted','decision_partially_accepted','decision_rejected') AND (previous_amount_sen IS NULL OR previous_amount_sen BETWEEN 0 AND 999999999999) AND (amount_sen IS NULL OR amount_sen BETWEEN 0 AND 999999999999) AND ((event_type='prepared' AND amount_sen IS NOT NULL) OR (event_type='amount_adjusted' AND previous_amount_sen IS NOT NULL AND amount_sen IS NOT NULL AND note IS NOT NULL) OR (event_type='submitted' AND amount_sen IS NOT NULL) OR (event_type='queried' AND note IS NOT NULL) OR (event_type LIKE 'decision_%' AND amount_sen IS NOT NULL AND note IS NOT NULL)))");
            DB::statement("ALTER TABLE panel_claim_payments ADD CONSTRAINT panel_claim_payments_shape CHECK (amount_sen BETWEEN 1 AND 999999999999 AND currency='MYR')");
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kpone_panel_claim_child_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Panel claim evidence is immutable and retained' USING ERRCODE='23514';
END $$;
CREATE TRIGGER panel_claim_lines_immutable BEFORE UPDATE OR DELETE ON panel_claim_lines FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_child_immutable();
CREATE TRIGGER panel_claim_events_immutable BEFORE UPDATE OR DELETE ON panel_claim_events FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_child_immutable();
CREATE TRIGGER panel_claim_payments_immutable BEFORE UPDATE OR DELETE ON panel_claim_payments FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_child_immutable();

CREATE OR REPLACE FUNCTION kpone_panel_claim_identity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.public_id<>OLD.public_id OR NEW.organisation_id<>OLD.organisation_id OR NEW.branch_id<>OLD.branch_id
       OR NEW.coverage_allocation_id<>OLD.coverage_allocation_id OR NEW.invoice_id<>OLD.invoice_id
       OR NEW.patient_id<>OLD.patient_id OR NEW.panel_id<>OLD.panel_id OR NEW.invoice_number<>OLD.invoice_number
       OR NEW.visit_number<>OLD.visit_number OR NEW.patient_name_snapshot<>OLD.patient_name_snapshot
       OR NEW.panel_name_snapshot<>OLD.panel_name_snapshot OR NEW.member_reference IS DISTINCT FROM OLD.member_reference
       OR NEW.branch_name_snapshot<>OLD.branch_name_snapshot OR NEW.visit_date<>OLD.visit_date
       OR NEW.invoice_total_sen<>OLD.invoice_total_sen OR NEW.created_by_user_id<>OLD.created_by_user_id THEN
        RAISE EXCEPTION 'Panel claim source snapshot is immutable' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER panel_claim_identity_immutable BEFORE UPDATE ON panel_claims FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_identity_immutable();
SQL);
        }
    }

    public function down(): void {}
};
