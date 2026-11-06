<?php

namespace Tests\Feature\Agents\AskKpone;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Queue\QueueTestCase;
use Tests\Support\AiReadViewList;

/**
 * AG-7: every view of schema ai_read masks patients and staff. The exact view and column lists are pinned here,
 * so a migration that adds a view or a column to ai_read fails until this test is updated after a privacy review.
 */
class AiReadSchemaMaskingTest extends QueueTestCase
{
    /** Every ai_read view and its exact columns, in order. */
    private const COLUMNS = [
        'branches' => ['id', 'code', 'name', 'timezone', 'is_active'],
        'consultation_diagnoses' => ['id', 'clinical_encounter_id', 'position', 'is_primary', 'diagnosis_code', 'code_system'],
        'consultations' => ['id', 'visit_id', 'branch_id', 'attending_clinician_user_id', 'status', 'started_at', 'diagnosis_count',
            'primary_diagnosis_code', 'has_vitals', 'medicine_order_count', 'service_order_count'],
        'dispensary_cases' => ['id', 'visit_id', 'branch_id', 'case_type', 'status', 'received_at', 'started_at', 'completed_at'],
        'dispensary_items' => ['id', 'dispensary_case_id', 'branch_id', 'medicine_catalogue_item_id', 'medicine_code', 'medicine_name',
            'strength', 'dosage_form', 'unit', 'quantity_ordered', 'quantity_dispensed', 'status', 'source', 'change_state'],
        'inventory_stock' => ['id', 'branch_id', 'location_code', 'location_type', 'sku_code', 'item_code', 'generic_name', 'brand_name',
            'batch_number', 'expiry_date', 'quantity', 'stock_unit'],
        'invoice_lines' => ['id', 'invoice_id', 'line_type', 'code', 'display_name', 'unit', 'quantity', 'unit_price_sen', 'line_total_sen'],
        'invoices' => ['id', 'invoice_number', 'branch_id', 'patient_id', 'visit_id', 'status', 'currency', 'subtotal_sen', 'total_sen',
            'finalized_at', 'voided_at'],
        'medicines' => ['id', 'code', 'display_name', 'generic_name', 'strength_text', 'dosage_form', 'category', 'is_controlled', 'is_active'],
        'panel_claims' => ['id', 'branch_id', 'invoice_id', 'invoice_number', 'visit_number', 'patient_id', 'panel_id', 'panel_name',
            'visit_date', 'invoice_total_sen', 'claim_amount_sen', 'accepted_amount_sen', 'status', 'created_at'],
        'panel_responsibilities' => ['id', 'invoice_id', 'branch_id', 'panel_id', 'amount_sen', 'status', 'approved_at'],
        'panels' => ['id', 'code', 'name', 'panel_type', 'claim_due_days', 'is_active'],
        'patients' => ['id', 'patient_number', 'initials', 'identifier_type', 'identifier_last4', 'sex', 'nationality_code',
            'has_guardian_contact', 'created_at'],
        'payment_allocations' => ['id', 'payment_id', 'invoice_id', 'amount_sen'],
        'payment_refunds' => ['id', 'payment_id', 'branch_id', 'payment_method_name', 'amount_sen', 'reason_code', 'paid_out_at'],
        'payments' => ['id', 'receipt_number', 'branch_id', 'patient_id', 'payment_method_code', 'payment_method_name', 'amount_sen',
            'currency', 'status', 'received_at'],
        'queue_entries' => ['queue_kind', 'id', 'visit_id', 'branch_id', 'operational_date', 'queue_number', 'status', 'queued_at',
            'called_at', 'removed_at'],
        'staff' => ['id', 'display_name', 'roles', 'is_active'],
        'stock_movements' => ['id', 'occurred_at', 'movement_type', 'sku_code', 'batch_number', 'source_location_id',
            'destination_location_id', 'quantity', 'unit_cost_sen', 'cost_total_sen', 'cost_source'],
        'visits' => ['id', 'visit_number', 'branch_id', 'patient_id', 'visit_type', 'status', 'priority', 'intake_purpose', 'coverage_type',
            'panel_id', 'assigned_doctor_user_id', 'registered_at', 'completed_at', 'cancelled_at'],
    ];

    /** Column names that must never appear in any ai_read view. */
    private const FORBIDDEN_COLUMN = '/phone|email|address|postcode|^city$|^state$|full_name|search_name|date_of_birth|normalized_value'
        .'|password|token|secret|google|remember|session|encrypted|payload|note|remark|reason(?!_code)|instruction|precaution'
        .'|indication|diagnosis_text|member_reference|reference|person_in_charge|verification|income_tax|registration_number|allergy|(^|_)age(_|$)|birth|job_title/';

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The ai_read schema exists on PostgreSQL only.');
        }
    }

    public function test_the_schema_holds_exactly_the_reviewed_views_and_columns(): void
    {
        $views = DB::table('information_schema.views')->where('table_schema', 'ai_read')->orderBy('table_name')->pluck('table_name')->all();
        $expected = array_keys(self::COLUMNS);
        sort($expected);
        $this->assertSame([], AiReadViewList::problems($expected, $views), 'The ai_read views changed.');
        $this->assertSame($expected, $views);

        $tables = DB::table('information_schema.tables')->where('table_schema', 'ai_read')->where('table_type', '<>', 'VIEW')->count();
        $this->assertSame(0, $tables, 'ai_read holds views only.');

        foreach (self::COLUMNS as $view => $columns) {
            $actual = DB::table('information_schema.columns')->where('table_schema', 'ai_read')->where('table_name', $view)
                ->orderBy('ordinal_position')->pluck('column_name')->all();
            $this->assertSame($columns, $actual, "The columns of ai_read.{$view} changed: review their masking and update this test.");
            foreach ($actual as $column) {
                $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_COLUMN, $column, "ai_read.{$view}.{$column} looks like personal or free-text data.");
            }
        }
    }

    public function test_no_view_returns_names_identifiers_contacts_clinical_text_or_credentials(): void
    {
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        DB::table('users')->where('id', $doctor->id)->update(['email' => 'sentinel.doctor@kpone.test']);
        $visit = $this->consultationVisit($ca, $doctor, ['visit_reason' => 'SENTINEL VISIT REASON']);
        DB::table('patients')->where('id', $visit->patient_id)->update([
            'full_name' => 'Siti Sentinelia binti Ahmad',
            'search_name' => 'siti sentinelia binti ahmad',
            'mobile_phone' => '+60129876543',
            'email' => 'sentinel.patient@kpone.test',
            'address_line_1' => '7 Jalan Sentinel',
            'postcode' => '43000',
            'date_of_birth' => '1990-01-01',
        ]);
        DB::table('patient_identifiers')->where('patient_id', $visit->patient_id)->update(['normalized_value' => '900101145678']);

        $encounter = ClinicalEncounter::factory()->create(['visit_id' => $visit->id, 'clinical_note' => 'SENTINEL CLINICAL NOTE']);
        EncounterDiagnosis::factory()->create([
            'clinical_encounter_id' => $encounter->id,
            'diagnosis_text' => 'SENTINEL DIAGNOSIS TEXT',
            'diagnosis_code' => 'J06.9',
            'code_system' => 'ICD-10',
            'is_primary' => true,
        ]);
        Panel::factory()->create([
            'organisation_id' => $this->organisation->id,
            'phone' => '+60311112222',
            'email' => 'sentinel.panel@kpone.test',
            'person_in_charge' => 'SENTINEL PANEL CONTACT',
        ]);

        $dump = '';
        foreach (array_keys(self::COLUMNS) as $view) {
            $dump .= implode("\n", DB::table(DB::raw("ai_read.{$view} AS v"))->selectRaw('row_to_json(v)::text AS r')->pluck('r')->all())."\n";
        }

        foreach ([
            'Siti', 'Sentinelia', 'siti sentinelia', '+60129876543', '0129876543', 'sentinel.patient@kpone.test', 'Jalan Sentinel',
            '1990-01-01', '900101145678', 'SENTINEL VISIT REASON', 'SENTINEL CLINICAL NOTE',
            'SENTINEL DIAGNOSIS TEXT', 'sentinel.doctor@kpone.test', '+60311112222', 'sentinel.panel@kpone.test', 'SENTINEL PANEL CONTACT',
            '$2y$',
        ] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $dump, "An ai_read view exposes {$sentinel}.");
        }

        $patient = DB::table('ai_read.patients')->where('id', $visit->patient_id)->first();
        $this->assertNotNull($patient);
        $this->assertSame('S. S. B. A.', $patient->initials);
        $this->assertSame('••••5678', $patient->identifier_last4);

        DB::table('patient_identifiers')->where('patient_id', $visit->patient_id)->update(['normalized_value' => 'A1234']);
        $this->assertSame('••••', DB::table('ai_read.patients')->where('id', $visit->patient_id)->value('identifier_last4'),
            'An identifier shorter than 8 characters is fully masked.');

        $consultation = DB::table('ai_read.consultations')->where('id', $encounter->id)->first();
        $this->assertNotNull($consultation);
        $this->assertSame('J06.9', $consultation->primary_diagnosis_code);
        $this->assertSame(1, (int) $consultation->diagnosis_count);

        $staff = DB::table('ai_read.staff')->where('id', $doctor->id)->first();
        $this->assertNotNull($staff);
        $this->assertSame($doctor->name, $staff->display_name);
        $this->assertSame('resident_doctor', $staff->roles);
    }
}
