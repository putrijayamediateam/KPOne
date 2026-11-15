<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_adjustment_limits', function (Blueprint $t): void {
            $this->identity($t);
            $t->bigInteger('limit_sen')->nullable();
            $t->unsignedBigInteger('lock_version');
            $t->unique(['branch_id', 'lock_version']);
        });
        Schema::create('invoice_adjustments', function (Blueprint $t): void {
            $this->identity($t);
            $t->unsignedBigInteger('invoice_id')->index();
            $t->string('direction', 10);
            $t->string('input_type', 10);
            $t->bigInteger('input_value');
            $t->bigInteger('base_sen');
            $t->bigInteger('amount_sen');
            $t->string('reason', 500);
            $t->unsignedBigInteger('threshold_version');
            $t->bigInteger('threshold_sen')->nullable();
            $t->boolean('limit_overridden');
            $t->unsignedBigInteger('invoice_version');
            $t->unique(['id', 'organisation_id', 'branch_id', 'invoice_id'], 'adjustment_owner_unique');
            $t->foreign(['invoice_id', 'organisation_id', 'branch_id'], 'adjustment_invoice_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('invoices')->restrictOnDelete();
        });
        Schema::create('invoice_adjustment_reversals', function (Blueprint $t): void {
            $this->identity($t);
            $t->unsignedBigInteger('invoice_id')->index();
            $t->unsignedBigInteger('invoice_adjustment_id')->unique();
            $t->bigInteger('amount_sen');
            $t->string('reason', 500);
            $t->unsignedBigInteger('invoice_version');
            $t->foreign(['invoice_adjustment_id', 'organisation_id', 'branch_id', 'invoice_id'], 'adjustment_reversal_owner_fk')->references(['id', 'organisation_id', 'branch_id', 'invoice_id'])->on('invoice_adjustments')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE invoice_adjustment_limits ADD CONSTRAINT adjustment_limit_shape CHECK (lock_version>0 AND (limit_sen IS NULL OR limit_sen BETWEEN 0 AND 999999999999));
ALTER TABLE invoice_adjustments ADD CONSTRAINT adjustment_shape CHECK (
 direction IN ('add','deduct') AND input_type IN ('fixed','percentage') AND input_value>0 AND base_sen BETWEEN 1 AND 999999999999 AND amount_sen BETWEEN 1 AND base_sen
 AND invoice_version>0 AND threshold_version>=0 AND (threshold_sen IS NULL OR threshold_sen BETWEEN 0 AND 999999999999)
 AND length(btrim(reason))>0 AND ((input_type='fixed' AND input_value=amount_sen) OR (input_type='percentage' AND input_value<=10000 AND amount_sen=(base_sen*input_value+5000)/10000))
 AND limit_overridden=(amount_sen>coalesce(threshold_sen,0)));
ALTER TABLE invoice_adjustment_reversals ADD CONSTRAINT adjustment_reversal_shape CHECK (amount_sen>0 AND invoice_version>0 AND length(btrim(reason))>0);
CREATE OR REPLACE FUNCTION kpone_invoice_payable(invoice_key bigint) RETURNS bigint LANGUAGE sql STABLE AS $$
 SELECT i.total_sen+coalesce((SELECT sum(CASE a.direction WHEN 'add' THEN a.amount_sen ELSE -a.amount_sen END) FROM invoice_adjustments a WHERE a.invoice_id=i.id AND NOT EXISTS (SELECT 1 FROM invoice_adjustment_reversals r WHERE r.invoice_adjustment_id=a.id)),0) FROM invoices i WHERE i.id=invoice_key
$$;
CREATE OR REPLACE FUNCTION kpone_adjustment_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Adjustment evidence is immutable and retained' USING ERRCODE='23514'; END $$;
CREATE OR REPLACE FUNCTION kpone_adjustment_anchor() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE inv record; v record; a record; threshold record;
BEGIN
 SELECT * INTO v FROM visits WHERE id=(SELECT visit_id FROM invoices WHERE id=NEW.invoice_id) FOR UPDATE;
 PERFORM id FROM branches WHERE id=NEW.branch_id FOR UPDATE;
 SELECT * INTO inv FROM invoices WHERE id=NEW.invoice_id FOR UPDATE;
 IF inv.id IS NULL OR inv.organisation_id<>NEW.organisation_id OR inv.branch_id<>NEW.branch_id OR inv.current_visit_guard IS DISTINCT FROM v.id OR inv.status<>'finalized' OR inv.source_stale OR inv.correction_hold OR v.status<>'registered' OR NEW.invoice_version<>inv.lock_version
 OR EXISTS (SELECT 1 FROM payment_allocations WHERE invoice_id=inv.id) OR EXISTS (SELECT 1 FROM coverage_allocations WHERE invoice_id=inv.id) OR EXISTS (SELECT 1 FROM patient_receivables WHERE invoice_id=inv.id) THEN
 RAISE EXCEPTION 'Adjustment requires current unsettled finalized Invoice before any financial history' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='invoice_adjustments' THEN
  SELECT * INTO threshold FROM invoice_adjustment_limits WHERE branch_id=NEW.branch_id ORDER BY lock_version DESC LIMIT 1;
  IF NEW.base_sen<>inv.subtotal_sen OR NEW.threshold_version<>coalesce(threshold.lock_version,0) OR NEW.threshold_sen IS DISTINCT FROM threshold.limit_sen
  OR EXISTS (SELECT 1 FROM invoice_adjustments x WHERE x.invoice_id=inv.id AND NOT EXISTS (SELECT 1 FROM invoice_adjustment_reversals r WHERE r.invoice_adjustment_id=x.id))
  OR (inv.total_sen+(CASE NEW.direction WHEN 'add' THEN NEW.amount_sen ELSE -NEW.amount_sen END)) NOT BETWEEN 0 AND 999999999999 THEN
   RAISE EXCEPTION 'Invalid adjustment basis, threshold or active adjustment' USING ERRCODE='23514'; END IF;
 ELSE
  SELECT * INTO a FROM invoice_adjustments WHERE id=NEW.invoice_adjustment_id;
  IF a.amount_sen<>NEW.amount_sen OR a.actor_user_id=NEW.actor_user_id THEN RAISE EXCEPTION 'Reversal requires exact independent evidence' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION kpone_adjustment_reconcile() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM kpone_billing_check_invoice(NEW.invoice_id);
 IF (SELECT lock_version FROM invoices WHERE id=NEW.invoice_id)<=NEW.invoice_version THEN RAISE EXCEPTION 'Adjustment requires a new Invoice revision' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION kpone_adjustment_limit_anchor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM id FROM branches WHERE id=NEW.branch_id FOR UPDATE;
 IF NEW.lock_version<>coalesce((SELECT max(lock_version) FROM invoice_adjustment_limits WHERE branch_id=NEW.branch_id),0)+1 THEN RAISE EXCEPTION 'Stale adjustment threshold' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
SQL);
        foreach (['invoice_adjustment_limits', 'invoice_adjustments', 'invoice_adjustment_reversals'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_retained BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION kpone_adjustment_retained()");
            $function = $table === 'invoice_adjustment_limits' ? 'kpone_adjustment_limit_anchor' : 'kpone_adjustment_anchor';
            DB::unprepared("CREATE TRIGGER {$table}_anchor BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}()");
            if ($table !== 'invoice_adjustment_limits') {
                DB::unprepared("CREATE CONSTRAINT TRIGGER {$table}_reconcile AFTER INSERT ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_adjustment_reconcile()");
            }
        }
        $this->patch('kpone_billing_check_invoice', 'paid+panel_amount+deferred_amount>inv.total_sen', 'paid+panel_amount+deferred_amount>kpone_invoice_payable(inv.id)', 'RETURNS void');
        $this->patch('kpone_completed_visit_evidence', 'paid+panel_amount+deferred_amount<>inv.total_sen', "paid+panel_amount+deferred_amount<>kpone_invoice_payable(inv.id) OR (EXISTS (SELECT 1 FROM invoice_adjustments WHERE invoice_id=inv.id) AND (evidence->'financial_revision'->>'total' IS DISTINCT FROM kpone_invoice_payable(inv.id)::text OR evidence->'adjustment'->>'adjustmentSen' IS DISTINCT FROM (kpone_invoice_payable(inv.id)-inv.total_sen)::text))", 'RETURNS trigger');
    }

    private function identity(Blueprint $t): void
    {
        $t->id();
        $t->uuid('public_id')->unique();
        $t->unsignedBigInteger('organisation_id');
        $t->unsignedBigInteger('branch_id');
        $t->unsignedBigInteger('actor_user_id');
        $t->uuid('idempotency_key');
        $t->string('payload_hash', 64);
        $t->timestamps();
        $t->unique(['organisation_id', 'idempotency_key']);
        $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
        $t->foreign(['actor_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
    }

    private function patch(string $name, string $old, string $new, string $returns): void
    {
        $definition = DB::selectOne('SELECT prosrc, pg_get_function_arguments(oid) AS arguments FROM pg_proc WHERE proname = ?', [$name]);
        $source = (string) $definition->prosrc;
        if (substr_count($source, $old) !== 1) {
            throw new RuntimeException("Unexpected {$name} reconciliation shape.");
        }
        // Preserve every existing consultation, OTC and source-proof rule verbatim.
        // @phpstan-ignore argument.type
        DB::unprepared("CREATE OR REPLACE FUNCTION {$name}({$definition->arguments}) {$returns} LANGUAGE plpgsql AS \$kpone\$".str_replace($old, $new, $source).'$kpone$');
    }

    public function down(): void {}
};
