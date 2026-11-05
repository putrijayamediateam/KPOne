<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AG-7 Ask KPOne (contract section 13): schema `ai_read` of masked, read-only views over the operational tables.
 * Masking happens in the views, so unmasked values never leave the database through this path: patient names
 * become initials, NRIC or passport numbers keep only their last 4 characters, and phone, email, address,
 * clinical free text, notes, remarks, reasons, member references, encrypted columns and credentials are not
 * selected at all. Staff appear by display name and role only.
 *
 * Adding a view here requires updating AiReadSchemaMaskingTest, which lists every expected view and fails on
 * any view it does not know.
 */
return new class extends Migration
{
    private const READER = 'kpone_ai_reader';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('CREATE SCHEMA IF NOT EXISTS ai_read');
        DB::unprepared("COMMENT ON SCHEMA ai_read IS 'AG-7 Ask KPOne: masked read-only views. Synthetic data only until the PDPA and legal review.'");

        foreach ($this->views() as $name => [$description, $sql, $columns]) {
            DB::unprepared("CREATE OR REPLACE VIEW ai_read.{$name} AS {$sql}");
            // Descriptions are fixed text without single quotes, so they are written as plain SQL literals.
            DB::unprepared("COMMENT ON VIEW ai_read.{$name} IS '{$description}'");
            foreach ($columns as $column => $text) {
                DB::unprepared("COMMENT ON COLUMN ai_read.{$name}.{$column} IS '{$text}'");
            }
        }

        // The reader login is created once by the owner (docs/DEVELOPMENT.md). When it already exists, grant it the
        // new views; it never receives anything outside ai_read.
        if (DB::table('pg_roles')->where('rolname', self::READER)->exists()) {
            DB::unprepared('GRANT USAGE ON SCHEMA ai_read TO '.self::READER);
            DB::unprepared('GRANT SELECT ON ALL TABLES IN SCHEMA ai_read TO '.self::READER);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP SCHEMA IF EXISTS ai_read CASCADE');
    }

    /** @return array<literal-string, array{0: literal-string, 1: literal-string, 2: array<literal-string, literal-string>}> */
    private function views(): array
    {
        return [
            'branches' => ['Clinic branches.', <<<'SQL'
                SELECT b.id, b.code, b.name, b.timezone, b.is_active FROM public.branches b
                SQL, ['timezone' => 'IANA time zone used for the branch-local day.']],

            'staff' => ['Staff directory: display name and roles only.', <<<'SQL'
                SELECT u.id, u.name AS display_name,
                    (SELECT string_agg(r.name, ', ' ORDER BY r.name) FROM public.model_has_roles mr
                        JOIN public.roles r ON r.id = mr.role_id
                        WHERE mr.model_id = u.id AND mr.model_type = 'App\Models\User') AS roles,
                    u.is_active
                FROM public.users u
                SQL, ['roles' => 'Comma-separated role codes, e.g. ca, resident_doctor.']],

            'patients' => ['Patients, masked: initials and the last 4 identifier characters only.', <<<'SQL'
                SELECT p.id, p.patient_number,
                    (SELECT string_agg(upper(left(part, 1)) || '.', ' ')
                        FROM regexp_split_to_table(btrim(p.full_name), '\s+') AS part WHERE part <> '') AS initials,
                    ident.identifier_type, CASE WHEN ident.normalized_value IS NULL THEN NULL
                        WHEN length(ident.normalized_value) < 8 THEN '••••'
                        ELSE '••••' || right(ident.normalized_value, 4) END AS identifier_last4,
                    p.sex, p.nationality_code,
                    EXISTS (SELECT 1 FROM public.patient_guardian_contacts g WHERE g.patient_id = p.id) AS has_guardian_contact,
                    p.created_at
                FROM public.patients p
                LEFT JOIN LATERAL (SELECT i.identifier_type, i.normalized_value FROM public.patient_identifiers i
                    WHERE i.patient_id = p.id AND i.retired_at IS NULL ORDER BY i.id DESC LIMIT 1) ident ON true
                SQL, [
                'patient_number' => 'KP number; open the patient in KPOne by this number to see details.',
                'initials' => 'Initials of the registered name, e.g. A. B.',
                'identifier_last4' => 'Last 4 characters of the current NRIC or passport number; fully masked when shorter than 8.',
            ]],

            'visits' => ['Visits (registrations). Visit reasons and cancellation reasons are not included.', <<<'SQL'
                SELECT v.id, v.visit_number, v.branch_id, v.patient_id, v.visit_type, v.status, v.priority,
                    v.intake_purpose, v.coverage_type, v.panel_id, v.assigned_doctor_user_id,
                    v.registered_at, v.completed_at, v.cancelled_at
                FROM public.visits v
                SQL, ['visit_type' => 'consultation or otc.', 'registered_at' => 'UTC timestamp.']],

            'queue_entries' => ['Consultation (A numbers) and OTC (B numbers) queue entries.', <<<'SQL'
                SELECT 'consultation'::text AS queue_kind, q.id, q.visit_id, q.branch_id, q.operational_date,
                    q.queue_number, q.status, q.queued_at, q.called_at, q.removed_at
                FROM public.queue_entries q
                UNION ALL
                SELECT 'otc'::text, o.id, o.visit_id, o.branch_id, o.operational_date,
                    o.queue_number, o.status, o.queued_at, NULL::timestamp, o.removed_at
                FROM public.otc_queue_entries o
                SQL, ['queue_kind' => 'consultation or otc.', 'operational_date' => 'Branch-local clinic day.']],

            'consultations' => ['Consultations in structured form: status, times, codes and counts; no clinical text.', <<<'SQL'
                SELECT e.id, e.visit_id, e.branch_id, e.attending_clinician_user_id, e.status, e.started_at,
                    (SELECT count(*) FROM public.encounter_diagnoses d WHERE d.clinical_encounter_id = e.id) AS diagnosis_count,
                    (SELECT d.diagnosis_code FROM public.encounter_diagnoses d
                        WHERE d.clinical_encounter_id = e.id AND d.is_primary ORDER BY d.position LIMIT 1) AS primary_diagnosis_code,
                    EXISTS (SELECT 1 FROM public.encounter_vital_observations o WHERE o.clinical_encounter_id = e.id) AS has_vitals,
                    (SELECT count(*) FROM public.treatment_plan_medicine_orders m
                        JOIN public.treatment_plans tp ON tp.id = m.treatment_plan_id
                        WHERE tp.clinical_encounter_id = e.id AND m.withdrawn_at IS NULL) AS medicine_order_count,
                    (SELECT count(*) FROM public.treatment_plan_service_orders s
                        JOIN public.treatment_plans tp ON tp.id = s.treatment_plan_id
                        WHERE tp.clinical_encounter_id = e.id AND s.withdrawn_at IS NULL) AS service_order_count
                FROM public.clinical_encounters e
                SQL, ['primary_diagnosis_code' => 'Code of the primary diagnosis when one was coded; diagnosis text is not included.']],

            'consultation_diagnoses' => ['Coded diagnoses per consultation; diagnosis text is not included.', <<<'SQL'
                SELECT d.id, d.clinical_encounter_id, d.position, d.is_primary, d.diagnosis_code, d.code_system
                FROM public.encounter_diagnoses d
                SQL, []],

            'dispensary_cases' => ['Dispensary cases (consultation and OTC).', <<<'SQL'
                SELECT c.id, c.visit_id, c.branch_id, c.case_type, c.status, c.received_at, c.started_at, c.completed_at
                FROM public.dispensary_cases c
                SQL, []],

            'dispensary_items' => ['Medicines per dispensary case: catalogue identity and quantities; no directions or precautions.', <<<'SQL'
                SELECT i.id, h.dispensary_case_id, i.branch_id, i.medicine_catalogue_item_id,
                    i.medicine_code_snapshot AS medicine_code, i.medicine_name_snapshot AS medicine_name,
                    i.strength_snapshot AS strength, i.dosage_form_snapshot AS dosage_form, i.unit_snapshot AS unit,
                    i.quantity_ordered, i.quantity_dispensed, i.status, i.source, i.change_state
                FROM public.dispensary_items i
                JOIN public.dispensary_handoffs h ON h.id = i.dispensary_handoff_id
                SQL, ['source' => 'doctor (ordered) or ca (added at Dispensary).']],

            'medicines' => ['Medicine catalogue.', <<<'SQL'
                SELECT m.id, m.code, m.display_name, m.generic_name, m.strength_text, m.dosage_form, m.category,
                    m.is_controlled, m.is_active
                FROM public.medicine_catalogue_items m
                SQL, []],

            'inventory_stock' => ['Current stock on hand per location, SKU and batch.', <<<'SQL'
                SELECT sb.id, l.branch_id, l.code AS location_code, l.type AS location_type, s.sku_code,
                    it.code AS item_code, it.generic_name, it.brand_name, b.batch_number, b.expiry_date,
                    sb.quantity, s.stock_unit
                FROM public.inventory_stock_balances sb
                JOIN public.inventory_locations l ON l.id = sb.inventory_location_id
                JOIN public.inventory_skus s ON s.id = sb.inventory_sku_id
                JOIN public.inventory_items it ON it.id = s.inventory_item_id
                LEFT JOIN public.inventory_batches b ON b.id = sb.inventory_batch_id
                SQL, ['quantity' => 'Quantity in the SKU stock unit, three decimals.']],

            'stock_movements' => ['Stock ledger movements.', <<<'SQL'
                SELECT m.id, m.occurred_at, m.movement_type, s.sku_code, b.batch_number,
                    m.source_location_id, m.destination_location_id, m.quantity, m.unit_cost_sen
                FROM public.stock_movements m
                JOIN public.inventory_skus s ON s.id = m.inventory_sku_id
                LEFT JOIN public.inventory_batches b ON b.id = m.inventory_batch_id
                SQL, ['unit_cost_sen' => 'Unit cost in sen when recorded (receiving).']],

            'invoices' => ['Invoices; amounts in sen. Notes and Bill to are not included.', <<<'SQL'
                SELECT i.id, i.invoice_number, i.branch_id, i.patient_id, i.visit_id, i.status, i.currency,
                    i.subtotal_sen, i.total_sen, i.finalized_at, i.voided_at
                FROM public.invoices i
                SQL, ['total_sen' => 'Invoice total in sen (100 sen = RM 1).']],

            'invoice_lines' => ['Invoice lines; amounts in sen.', <<<'SQL'
                SELECT l.id, l.invoice_id, l.line_type, l.code_snapshot AS code, l.display_name, l.unit_snapshot AS unit,
                    l.quantity, l.unit_price_sen, l.line_total_sen
                FROM public.invoice_lines l
                SQL, []],

            'payments' => ['Patient receipts; amounts in sen. Payment references are not included.', <<<'SQL'
                SELECT p.id, p.receipt_number, p.branch_id, p.patient_id, pm.code AS payment_method_code,
                    p.method_snapshot AS payment_method_name, p.amount_sen, p.currency,
                    p.status, p.received_at
                FROM public.payments p
                LEFT JOIN public.payment_methods pm ON pm.id = p.payment_method_id
                SQL, ['payment_method_code' => 'Payment method code, e.g. CASH.']],

            'payment_allocations' => ['How receipts are allocated to invoices; amounts in sen.', <<<'SQL'
                SELECT a.id, a.payment_id, a.invoice_id, a.amount_sen FROM public.payment_allocations a
                SQL, []],

            'payment_refunds' => ['Refunds paid out; amounts in sen.', <<<'SQL'
                SELECT r.id, r.payment_id, r.branch_id, r.method_snapshot AS payment_method_name, r.amount_sen,
                    r.reason_code, r.paid_out_at
                FROM public.payment_refunds r
                SQL, ['reason_code' => 'Controlled refund reason code.']],

            'panels' => ['Panels (corporate and TPA). Contact details are not included.', <<<'SQL'
                SELECT p.id, p.code, p.name, p.panel_type, p.claim_due_days, p.is_active FROM public.panels p
                SQL, []],

            'panel_responsibilities' => ['Panel shares of invoices (coverage allocations); amounts in sen.', <<<'SQL'
                SELECT c.id, c.invoice_id, c.branch_id, c.panel_id, c.amount_sen, c.status, c.approved_at
                FROM public.coverage_allocations c
                SQL, []],

            'panel_claims' => ['Panel claims; amounts in sen. Patient names, member references and remarks are not included.', <<<'SQL'
                SELECT c.id, c.branch_id, c.invoice_id, c.invoice_number, c.visit_number, c.patient_id, c.panel_id,
                    c.panel_name_snapshot AS panel_name, c.visit_date, c.invoice_total_sen, c.claim_amount_sen,
                    c.accepted_amount_sen, c.status, c.created_at
                FROM public.panel_claims c
                SQL, []],
        ];
    }
};
