<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PCA-2 (Panel claim adjustment design note v3.2, owner approved 2026-11-15): a recorded, confirmed change to the Panel
 * share of a finalized invoice. The approved allocation row is never edited; the Panel share becomes "approved
 * allocation + confirmed Panel claim adjustments", and the invoice payable rises or falls by the same amount, so what the
 * patient owes never moves. Every existing PostgreSQL protection stays; the two money checks read the effective figures.
 * Supplementary claims (a second claim on the same Panel share for a plus adjustment on a decided claim) and an
 * append-only version history of each claim's pack totals are added beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_claim_adjustments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('invoice_id')->index();
            $t->unsignedBigInteger('coverage_allocation_id')->index();
            $t->unsignedBigInteger('panel_claim_id')->nullable()->index();
            $t->string('direction', 10);
            $t->bigInteger('amount_sen');
            $t->string('reason_code', 40);
            $t->string('note', 500)->nullable();
            $t->string('status', 12);
            $t->string('claim_status_at_request', 32)->nullable();
            $t->unsignedBigInteger('invoice_version');
            $t->unsignedBigInteger('requested_by_user_id');
            $t->timestampTz('requested_at');
            $t->uuid('request_idempotency_key');
            $t->string('request_payload_hash', 64);
            $t->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $t->timestampTz('confirmed_at')->nullable();
            $t->uuid('confirm_idempotency_key')->nullable();
            $t->unsignedBigInteger('withdrawn_by_user_id')->nullable();
            $t->timestampTz('withdrawn_at')->nullable();
            $t->unsignedBigInteger('lock_version');
            $t->timestamps();
            $t->unique(['organisation_id', 'request_idempotency_key'], 'panel_adjustment_request_key_unique');
            $t->unique(['organisation_id', 'confirm_idempotency_key'], 'panel_adjustment_confirm_key_unique');
            $t->unique(['id', 'organisation_id', 'branch_id'], 'panel_adjustment_owner_unique');
            $t->foreign(['branch_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['invoice_id', 'organisation_id', 'branch_id'], 'panel_adjustment_invoice_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('invoices')->restrictOnDelete();
            $t->foreign(['coverage_allocation_id', 'organisation_id', 'branch_id'], 'panel_adjustment_allocation_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('coverage_allocations')->restrictOnDelete();
            $t->foreign(['panel_claim_id', 'organisation_id', 'branch_id'], 'panel_adjustment_claim_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            foreach (['requested_by_user_id', 'confirmed_by_user_id', 'withdrawn_by_user_id'] as $user) {
                $t->foreign([$user, 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
            }
        });

        Schema::table('panel_claims', function (Blueprint $t): void {
            $t->string('claim_kind', 16)->default('original');
            $t->unsignedBigInteger('parent_claim_id')->nullable()->index();
            $t->unsignedBigInteger('panel_claim_adjustment_id')->nullable();
            $t->foreign(['parent_claim_id', 'organisation_id', 'branch_id'], 'panel_claim_parent_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            $t->foreign(['panel_claim_adjustment_id', 'organisation_id', 'branch_id'], 'panel_claim_adjustment_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('panel_claim_adjustments')->restrictOnDelete();
        });

        Schema::create('panel_claim_snapshots', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organisation_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('panel_claim_id');
            $t->unsignedInteger('version');
            $t->bigInteger('invoice_total_sen');
            $t->bigInteger('panel_adjustment_sen');
            $t->bigInteger('panel_share_sen');
            $t->bigInteger('claim_amount_sen');
            $t->unsignedBigInteger('panel_claim_adjustment_id')->nullable();
            $t->unsignedBigInteger('created_by_user_id');
            $t->timestampTz('created_at');
            $t->unique(['panel_claim_id', 'version']);
            $t->foreign(['panel_claim_id', 'organisation_id', 'branch_id'], 'panel_claim_snapshot_claim_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('panel_claims')->restrictOnDelete();
            $t->foreign(['panel_claim_adjustment_id', 'organisation_id', 'branch_id'], 'panel_claim_snapshot_adjustment_owner_fk')->references(['id', 'organisation_id', 'branch_id'])->on('panel_claim_adjustments')->restrictOnDelete();
            $t->foreign(['created_by_user_id', 'organisation_id'])->references(['id', 'organisation_id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE panel_claim_adjustments ADD CONSTRAINT panel_adjustment_shape CHECK (
 direction IN ('add','deduct') AND amount_sen BETWEEN 1 AND 999999999999
 AND reason_code IN ('panel_service_charge','panel_tax','panel_tariff_change','panel_company_correction','clinic_correction','other')
 AND (note IS NULL OR length(btrim(note)) BETWEEN 1 AND 500) AND (reason_code<>'other' OR note IS NOT NULL)
 AND invoice_version>0 AND lock_version>0
 AND ((status='requested' AND confirmed_by_user_id IS NULL AND confirmed_at IS NULL AND confirm_idempotency_key IS NULL AND withdrawn_by_user_id IS NULL AND withdrawn_at IS NULL)
  OR (status='confirmed' AND confirmed_by_user_id IS NOT NULL AND confirmed_at IS NOT NULL AND confirm_idempotency_key IS NOT NULL AND withdrawn_by_user_id IS NULL AND withdrawn_at IS NULL)
  OR (status='withdrawn' AND withdrawn_by_user_id IS NOT NULL AND withdrawn_at IS NOT NULL AND confirmed_by_user_id IS NULL AND confirmed_at IS NULL AND confirm_idempotency_key IS NULL)));
CREATE UNIQUE INDEX panel_adjustment_one_waiting_per_invoice ON panel_claim_adjustments (invoice_id) WHERE status='requested';

ALTER TABLE panel_claims DROP CONSTRAINT panel_claims_coverage_allocation_id_unique;
CREATE UNIQUE INDEX panel_claims_one_original_per_allocation ON panel_claims (coverage_allocation_id) WHERE claim_kind='original';
CREATE UNIQUE INDEX panel_claims_one_supplementary_per_adjustment ON panel_claims (panel_claim_adjustment_id) WHERE panel_claim_adjustment_id IS NOT NULL;
ALTER TABLE panel_claims ADD CONSTRAINT panel_claims_kind_shape CHECK (
 (claim_kind='original' AND parent_claim_id IS NULL AND panel_claim_adjustment_id IS NULL)
 OR (claim_kind='supplementary' AND parent_claim_id IS NOT NULL AND panel_claim_adjustment_id IS NOT NULL));

ALTER TABLE panel_claim_events DROP CONSTRAINT panel_claim_events_shape;
ALTER TABLE panel_claim_events ADD CONSTRAINT panel_claim_events_shape CHECK (
 event_type IN ('prepared','amount_adjusted','submitted','queried','decision_accepted','decision_partially_accepted','decision_rejected','note','panel_adjusted','supplementary_created')
 AND (previous_amount_sen IS NULL OR previous_amount_sen BETWEEN 0 AND 999999999999)
 AND (amount_sen IS NULL OR amount_sen BETWEEN 0 AND 999999999999)
 AND ((event_type='prepared' AND amount_sen IS NOT NULL)
  OR (event_type='amount_adjusted' AND previous_amount_sen IS NOT NULL AND amount_sen IS NOT NULL AND note IS NOT NULL)
  OR (event_type='submitted' AND amount_sen IS NOT NULL)
  OR (event_type='queried' AND note IS NOT NULL)
  OR (event_type LIKE 'decision_%' AND amount_sen IS NOT NULL AND note IS NOT NULL)
  OR (event_type='note' AND note IS NOT NULL)
  OR (event_type='panel_adjusted' AND previous_amount_sen IS NOT NULL AND amount_sen IS NOT NULL)
  OR (event_type='supplementary_created' AND amount_sen IS NOT NULL)));

ALTER TABLE panel_claim_snapshots ADD CONSTRAINT panel_claim_snapshot_shape CHECK (
 version>0 AND invoice_total_sen BETWEEN 0 AND 999999999999 AND panel_adjustment_sen BETWEEN -999999999999 AND 999999999999
 AND panel_share_sen BETWEEN 0 AND 999999999999 AND claim_amount_sen BETWEEN 1 AND 999999999999
 AND (version=1 OR panel_claim_adjustment_id IS NOT NULL));

-- Confirmed adjustments on a current approved allocation only: after a void the allocation is superseded and they drop away.
CREATE OR REPLACE FUNCTION kpone_panel_adjustment_sum(invoice_key bigint) RETURNS bigint LANGUAGE sql STABLE AS $$
 SELECT coalesce(sum(CASE a.direction WHEN 'add' THEN a.amount_sen ELSE -a.amount_sen END),0)::bigint
 FROM panel_claim_adjustments a JOIN coverage_allocations c ON c.id=a.coverage_allocation_id AND c.status='approved'
 WHERE a.invoice_id=invoice_key AND a.status='confirmed'
$$;
CREATE OR REPLACE FUNCTION kpone_panel_effective(invoice_key bigint) RETURNS bigint LANGUAGE sql STABLE AS $$
 SELECT coalesce((SELECT sum(amount_sen) FROM coverage_allocations WHERE invoice_id=invoice_key AND status='approved'),0)::bigint+kpone_panel_adjustment_sum(invoice_key)
$$;
-- kpone_invoice_payable stays the day-story figure (billed plus discount or surcharge); this one adds Panel claim adjustments.
CREATE OR REPLACE FUNCTION kpone_invoice_payable_effective(invoice_key bigint) RETURNS bigint LANGUAGE sql STABLE AS $$
 SELECT kpone_invoice_payable(invoice_key)+kpone_panel_adjustment_sum(invoice_key)
$$;

CREATE OR REPLACE FUNCTION kpone_panel_adjustment_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Panel claim adjustment evidence is retained' USING ERRCODE='23514'; END $$;

CREATE OR REPLACE FUNCTION kpone_panel_adjustment_can_confirm(user_key bigint) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS (SELECT 1 FROM model_has_roles mr JOIN role_has_permissions rp ON rp.role_id=mr.role_id JOIN permissions p ON p.id=rp.permission_id
   WHERE mr.model_id=user_key AND mr.model_type='App\Models\User' AND p.name='panel.claims.adjustments.confirm.branch')
 OR EXISTS (SELECT 1 FROM model_has_permissions mp JOIN permissions p ON p.id=mp.permission_id
   WHERE mp.model_id=user_key AND mp.model_type='App\Models\User' AND p.name='panel.claims.adjustments.confirm.branch')
$$;

CREATE OR REPLACE FUNCTION kpone_panel_adjustment_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE inv record; alloc record; claim record;
BEGIN
 IF TG_OP='INSERT' THEN
  SELECT * INTO inv FROM invoices WHERE id=NEW.invoice_id FOR UPDATE;
  SELECT * INTO alloc FROM coverage_allocations WHERE id=NEW.coverage_allocation_id;
  IF inv.id IS NULL OR inv.status<>'finalized' OR inv.current_visit_guard IS NULL OR inv.organisation_id<>NEW.organisation_id OR inv.branch_id<>NEW.branch_id
   OR alloc.id IS NULL OR alloc.status<>'approved' OR alloc.invoice_id<>NEW.invoice_id OR alloc.panel_id IS NULL
   OR NEW.status<>'requested' OR NEW.lock_version<>1 OR NEW.invoice_version<>inv.lock_version THEN
   RAISE EXCEPTION 'A Panel claim adjustment needs a current finalized invoice with an approved Panel share' USING ERRCODE='23514';
  END IF;
  IF NEW.panel_claim_id IS NOT NULL THEN
   SELECT * INTO claim FROM panel_claims WHERE id=NEW.panel_claim_id;
   IF claim.coverage_allocation_id<>NEW.coverage_allocation_id OR claim.claim_kind<>'original' THEN
    RAISE EXCEPTION 'A Panel claim adjustment belongs to the original claim of its Panel share' USING ERRCODE='23514';
   END IF;
  END IF;
  RETURN NEW;
 END IF;
 IF TG_OP<>'UPDATE' THEN RAISE EXCEPTION 'Panel claim adjustment evidence is retained' USING ERRCODE='23514'; END IF;
 IF OLD.status<>'requested' OR NEW.status NOT IN ('confirmed','withdrawn') OR NEW.lock_version<>OLD.lock_version+1
  OR (to_jsonb(NEW) - ARRAY['status','confirmed_by_user_id','confirmed_at','confirm_idempotency_key','withdrawn_by_user_id','withdrawn_at','lock_version','updated_at'])
   IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','confirmed_by_user_id','confirmed_at','confirm_idempotency_key','withdrawn_by_user_id','withdrawn_at','lock_version','updated_at']) THEN
  RAISE EXCEPTION 'A Panel claim adjustment can only be confirmed or withdrawn once, never edited' USING ERRCODE='23514';
 END IF;
 IF NEW.status='confirmed' THEN
  SELECT * INTO inv FROM invoices WHERE id=NEW.invoice_id FOR UPDATE;
  SELECT * INTO alloc FROM coverage_allocations WHERE id=NEW.coverage_allocation_id;
  IF NOT kpone_panel_adjustment_can_confirm(NEW.confirmed_by_user_id) THEN
   RAISE EXCEPTION 'Confirming a Panel claim adjustment needs the confirm permission' USING ERRCODE='23514';
  END IF;
  IF inv.status<>'finalized' OR inv.current_visit_guard IS NULL OR alloc.status<>'approved' THEN
   RAISE EXCEPTION 'A Panel claim adjustment needs a current finalized invoice with an approved Panel share' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_panel_adjustment_reconcile() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM kpone_billing_check_invoice(NEW.invoice_id);
 IF kpone_panel_effective(NEW.invoice_id)<0 THEN RAISE EXCEPTION 'The Panel share cannot go below zero' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION kpone_panel_claim_kind_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent record; adj record;
BEGIN
 IF TG_OP='UPDATE' THEN
  IF NEW.claim_kind IS DISTINCT FROM OLD.claim_kind OR NEW.parent_claim_id IS DISTINCT FROM OLD.parent_claim_id OR NEW.panel_claim_adjustment_id IS DISTINCT FROM OLD.panel_claim_adjustment_id THEN
   RAISE EXCEPTION 'Panel claim kind and links are immutable' USING ERRCODE='23514';
  END IF;
  RETURN NEW;
 END IF;
 IF NEW.claim_kind='supplementary' THEN
  SELECT * INTO parent FROM panel_claims WHERE id=NEW.parent_claim_id;
  SELECT * INTO adj FROM panel_claim_adjustments WHERE id=NEW.panel_claim_adjustment_id;
  IF parent.claim_kind<>'original' OR parent.coverage_allocation_id<>NEW.coverage_allocation_id OR parent.invoice_id<>NEW.invoice_id OR parent.panel_id<>NEW.panel_id
   OR adj.status<>'confirmed' OR adj.direction<>'add' OR adj.coverage_allocation_id<>NEW.coverage_allocation_id OR adj.amount_sen<>NEW.claim_amount_sen OR NEW.status<>'prepared' THEN
   RAISE EXCEPTION 'A supplementary claim must match its original claim and its confirmed plus adjustment' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_panel_claim_snapshot_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'Panel claim versions are retained' USING ERRCODE='23514'; END IF;
 PERFORM id FROM panel_claims WHERE id=NEW.panel_claim_id FOR UPDATE;
 IF NEW.version<>coalesce((SELECT max(version) FROM panel_claim_snapshots WHERE panel_claim_id=NEW.panel_claim_id),0)+1 THEN
  RAISE EXCEPTION 'Panel claim versions are numbered in order' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
SQL);

        DB::unprepared('CREATE TRIGGER panel_claim_adjustments_guard BEFORE INSERT OR UPDATE OR DELETE ON panel_claim_adjustments FOR EACH ROW EXECUTE FUNCTION kpone_panel_adjustment_guard()');
        DB::unprepared('CREATE TRIGGER panel_claim_adjustments_no_truncate BEFORE TRUNCATE ON panel_claim_adjustments FOR EACH STATEMENT EXECUTE FUNCTION kpone_panel_adjustment_retained()');
        DB::unprepared('CREATE CONSTRAINT TRIGGER panel_claim_adjustments_reconcile AFTER INSERT OR UPDATE ON panel_claim_adjustments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_panel_adjustment_reconcile()');
        DB::unprepared('CREATE TRIGGER panel_claim_kind_guard BEFORE INSERT OR UPDATE ON panel_claims FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_kind_guard()');
        DB::unprepared('CREATE TRIGGER panel_claim_snapshots_guard BEFORE INSERT OR UPDATE OR DELETE ON panel_claim_snapshots FOR EACH ROW EXECUTE FUNCTION kpone_panel_claim_snapshot_guard()');
        DB::unprepared('CREATE TRIGGER panel_claim_snapshots_no_truncate BEFORE TRUNCATE ON panel_claim_snapshots FOR EACH STATEMENT EXECUTE FUNCTION kpone_panel_adjustment_retained()');

        // Both money checks read the effective Panel share and payable; every other rule in them is kept verbatim.
        $this->patch('kpone_billing_check_invoice', [
            "SELECT coalesce(sum(amount_sen),0) INTO panel_amount FROM coverage_allocations WHERE invoice_id=invoice_key AND status='approved';" => 'SELECT kpone_panel_effective(invoice_key) INTO panel_amount;',
            'IF paid<0 OR paid+panel_amount+deferred_amount>kpone_invoice_payable(inv.id) THEN' => 'IF paid<0 OR panel_amount<0 OR paid+panel_amount+deferred_amount>kpone_invoice_payable_effective(inv.id) THEN',
        ], 'RETURNS void');
        $this->patch('kpone_completed_visit_evidence', [
            "SELECT coalesce(sum(amount_sen),0) INTO panel_amount FROM coverage_allocations WHERE invoice_id=inv.id AND status='approved';" => 'SELECT kpone_panel_effective(inv.id) INTO panel_amount;',
            "paid+panel_amount+deferred_amount<>kpone_invoice_payable(inv.id) OR (EXISTS (SELECT 1 FROM invoice_adjustments WHERE invoice_id=inv.id) AND (evidence->'financial_revision'->>'total' IS DISTINCT FROM kpone_invoice_payable(inv.id)::text" => "paid+panel_amount+deferred_amount<>kpone_invoice_payable_effective(inv.id) OR ((EXISTS (SELECT 1 FROM invoice_adjustments WHERE invoice_id=inv.id) OR kpone_panel_adjustment_sum(inv.id)<>0) AND (evidence->'financial_revision'->>'total' IS DISTINCT FROM kpone_invoice_payable_effective(inv.id)::text",
        ], 'RETURNS trigger');

        // Version 1 of every existing claim: the frozen invoice total and the amount the claim was first prepared at
        // (a later claim-only edit may have changed the live amount since).
        DB::unprepared(<<<'SQL'
INSERT INTO panel_claim_snapshots (public_id, organisation_id, branch_id, panel_claim_id, version, invoice_total_sen, panel_adjustment_sen, panel_share_sen, claim_amount_sen, panel_claim_adjustment_id, created_by_user_id, created_at)
SELECT gen_random_uuid(), c.organisation_id, c.branch_id, c.id, 1, c.invoice_total_sen, 0, a.amount_sen,
 coalesce((SELECT e.amount_sen FROM panel_claim_events e WHERE e.panel_claim_id=c.id AND e.event_type='prepared' ORDER BY e.occurred_at, e.id LIMIT 1), c.claim_amount_sen),
 NULL, c.created_by_user_id, c.created_at
FROM panel_claims c JOIN coverage_allocations a ON a.id=c.coverage_allocation_id;
SQL);
    }

    /** @param array<string, string> $replacements */
    private function patch(string $name, array $replacements, string $returns): void
    {
        $definition = DB::selectOne('SELECT prosrc, pg_get_function_arguments(oid) AS arguments FROM pg_proc WHERE proname = ?', [$name]);
        $source = (string) $definition->prosrc;
        foreach ($replacements as $old => $new) {
            if (substr_count($source, $old) !== 1) {
                throw new RuntimeException("Unexpected {$name} shape.");
            }
            $source = str_replace($old, $new, $source);
        }
        // @phpstan-ignore argument.type
        DB::unprepared("CREATE OR REPLACE FUNCTION {$name}({$definition->arguments}) {$returns} LANGUAGE plpgsql AS \$kpone\$".$source.'$kpone$');
    }

    public function down(): void {}
};
