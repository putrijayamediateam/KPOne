<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION kpone_billing_check_invoice(invoice_key bigint) RETURNS void LANGUAGE plpgsql AS $$
DECLARE inv record; paid bigint; panel_amount bigint; deferred_amount bigint; line_sum bigint;
BEGIN
    SELECT * INTO inv FROM invoices WHERE id=invoice_key FOR UPDATE;
    IF NOT FOUND THEN RETURN; END IF;
    IF NOT EXISTS (SELECT 1 FROM consultation_checkouts c WHERE c.id=inv.consultation_checkout_id AND c.visit_id=inv.visit_id AND c.patient_id=inv.patient_id AND c.organisation_id=inv.organisation_id AND c.branch_id=inv.branch_id) THEN
        RAISE EXCEPTION 'Billing source ownership mismatch' USING ERRCODE='23514';
    END IF;
    IF inv.replaces_public_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM invoices original WHERE original.public_id=inv.replaces_public_id AND original.visit_id=inv.visit_id AND original.status='voided') THEN
        RAISE EXCEPTION 'Invoice replacement provenance mismatch' USING ERRCODE='23514';
    END IF;
    SELECT coalesce(sum(line_total_sen),0) INTO line_sum FROM invoice_lines WHERE invoice_id=invoice_key;
    IF line_sum<>inv.subtotal_sen OR NOT EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id=invoice_key AND line_type='consultation') THEN
        RAISE EXCEPTION 'Invoice line reconciliation failed' USING ERRCODE='23514';
    END IF;
    IF EXISTS (
        SELECT 1 FROM invoice_lines l JOIN price_entries p ON p.id=l.price_entry_id JOIN price_books b ON b.id=p.price_book_id JOIN charge_definitions d ON d.id=l.charge_definition_id
        WHERE l.invoice_id=invoice_key AND ((b.branch_id IS NOT NULL AND b.branch_id<>inv.branch_id) OR p.charge_definition_id<>d.id OR p.version<>l.price_version OR p.unit_price_sen<>l.unit_price_sen OR d.type<>l.line_type OR d.unit<>l.unit_snapshot
        OR (l.line_type='consultation' AND (l.consultation_checkout_id<>inv.consultation_checkout_id OR l.quantity<>1 OR l.source_key<>'consultation:'||l.consultation_checkout_id::text))
        OR (l.line_type='service' AND NOT EXISTS (SELECT 1 FROM service_deliveries s JOIN treatment_plan_service_orders o ON o.id=s.treatment_plan_service_order_id WHERE s.id=l.service_delivery_id AND s.consultation_checkout_id=inv.consultation_checkout_id AND s.disposition='performed' AND s.quantity_performed=l.quantity AND d.clinical_service_catalogue_item_id=o.clinical_service_catalogue_item_id AND l.source_key='service:'||s.id::text))
        OR (l.line_type='medicine' AND NOT EXISTS (SELECT 1 FROM dispensary_items i JOIN dispensary_handoffs h ON h.id=i.dispensary_handoff_id JOIN consultation_checkouts c ON c.dispensary_handoff_id=h.id WHERE i.id=l.dispensary_item_id AND c.id=inv.consultation_checkout_id AND h.status='completed' AND i.status IN ('dispensed','partial') AND i.quantity_dispensed=l.quantity AND d.medicine_catalogue_item_id=i.medicine_catalogue_item_id AND l.source_key='medicine:'||i.id::text)))
    ) THEN RAISE EXCEPTION 'Invoice typed source or price mismatch' USING ERRCODE='23514'; END IF;
    SELECT coalesce(sum(a.amount_sen),0)-coalesce((SELECT sum(r.amount_sen) FROM payment_reversals r WHERE r.invoice_id=invoice_key),0) INTO paid FROM payment_allocations a WHERE a.invoice_id=invoice_key;
    SELECT coalesce(sum(amount_sen),0) INTO panel_amount FROM coverage_allocations WHERE invoice_id=invoice_key AND status='approved';
    SELECT coalesce(sum(remaining_sen),0) INTO deferred_amount FROM patient_receivables WHERE invoice_id=invoice_key AND status='approved';
    IF paid<0 OR paid+panel_amount+deferred_amount>inv.total_sen THEN RAISE EXCEPTION 'Invoice responsibility exceeds total' USING ERRCODE='23514'; END IF;
    IF EXISTS (SELECT 1 FROM payment_allocations a JOIN patient_receivables r ON r.id=a.patient_receivable_id WHERE a.invoice_id=invoice_key AND r.invoice_id<>a.invoice_id)
        OR EXISTS (SELECT 1 FROM patient_receivables r WHERE r.invoice_id=invoice_key AND r.status='approved' AND r.remaining_sen<>r.amount_sen-coalesce((SELECT sum(a.deferment_applied_sen) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.patient_receivable_id=r.id AND p.status='posted'),0)) THEN
        RAISE EXCEPTION 'Deferred balance must reconcile to immutable receipt allocations' USING ERRCODE='23514';
    END IF;
    IF inv.status<>'finalized' AND (paid<>0 OR panel_amount<>0 OR deferred_amount<>0) THEN RAISE EXCEPTION 'Financial allocations require finalized Invoice' USING ERRCODE='23514'; END IF;
    IF EXISTS (SELECT 1 FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.invoice_id=invoice_key AND (a.amount_sen<>p.amount_sen OR p.patient_id<>inv.patient_id OR p.currency<>inv.currency)) THEN
        RAISE EXCEPTION 'Payment allocation ownership or amount mismatch' USING ERRCODE='23514';
    END IF;
    IF EXISTS (SELECT 1 FROM payment_reversals r JOIN payments p ON p.id=r.payment_id LEFT JOIN payment_allocations a ON a.payment_id=p.id WHERE r.invoice_id=invoice_key AND (a.invoice_id IS DISTINCT FROM invoice_key OR r.amount_sen<>a.amount_sen OR p.status<>'reversed' OR r.approved_by_user_id=p.recorded_by_user_id)) THEN
        RAISE EXCEPTION 'Payment reversal reconciliation failed' USING ERRCODE='23514';
    END IF;
END $$;

CREATE OR REPLACE FUNCTION kpone_billing_reconcile() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE invoice_key bigint; payment_key bigint; p record;
BEGIN
    IF TG_TABLE_NAME='invoices' THEN
        invoice_key := CASE WHEN TG_OP='DELETE' THEN OLD.id ELSE NEW.id END;
    ELSIF TG_TABLE_NAME='payments' THEN
        payment_key := CASE WHEN TG_OP='DELETE' THEN OLD.id ELSE NEW.id END;
        SELECT * INTO p FROM payments WHERE id=payment_key;
        IF FOUND THEN
            SELECT invoice_id INTO invoice_key FROM payment_allocations WHERE payment_id=payment_key;
            IF invoice_key IS NULL OR (p.status='reversed') IS DISTINCT FROM EXISTS (SELECT 1 FROM payment_reversals WHERE payment_id=payment_key) THEN
                RAISE EXCEPTION 'Receipt must be fully allocated with exact reversal evidence' USING ERRCODE='23514';
            END IF;
        END IF;
    ELSE invoice_key := CASE WHEN TG_OP='DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END;
    END IF;
    IF invoice_key IS NOT NULL THEN PERFORM kpone_billing_check_invoice(invoice_key); END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION kpone_billing_child_anchor() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP='UPDATE' AND (NEW.invoice_id<>OLD.invoice_id OR NEW.organisation_id<>OLD.organisation_id OR NEW.branch_id<>OLD.branch_id OR NEW.public_id<>OLD.public_id) THEN
        RAISE EXCEPTION 'Financial ownership is immutable' USING ERRCODE='23514';
    END IF;
    PERFORM id FROM invoices WHERE id=CASE WHEN TG_OP='DELETE' THEN OLD.invoice_id ELSE NEW.invoice_id END FOR UPDATE;
    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_billing_retained_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE retained boolean;
BEGIN
    IF TG_TABLE_NAME='price_entries' THEN
        retained := EXISTS (SELECT 1 FROM organisations WHERE id=OLD.organisation_id);
    ELSIF TG_TABLE_NAME='invoice_lines' THEN
        retained := EXISTS (SELECT 1 FROM invoices WHERE id=OLD.invoice_id AND status<>'draft');
    ELSE retained := EXISTS (SELECT 1 FROM invoices WHERE id=OLD.invoice_id);
    END IF;
    IF retained THEN RAISE EXCEPTION 'Final financial evidence is immutable and retained' USING ERRCODE='23514'; END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION kpone_invoice_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.status<>OLD.status AND NOT ((OLD.status='draft' AND NEW.status='finalized') OR (OLD.status='finalized' AND NEW.status='voided')) THEN
        RAISE EXCEPTION 'Invalid Invoice lifecycle transition' USING ERRCODE='23514';
    END IF;
    IF NEW.public_id<>OLD.public_id OR NEW.organisation_id<>OLD.organisation_id OR NEW.branch_id<>OLD.branch_id OR NEW.patient_id<>OLD.patient_id OR NEW.visit_id<>OLD.visit_id OR NEW.created_by_user_id<>OLD.created_by_user_id THEN
        RAISE EXCEPTION 'Invoice identity is immutable' USING ERRCODE='23514';
    END IF;
    IF OLD.status<>'draft' AND ((to_jsonb(NEW)-ARRAY['status','lock_version','current_visit_guard','voided_at','voided_by_user_id','void_reason','correction_hold','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','lock_version','current_visit_guard','voided_at','voided_by_user_id','void_reason','correction_hold','updated_at']) OR (NEW.status<>OLD.status AND NOT (OLD.status='finalized' AND NEW.status='voided'))) THEN
        RAISE EXCEPTION 'Finalized Invoice snapshots are immutable' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_payment_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF (to_jsonb(NEW)-ARRAY['status','lock_version','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','lock_version','updated_at']) OR (NEW.status<>OLD.status AND NOT (OLD.status='posted' AND NEW.status='reversed')) THEN
        RAISE EXCEPTION 'Receipt facts are immutable' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_completed_visit_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE inv record; paid bigint; panel_amount bigint; deferred_amount bigint; evidence jsonb;
BEGIN
    IF TG_OP='INSERT' THEN
        IF NEW.status='completed' THEN RAISE EXCEPTION 'Visit completion requires a registered transition' USING ERRCODE='23514'; END IF;
        RETURN NULL;
    END IF;
    IF OLD.status='completed' AND (NEW.status<>OLD.status OR NEW.completed_at IS DISTINCT FROM OLD.completed_at OR NEW.completed_by_user_id IS DISTINCT FROM OLD.completed_by_user_id OR NEW.completion_evidence::jsonb IS DISTINCT FROM OLD.completion_evidence::jsonb) THEN
        RAISE EXCEPTION 'Completed Visit evidence is historical and immutable' USING ERRCODE='23514';
    END IF;
    IF NEW.status='completed' AND OLD.status<>'completed' THEN
        evidence := NEW.completion_evidence::jsonb;
        SELECT * INTO inv FROM invoices WHERE current_visit_guard=NEW.id;
        IF NOT FOUND OR OLD.status<>'registered' OR NEW.visit_type<>'consultation' OR inv.status<>'finalized' OR inv.correction_hold OR inv.source_stale
            OR evidence->>'invoice_public_id' IS DISTINCT FROM inv.public_id::text OR evidence->>'invoice_version' IS DISTINCT FROM inv.lock_version::text OR evidence->>'source_hash' IS DISTINCT FROM inv.source_hash
            OR NOT EXISTS (SELECT 1 FROM queue_entries WHERE visit_id=NEW.id AND status='removed')
            OR NOT EXISTS (SELECT 1 FROM consultation_checkouts c JOIN clinical_encounters e ON e.id=c.clinical_encounter_id LEFT JOIN treatment_plans p ON p.id=c.treatment_plan_id
                WHERE c.id=inv.consultation_checkout_id AND c.current_visit_guard=NEW.id AND c.encounter_version=e.lock_version AND c.plan_version IS NOT DISTINCT FROM p.lock_version
                AND e.attending_clinician_user_id=NEW.assigned_doctor_user_id AND (c.route='billing' OR EXISTS (SELECT 1 FROM dispensary_handoffs h JOIN dispensary_cases d ON d.id=h.dispensary_case_id WHERE h.id=c.dispensary_handoff_id AND h.status='completed' AND d.status='completed')))) THEN
            RAISE EXCEPTION 'Completed Visit requires current final sources and Invoice evidence' USING ERRCODE='23514';
        END IF;
        PERFORM kpone_billing_check_invoice(inv.id);
        SELECT coalesce(sum(amount_sen),0)-(SELECT coalesce(sum(amount_sen),0) FROM payment_reversals WHERE invoice_id=inv.id) INTO paid FROM payment_allocations WHERE invoice_id=inv.id;
        SELECT coalesce(sum(amount_sen),0) INTO panel_amount FROM coverage_allocations WHERE invoice_id=inv.id AND status='approved';
        SELECT coalesce(sum(remaining_sen),0) INTO deferred_amount FROM patient_receivables WHERE invoice_id=inv.id AND status='approved';
        IF paid+panel_amount+deferred_amount<>inv.total_sen THEN RAISE EXCEPTION 'Completed Visit has unapproved due' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NULL;
END $$;

CREATE OR REPLACE FUNCTION kpone_responsibility_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE allowed text[];
BEGIN
    allowed := ARRAY['status','current_invoice_guard','lock_version','updated_at'];
    IF OLD.status='superseded' OR (NEW.status<>OLD.status AND NOT (OLD.status='proposed' AND NEW.status IN ('approved','superseded') OR OLD.status='approved' AND NEW.status='superseded')) THEN
        RAISE EXCEPTION 'Responsibility cannot be reactivated' USING ERRCODE='23514';
    END IF;
    IF OLD.status='proposed' AND NEW.status='approved' THEN allowed := allowed || ARRAY['approved_by_user_id','approved_at']; END IF;
    IF TG_TABLE_NAME='patient_receivables' AND OLD.status='approved' AND NEW.status='approved' THEN
        allowed := allowed || ARRAY['remaining_sen','last_payment_id','settled_at'];
        IF (to_jsonb(NEW)->>'remaining_sen')::bigint>(to_jsonb(OLD)->>'remaining_sen')::bigint THEN
            RAISE EXCEPTION 'Approved deferment cannot expand' USING ERRCODE='23514';
        END IF;
    END IF;
    IF (to_jsonb(NEW)-allowed) IS DISTINCT FROM (to_jsonb(OLD)-allowed) THEN RAISE EXCEPTION 'Responsibility facts are immutable' USING ERRCODE='23514'; END IF;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION kpone_financial_document_retained() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME='invoices' THEN
        IF EXISTS (SELECT 1 FROM visits WHERE id=OLD.visit_id) THEN RAISE EXCEPTION 'Invoice must be retained' USING ERRCODE='23514'; END IF;
    ELSIF EXISTS (SELECT 1 FROM organisations WHERE id=OLD.organisation_id) THEN
        RAISE EXCEPTION 'Receipt must be retained' USING ERRCODE='23514';
    END IF;
    RETURN NULL;
END $$;
SQL);
        foreach (['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables'] as $table) {
            DB::unprepared("CREATE CONSTRAINT TRIGGER {$table}_billing_reconcile AFTER INSERT OR UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_billing_reconcile()");
        }
        foreach (['invoice_lines', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_billing_anchor BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION kpone_billing_child_anchor()");
        }
        foreach (['invoice_lines', 'price_entries', 'payment_allocations', 'payment_reversals'] as $table) {
            DB::unprepared("CREATE CONSTRAINT TRIGGER {$table}_retained AFTER UPDATE OR DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_billing_retained_evidence()");
        }
        foreach (['coverage_allocations', 'patient_receivables'] as $table) {
            DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE ON {$table} FOR EACH ROW EXECUTE FUNCTION kpone_responsibility_immutable()");
        }
        foreach (['invoices', 'payments'] as $table) {
            DB::unprepared("CREATE CONSTRAINT TRIGGER {$table}_document_retained AFTER DELETE ON {$table} DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_financial_document_retained()");
        }
        DB::unprepared('CREATE TRIGGER invoice_immutable BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION kpone_invoice_immutable(); CREATE TRIGGER payment_immutable BEFORE UPDATE ON payments FOR EACH ROW EXECUTE FUNCTION kpone_payment_immutable(); CREATE CONSTRAINT TRIGGER completed_visit_evidence AFTER INSERT OR UPDATE ON visits DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION kpone_completed_visit_evidence();');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_billing_reconcile ON {$table}");
        }
        foreach (['invoice_lines', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_billing_anchor ON {$table}");
        }
        foreach (['invoice_lines', 'price_entries', 'payment_allocations', 'payment_reversals'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_retained ON {$table}");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS invoice_immutable ON invoices; DROP TRIGGER IF EXISTS payment_immutable ON payments; DROP TRIGGER IF EXISTS completed_visit_evidence ON visits;');
        DB::unprepared('DROP TRIGGER IF EXISTS coverage_allocations_immutable ON coverage_allocations; DROP TRIGGER IF EXISTS patient_receivables_immutable ON patient_receivables; DROP TRIGGER IF EXISTS invoices_document_retained ON invoices; DROP TRIGGER IF EXISTS payments_document_retained ON payments;');
        foreach (['kpone_responsibility_immutable()', 'kpone_financial_document_retained()', 'kpone_billing_reconcile()', 'kpone_billing_child_anchor()', 'kpone_billing_retained_evidence()', 'kpone_invoice_immutable()', 'kpone_payment_immutable()', 'kpone_completed_visit_evidence()', 'kpone_billing_check_invoice(bigint)'] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}");
        }
    }
};
