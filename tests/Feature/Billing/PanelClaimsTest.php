<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\BillingPermissions;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PanelClaimsTest extends BillingTestCase
{
    public function test_panel_officer_creates_a_submitted_minimized_claim_from_an_approved_allocation(): void
    {
        [$panelOfficer, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);

        $this->get(route('panel-claims.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PanelClaims/Index')
            ->has('work.eligible.data', 1)
            ->where('work.eligible.data.0.invoiceNumber', $invoice->invoice_number)
            ->where('work.eligible.data.0.panelName', $allocation->panel_name_snapshot)
            ->has('work.claims.data', 0));

        $this->get(route('panel-claims.create', $allocation))->assertOk()->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('PanelClaims/Create')->where('allocation.amountSen', 3000)->has('allocation.lines', 1));
        $this->post(route('panel-claims.store', $allocation), $this->creationPayload($allocation, $invoice))
            ->assertRedirect();
        $claim = PanelClaim::query()->sole();

        $this->assertSame('submitted', $claim->status);
        $this->assertSame($allocation->amount_sen, $claim->claim_amount_sen);
        $this->assertSame($invoice->total_sen, $claim->invoice_total_sen);
        $this->assertSame($visit->visit_number, $claim->visit_number);
        $this->assertDatabaseCount('panel_claim_lines', 1);
        $this->assertDatabaseHas('panel_claim_events', ['panel_claim_id' => $claim->id, 'event_type' => 'prepared']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.created', 'subject_id' => $claim->id]);

        $response = $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PanelClaims/Show')
            ->where('claim.patientName', $claim->patient_name_snapshot)
            ->where('claim.memberReference', $claim->member_reference)
            ->where('claim.invoiceNumber', $invoice->invoice_number)
            ->where('claim.adjustmentSen', 0)
            ->has('claim.lines', 1)
            ->has('claim.events', 2)
            ->where('claim.events.0.type', 'prepared')->where('claim.events.1.type', 'submitted')
            ->missing('claim.diagnoses')
            ->missing('claim.clinicalNotes')
            ->missing('claim.identityDocuments'));
        $response->assertDontSee('Synthetic private reason');
        $this->assertSame($invoice->lock_version, $invoice->refresh()->lock_version);
        $this->assertSame($allocation->amount_sen, $allocation->refresh()->amount_sen);
    }

    public function test_amount_adjustments_require_a_reason_and_confirmation_above_invoice_without_mutating_billing(): void
    {
        [$panelOfficer, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();

        $this->patch(route('panel-claims.amount', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => 1,
            'amount_sen' => 6000,
            'reason' => 'Synthetic claim adjustment for test',
        ])->assertSessionHasErrors('confirm_over_invoice');
        $this->assertSame(3000, $claim->refresh()->claim_amount_sen);

        $this->patch(route('panel-claims.amount', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => 1,
            'amount_sen' => 6000,
            'reason' => 'Synthetic claim adjustment for test',
            'confirm_over_invoice' => true,
        ])->assertRedirect();

        $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->where('claim.claimAmountSen', 6000)
            ->where('claim.adjustmentSen', 2000)
            ->has('claim.lines', 2)
            ->where('claim.lines.1.name', 'Panel claim adjustment')
            ->where('claim.lines.1.totalSen', 2000));

        $this->assertSame($invoice->total_sen, $invoice->refresh()->total_sen);
        $this->assertSame($invoice->lock_version, $invoice->refresh()->lock_version);
        $this->assertSame(3000, $allocation->refresh()->amount_sen);
        $this->assertSame('approved', $allocation->status);
    }

    public function test_manual_submission_panel_decisions_and_partial_remittances_are_audited_idempotent_and_claim_scoped(): void
    {
        [$panelOfficer, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();

        $this->post(route('panel-claims.submit', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => 1,
            'external_reference' => 'PANEL-TEST-001',
        ])->assertRedirect();
        $claim->refresh();
        $this->assertSame('submitted', $claim->status);

        $this->post(route('panel-claims.query', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $claim->lock_version,
            'external_reference' => 'PANEL-TEST-QUERY-001',
            'note' => 'Synthetic query response',
        ])->assertRedirect();
        $claim->refresh();
        $this->assertSame('queried', $claim->status);

        $this->post(route('panel-claims.submit', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $claim->lock_version,
        ])->assertRedirect();
        $claim->refresh();
        $this->post(route('panel-claims.decision', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $claim->lock_version,
            'decision' => 'partially_accepted',
            'accepted_amount_sen' => 2500,
            'note' => 'Synthetic partial acceptance',
            'external_reference' => 'PANEL-TEST-DECISION-001',
        ])->assertRedirect();
        $claim->refresh();
        $this->assertSame('partially_accepted', $claim->status);
        $this->assertSame(2500, $claim->accepted_amount_sen);

        $paymentKey = (string) Str::uuid();
        $payment = [
            'expected_branch_id' => $visit->branch_id,
            'idempotency_key' => $paymentKey,
            'amount_sen' => 1000,
            'received_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
            'reference' => 'REMITTANCE-TEST-001',
        ];
        $this->post(route('panel-claims.payment', $claim), $payment)->assertRedirect();
        $claim->refresh();
        $this->assertSame('partially_paid', $claim->status);
        $this->post(route('panel-claims.payment', $claim), $payment)->assertRedirect();
        $this->assertDatabaseCount('panel_claim_payments', 1);

        $this->post(route('panel-claims.payment', $claim), [
            ...$payment,
            'idempotency_key' => (string) Str::uuid(),
            'amount_sen' => 1501,
        ])->assertSessionHasErrors('amount_sen');
        $this->post(route('panel-claims.payment', $claim), [
            ...$payment,
            'idempotency_key' => (string) Str::uuid(),
            'amount_sen' => 1500,
        ])->assertRedirect();
        $this->assertSame('paid', $claim->refresh()->status);
        $this->assertSame(2500, (int) DB::table('panel_claim_payments')->where('panel_claim_id', $claim->id)->sum('amount_sen'));

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertSame($invoice->lock_version, $invoice->refresh()->lock_version);
        $this->assertSame(3000, $allocation->refresh()->amount_sen);
        $this->assertSame('approved', $allocation->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.submitted', 'subject_id' => $claim->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.decision_recorded', 'subject_id' => $claim->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.payment_received', 'subject_id' => $claim->id]);
        $this->assertSame([
            'prepared',
            'submitted',
            'queried',
            'submitted',
            'decision_partially_accepted',
        ], DB::table('panel_claim_events')->where('panel_claim_id', $claim->id)->orderBy('id')->pluck('event_type')->all());
    }

    public function test_only_panel_officers_can_manage_claims_and_another_branch_cannot_read_them(): void
    {
        [$panelOfficer, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();

        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'director', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->get(route('panel-claims.index'))->assertForbidden();
            $this->get(route('panel-claims.show', $claim))->assertForbidden();
            $this->post(route('panel-claims.submit', $claim), [])->assertForbidden();
        }

        $otherBranch = Branch::query()
            ->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $differentBranchOfficer = $this->actor('panel_officer', $otherBranch);
        $this->selectBranch($differentBranchOfficer, $otherBranch);
        $this->get(route('panel-claims.show', $claim))->assertNotFound();
        $this->assertContains('panel.claims.manage.branch', BillingPermissions::roles()['panel_officer']);
        $this->assertNotContains('panel.claims.manage.branch', BillingPermissions::roles()['director']);
        $this->assertSame($invoice->lock_version, $invoice->refresh()->lock_version);
    }

    public function test_payment_insights_separate_panel_claim_remittances_from_patient_receipts(): void
    {
        [$panelOfficer, $visit, , $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $this->post(route('panel-claims.submit', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $claim->lock_version,
        ])->assertRedirect();
        $claim->refresh();
        $this->post(route('panel-claims.decision', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $claim->lock_version,
            'decision' => 'accepted',
            'accepted_amount_sen' => 3000,
            'note' => 'Synthetic acceptance for Insights test',
        ])->assertRedirect();
        $claim->refresh();
        $date = now()->setTimezone($this->branch->timezone)->toDateString();
        $this->post(route('panel-claims.payment', $claim), [
            'expected_branch_id' => $visit->branch_id,
            'idempotency_key' => (string) Str::uuid(),
            'amount_sen' => 1000,
            'received_date' => $date,
        ])->assertRedirect();

        $this->get(route('insights.report', [
            'section' => 'payments',
            'branch' => $this->branch->code,
            'from' => $date,
            'to' => $date,
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.report.summary.panelClaimPacksSen', 3000)
            ->where('data.report.summary.panelClaimsAcceptedSen', 3000)
            ->where('data.report.summary.panelRemittancesReceivedSen', 1000)
            ->where('data.report.summary.panelClaimsOutstandingSen', 2000)
            ->has('data.report.panelClaims', 1)
            ->where('data.report.panelClaims.0.claimAmountSen', 3000)
            ->where('data.report.panelClaims.0.acceptedClaimSen', 3000)
            ->where('data.report.panelClaims.0.remittedClaimSen', 1000)
            ->where('data.report.panelClaims.0.outstandingClaimSen', 2000));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_pending_and_overdue_list_unclaimed_invoices_by_each_panels_due_days(): void
    {
        [$panelOfficer, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);

        $this->get(route('panel-claims.index', ['tab' => 'pending']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.pending', 1)->where('work.tabs.overdue', 0)->where('work.tabs.all', 0)
            ->where('work.summary.pending.count', 1)->where('work.summary.pending.amountSen', 3000)
            ->where('work.summary.overdue.count', 0)
            ->has('work.eligible.data', 1)
            ->where('work.eligible.data.0.invoiceNumber', $invoice->invoice_number)
            ->where('work.eligible.data.0.remarkNote', 'Synthetic Panel responsibility for tests')
            ->where('work.eligible.data.0.overdue', false)
            ->where('work.eligible.data.0.visitType', $visit->visit_type)
            ->where('work.eligible.data.0.invoiceUrl', route('billing.show', ['visit' => $visit->visit_number]))
            ->where('work.eligible.data.0.doctorName', $visit->assignedDoctor?->name));

        // A Panel with a shorter due period makes the same unclaimed invoice overdue.
        DB::table('visits')->where('id', $visit->id)->update(['registered_at' => now()->subDays(3)]);
        DB::table('panels')->where('id', $allocation->panel_id)->update(['claim_due_days' => 2]);
        $this->get(route('panel-claims.index', ['tab' => 'overdue']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.overdue', 1)->where('work.tabs.pending', 0)
            ->where('work.summary.overdue.count', 1)->where('work.summary.overdue.amountSen', 3000)
            ->has('work.eligible.data', 1)->where('work.eligible.data.0.overdue', true));
        DB::table('panels')->where('id', $allocation->panel_id)->update(['claim_due_days' => 30]);
        $this->get(route('panel-claims.index', ['tab' => 'overdue']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.overdue', 0)->where('work.tabs.pending', 1)->has('work.eligible.data', 0));

        // A scheduled rule (daily, by a time, same day) is overdue once that time has passed on a past visit day.
        DB::table('panels')->where('id', $allocation->panel_id)->update(['claim_due_rule' => 'daily', 'claim_due_time' => '23:59', 'claim_due_offset' => 0]);
        $this->get(route('panel-claims.index', ['tab' => 'overdue']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.overdue', 1)->where('work.tabs.pending', 0)->has('work.eligible.data', 1));
        // Monthly, day 1 of next month: a visit three days ago is still pending.
        DB::table('panels')->where('id', $allocation->panel_id)->update(['claim_due_rule' => 'monthly', 'claim_due_time' => '23:59', 'claim_due_offset' => 28]);
        DB::table('visits')->where('id', $visit->id)->update(['registered_at' => now()]);
        $this->get(route('panel-claims.index', ['tab' => 'pending']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.pending', 1)->where('work.tabs.overdue', 0));
        DB::table('panels')->where('id', $allocation->panel_id)->update(['claim_due_rule' => 'days_after_visit', 'claim_due_time' => null, 'claim_due_offset' => null]);

        foreach ([
            [['invoice_no' => $invoice->invoice_number, 'tab' => 'pending'], 1],
            [['invoice_no' => 'NOPE-1', 'tab' => 'pending'], 0],
            [['panel' => $allocation->panel_name_snapshot, 'tab' => 'pending'], 1],
            [['panel' => 'No Such Panel', 'tab' => 'pending'], 0],
            [['visit_type' => $visit->visit_type, 'tab' => 'pending'], 1],
            [['visit_type' => $visit->visit_type === 'otc' ? 'consultation' : 'otc', 'tab' => 'pending'], 0],
            [['visit_from' => now()->addDay()->toDateString(), 'tab' => 'pending'], 0],
            [['due_from' => now()->toDateString(), 'tab' => 'pending'], 1],
            [['due_to' => now()->subDays(2)->toDateString(), 'tab' => 'pending'], 0],
            [['amount_min' => '30.01', 'tab' => 'pending'], 0],
        ] as [$query, $expected]) {
            $this->get(route('panel-claims.index', $query))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('work.eligible.data', $expected));
        }
        $this->get(route('panel-claims.index', ['tab' => 'bogus']))->assertSessionHasErrors('tab');
        $this->get(route('panel-claims.index', ['visit_type' => 'bogus']))->assertSessionHasErrors('visit_type');
    }

    public function test_diagnosis_and_treatment_filters_need_the_clinical_filters_permission_and_return_no_clinical_text(): void
    {
        [$panelOfficer, $visit, , $allocation] = $this->approvedPanelFixture();
        $encounter = ClinicalEncounter::query()->where('visit_id', $visit->id)->firstOrFail();
        EncounterDiagnosis::factory()->create(['clinical_encounter_id' => $encounter->id, 'diagnosis_text' => 'Synthetic acute rhinitis', 'diagnosis_code' => 'J30.9', 'code_system' => 'ICD-10']);
        $plan = TreatmentPlan::factory()->create(['clinical_encounter_id' => $encounter->id]);
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $visit->organisation_id, 'display_name' => 'Synthetic cetirizine tablet']);
        TreatmentPlanMedicineOrder::factory()->create(['treatment_plan_id' => $plan->id, 'medicine_catalogue_item_id' => $medicine->id]);
        $this->selectBranch($panelOfficer, $this->branch);
        $this->assertContains('panel.claims.clinical_filters.branch', BillingPermissions::roles()['panel_officer']);

        foreach ([
            [['diagnosis' => 'RHINITIS'], 1],
            [['diagnosis' => 'j30'], 1],
            [['diagnosis' => 'fracture'], 0],
            [['treatment' => 'Cetirizine'], 1],
            [['treatment' => 'insulin'], 0],
            [['diagnosis' => 'rhinitis', 'treatment' => 'insulin'], 0],
        ] as [$query, $expected]) {
            $this->get(route('panel-claims.index', ['tab' => 'pending', ...$query]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->has('work.eligible.data', $expected)->where('work.can.clinicalFilters', true));
        }
        $this->get(route('panel-claims.index', ['tab' => 'pending', 'diagnosis' => 'rhinitis']))->assertDontSee('Synthetic acute rhinitis')->assertDontSee('cetirizine');

        // Without the permission the filters are ignored, not applied.
        Role::query()->where('name', 'panel_officer')->firstOrFail()->revokePermissionTo('panel.claims.clinical_filters.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->get(route('panel-claims.index', ['tab' => 'pending', 'diagnosis' => 'fracture']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('work.eligible.data', 1)->where('work.can.clinicalFilters', false)->where('work.filters.diagnosis', ''));
        $this->assertSame($allocation->panel_name_snapshot, $allocation->refresh()->panel_name_snapshot);
    }

    public function test_claim_page_shows_summary_identity_for_the_panel_officer_and_hides_it_without_the_permission(): void
    {
        [$panelOfficer, $visit, , $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        DB::table('panels')->where('id', $allocation->panel_id)->update(['claim_due_days' => 7, 'verification_url' => 'https://claims.example.test/verify']);
        DB::table('patients')->where('id', $visit->patient_id)->update(['mobile_phone' => '0123456789']);
        DB::table('patient_identifiers')->insert(['organisation_id' => $visit->organisation_id, 'patient_id' => $visit->patient_id, 'identifier_type' => 'nric', 'issuing_country_code' => 'MY', 'normalized_value' => '900101015555', 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();

        $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('claim.claimNo', (string) $claim->id)
            ->where('claim.dueRule', 'Submit 7 days after the visit')
            ->where('claim.verificationUrl', 'https://claims.example.test/verify')
            ->where('claim.identityVisible', true)
            ->where('claim.patientPhone', '0123456789')
            ->where('claim.patientIdentifier', '900101015555')
            ->where('claim.patientIdentifierType', 'NRIC')
            ->where('claim.amountDueSen', 3000)
            ->where('claim.invoiceUrl', route('billing.show', ['visit' => $visit->visit_number]))
            ->where('claim.receivedOn', null)
            ->where('claim.updatedBy', $panelOfficer->name));
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.identity_viewed', 'subject_id' => $claim->id]);
        $this->assertStringNotContainsString('900101015555', (string) DB::table('audit_logs')->where('event', 'panel_claim.identity_viewed')->value('metadata'));

        Role::query()->where('name', 'panel_officer')->firstOrFail()->revokePermissionTo('panel.claims.patient_identity.view.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('claim.identityVisible', false)->where('claim.patientPhone', null)->where('claim.patientIdentifier', null));
    }

    public function test_notes_remarks_and_photo_documents_are_branch_scoped_audited_and_validated(): void
    {
        Storage::fake('local');
        [$panelOfficer, $visit, , $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $branchId = $visit->branch_id;

        $this->post(route('panel-claims.note', $claim), ['expected_branch_id' => $branchId, 'note' => 'Called the Panel desk'])->assertRedirect();
        $this->assertDatabaseHas('panel_claim_events', ['panel_claim_id' => $claim->id, 'event_type' => 'note', 'note' => 'Called the Panel desk']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.note_added', 'subject_id' => $claim->id]);
        $this->post(route('panel-claims.note', $claim), ['expected_branch_id' => $branchId, 'note' => ''])->assertSessionHasErrors('note');

        $this->patch(route('panel-claims.remarks', $claim), ['expected_branch_id' => $branchId, 'lock_version' => $claim->lock_version, 'remarks' => 'Panel payment by cheque'])->assertRedirect();
        $this->assertSame('Panel payment by cheque', $claim->refresh()->remarks);
        $this->patch(route('panel-claims.remarks', $claim), ['expected_branch_id' => $branchId, 'lock_version' => 1, 'remarks' => 'stale'])->assertSessionHasErrors('claim');
        $this->assertSame('Panel payment by cheque', $claim->refresh()->remarks);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->post(route('panel-claims.documents.store', $claim), ['expected_branch_id' => $branchId, 'file' => UploadedFile::fake()->createWithContent('guarantee letter.png', $png)])->assertRedirect();
        $document = DB::table('panel_claim_documents')->where('panel_claim_id', $claim->id)->first();
        $this->assertNotNull($document);
        $this->assertSame('image/png', $document->mime_type);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.document_added', 'subject_id' => $claim->id]);

        $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page->has('claim.documents', 1)->where('claim.documents.0.name', 'guarantee letter.png'));
        $this->get(route('panel-claims.documents.show', [$claim, $document->public_id]))->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->post(route('panel-claims.documents.store', $claim), ['expected_branch_id' => $branchId, 'file' => UploadedFile::fake()->createWithContent('notes.png', 'not an image')])->assertSessionHasErrors('file');
        $this->post(route('panel-claims.documents.store', $claim), ['expected_branch_id' => $branchId, 'file' => UploadedFile::fake()->createWithContent('big.png', $png.str_repeat('0', 5 * 1024 * 1024))])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('panel_claim_documents', 1);

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->get(route('panel-claims.documents.show', [$claim, $document->public_id]))->assertForbidden();
        $this->post(route('panel-claims.note', $claim), ['expected_branch_id' => $branchId, 'note' => 'x'])->assertForbidden();
    }

    public function test_created_claims_leave_pending_and_show_in_submitted_then_follow_decisions_and_remittances(): void
    {
        [$panelOfficer, $visit, , $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();

        $this->get(route('panel-claims.index', ['tab' => 'submitted']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.pending', 0)->where('work.tabs.all', 1)->where('work.tabs.submitted', 1)
            ->where('work.claims.data.0.claimNo', (string) $claim->id)
            ->where('work.claims.data.0.status', 'Prepared')
            ->where('work.claims.data.0.updatedBy', $panelOfficer->name)
            ->has('work.eligible.data', 0));

        $this->get(route('panel-claims.index', ['tab' => 'submitted', 'name' => 'zzz-no-match']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('work.claims.data', 0));
        $this->get(route('panel-claims.index', ['claim_no' => (string) $claim->id, 'amount_min' => '30', 'amount_max' => '30.00']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('work.claims.data', 1));
        $this->get(route('panel-claims.index', ['amount_min' => '30.01']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('work.claims.data', 0));
        $this->get(route('panel-claims.index', ['range' => 'yesterday']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('work.claims.data', 0)->where('work.filters.range', 'yesterday'));

        $claim->forceFill(['status' => 'accepted', 'accepted_amount_sen' => 3000])->save();
        DB::table('panel_claim_payments')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $claim->organisation_id, 'branch_id' => $claim->branch_id,
            'panel_claim_id' => $claim->id, 'amount_sen' => 1000, 'currency' => 'MYR', 'reference' => 'SYNTH-REMIT-1', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'received_at' => now(), 'recorded_by_user_id' => $panelOfficer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get(route('panel-claims.index', ['tab' => 'approved']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('work.claims.data.0.remarks', 'SYNTH-REMIT-1'));
        $this->get(route('panel-claims.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.summary.approved.amountSen', 3000)
            ->where('work.summary.outstanding.count', 1)->where('work.summary.outstanding.amountSen', 2000)
            ->where('work.tabs.approved', 1)->where('work.tabs.submitted', 0));
    }

    /** @return array<string, mixed> */
    private function creationPayload(CoverageAllocation $allocation, Invoice $invoice): array
    {
        return [
            'expected_branch_id' => $allocation->branch_id,
            'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
            'remarks' => 'Synthetic claim remarks',
        ];
    }

    public function test_creation_retry_returns_same_claim_and_changed_payload_or_another_key_is_refused(): void
    {
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $payload = $this->creationPayload($allocation, $invoice);
        $this->post(route('panel-claims.store', $allocation), $payload)->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $this->post(route('panel-claims.store', $allocation), $payload)->assertRedirect(route('panel-claims.show', $claim));
        $this->assertDatabaseCount('panel_claims', 1);
        $this->assertDatabaseCount('panel_claim_events', 2);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());
        $this->assertSame('Synthetic claim remarks', $claim->remarks);
        $audit = json_decode(DB::table('audit_logs')->where('event', 'panel_claim.created')->sole()->metadata, true);
        $this->assertSame(['claim_amount_sen' => 3000, 'submitted_date' => $payload['submitted_date']], $audit);
        $this->post(route('panel-claims.store', $allocation), array_replace($payload, ['remarks' => 'Changed details']))
            ->assertRedirect(route('panel-claims.create', $allocation))->assertSessionHasErrors('claim');
        $this->post(route('panel-claims.store', $allocation), array_replace($payload, ['idempotency_key' => (string) Str::uuid()]))
            ->assertSessionHasErrors('claim');
        $this->get(route('panel-claims.create', $allocation))->assertRedirect(route('panel-claims.show', $claim));
        $this->assertDatabaseCount('panel_claim_events', 2);
    }

    public function test_creation_dates_use_branch_timezone_and_backdated_events_are_ordered_with_real_creation_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 17:30:00', 'UTC'));
        [, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        DB::table('visits')->where('id', $visit->id)->update(['registered_at' => '2026-10-10 17:00:00']);
        $payload = array_replace($this->creationPayload($allocation, $invoice), ['submitted_date' => '2026-10-12']);
        $this->post(route('panel-claims.store', $allocation), $payload)->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $this->assertSame('2026-10-13 17:30:00', $claim->created_at->format('Y-m-d H:i:s'));
        $events = $claim->events()->get();
        $this->assertSame(['prepared', 'submitted'], $events->pluck('event_type')->all());
        foreach ($events as $event) {
            $this->assertSame('2026-10-11 16:00:00', $event->occurred_at->format('Y-m-d H:i:s'));
            $this->assertSame('2026-10-13 17:30:00', $event->created_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_today_submission_uses_current_time_even_when_utc_date_is_yesterday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 17:30:00', 'UTC'));
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $payload = $this->creationPayload($allocation, $invoice);
        $this->assertSame('2026-10-14', $payload['submitted_date']);
        $this->post(route('panel-claims.store', $allocation), $payload)->assertRedirect();
        foreach (PanelClaim::query()->sole()->events()->get() as $event) {
            $this->assertSame('2026-10-13 17:30:00', $event->occurred_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_future_and_pre_visit_dates_are_field_errors_and_roll_back_all_creation_evidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 17:30:00', 'UTC'));
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $payload = $this->creationPayload($allocation, $invoice);
        foreach (['2026-10-15', '2026-10-13', '2026-02-30', 'tomorrow'] as $date) {
            $this->post(route('panel-claims.store', $allocation), array_replace($payload, ['submitted_date' => $date]))
                ->assertRedirect(route('panel-claims.create', $allocation))->assertSessionHasErrors('submitted_date');
            $this->assertDatabaseCount('panel_claims', 0);
            $this->assertDatabaseCount('panel_claim_lines', 0);
            $this->assertDatabaseCount('panel_claim_events', 0);
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());
        }
    }

    public function test_creation_rejects_stale_versions_and_no_longer_claimable_source(): void
    {
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $payload = $this->creationPayload($allocation, $invoice);
        foreach (['invoice_lock_version', 'allocation_lock_version'] as $key) {
            $this->post(route('panel-claims.store', $allocation), array_replace($payload, [$key => 999]))->assertSessionHasErrors('claim');
        }
        $invoice->forceFill(['correction_hold' => true])->save();
        $this->post(route('panel-claims.store', $allocation), $payload)->assertSessionHasErrors('claim');
        $this->assertDatabaseCount('panel_claims', 0);
        $this->assertDatabaseCount('panel_claim_lines', 0);
    }

    public function test_create_get_and_post_require_panel_permission_and_current_branch_scope(): void
    {
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $payload = $this->creationPayload($allocation, $invoice);
        foreach (['director', 'technical_admin', 'ca', 'finance_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->get(route('panel-claims.create', $allocation))->assertForbidden();
            $this->post(route('panel-claims.store', $allocation), $payload)->assertForbidden();
        }
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $officer = $this->actor('panel_officer', $otherBranch);
        $this->selectBranch($officer, $otherBranch);
        $this->get(route('panel-claims.create', $allocation))->assertNotFound();
        $this->post(route('panel-claims.store', $allocation), array_replace($payload, ['expected_branch_id' => $otherBranch->id]))->assertNotFound();
        $this->assertDatabaseCount('panel_claims', 0);
    }

    public function test_all_photos_are_content_validated_before_creation_with_size_and_count_limits(): void
    {
        Storage::fake('local');
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $payload = $this->creationPayload($allocation, $invoice);
        $invalid = [
            [UploadedFile::fake()->image('valid.png'), UploadedFile::fake()->createWithContent('fake.jpg', 'not an image')],
            [UploadedFile::fake()->image('large.png')->size(5121)],
            array_map(fn () => UploadedFile::fake()->image('photo.jpg'), range(1, 11)),
        ];
        foreach ($invalid as $photos) {
            $this->post(route('panel-claims.store', $allocation), $payload + ['photos' => $photos])->assertSessionHasErrors();
            $this->assertDatabaseCount('panel_claims', 0);
            $this->assertDatabaseCount('panel_claim_documents', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_creation_photos_are_private_audited_and_never_reuploaded_on_retry(): void
    {
        Storage::fake('local');
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $payload = $this->creationPayload($allocation, $invoice) + ['photos' => [UploadedFile::fake()->image('synthetic.png'), UploadedFile::fake()->image('synthetic.jpg')]];
        $this->post(route('panel-claims.store', $allocation), $payload)->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $this->post(route('panel-claims.store', $allocation), $payload)->assertRedirect(route('panel-claims.show', $claim));
        $this->assertDatabaseCount('panel_claim_documents', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'panel_claim.document_added')->count());
        $this->assertSame(3, $claim->refresh()->lock_version);
    }

    public function test_failed_upload_redirects_to_created_claim_with_recovery_error_and_retry_does_not_duplicate(): void
    {
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $actualDisk = Storage::fake('local');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $writes = 0;
        $disk->shouldReceive('putFileAs')->twice()->andReturnUsing(function ($directory, $file, $name) use ($actualDisk, &$writes) {
            $path = $actualDisk->putFileAs($directory, $file, $name);

            return ++$writes === 1 ? $path : false;
        });
        $disk->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $actualDisk->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $payload = $this->creationPayload($allocation, $invoice) + ['photos' => [
            UploadedFile::fake()->image('synthetic-first.png'), UploadedFile::fake()->image('synthetic-second.jpg'),
        ]];
        $response = $this->post(route('panel-claims.store', $allocation), $payload)->assertSessionHasErrors('file');
        $claim = PanelClaim::query()->sole();
        $response->assertRedirect(route('panel-claims.show', $claim));
        $this->assertSame('submitted', $claim->status);
        $this->assertSame(2, $claim->lock_version);
        $this->assertDatabaseCount('panel_claim_documents', 1);
        $this->assertCount(1, $actualDisk->allFiles());
        $this->post(route('panel-claims.store', $allocation), $payload)->assertRedirect(route('panel-claims.show', $claim));
        $this->assertDatabaseCount('panel_claims', 1);
        $this->assertDatabaseCount('panel_claim_events', 2);
        $this->assertDatabaseCount('panel_claim_documents', 1);
        $this->assertCount(1, $actualDisk->allFiles());
    }

    public function test_document_transaction_retry_compensates_abandoned_files_and_keeps_one_document(): void
    {
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        // Laravel retries deadlocks only at the outermost transaction. Commit this disposable
        // fixture so the request exercises the runtime boundary; force a fresh schema next test.
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();
        RefreshDatabaseState::$migrated = false;
        $actualDisk = Storage::fake('local');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $writes = 0;
        $disk->shouldReceive('putFileAs')->twice()->andReturnUsing(function ($directory, $file, $name) use ($actualDisk, &$writes) {
            $path = $actualDisk->putFileAs($directory, $file, $name);
            if (++$writes === 1) {
                throw new \PDOException('deadlock detected', 40001);
            }

            return $path;
        });
        $disk->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $actualDisk->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $payload = $this->creationPayload($allocation, $invoice) + ['photos' => [UploadedFile::fake()->image('synthetic.png')]];
        $this->post(route('panel-claims.store', $allocation), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame(2, $claim->lock_version);
        $this->assertDatabaseCount('panel_claim_documents', 1);
        $this->assertCount(1, $actualDisk->allFiles());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.document_added')->count());
    }

    public function test_submitted_amount_edit_keeps_submitted_requires_reason_confirmation_and_current_version(): void
    {
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $this->post(route('panel-claims.store', $allocation), $this->creationPayload($allocation, $invoice))->assertRedirect();
        $claim = PanelClaim::query()->sole();
        $data = ['expected_branch_id' => $claim->branch_id, 'lock_version' => 1, 'amount_sen' => 6000];
        $this->patch(route('panel-claims.amount', $claim), $data)->assertSessionHasErrors('reason');
        $this->patch(route('panel-claims.amount', $claim), $data + ['reason' => 'Synthetic reason'])->assertSessionHasErrors('confirm_over_invoice');
        $this->patch(route('panel-claims.amount', $claim), $data + ['reason' => 'Synthetic reason', 'confirm_over_invoice' => true])->assertRedirect();
        $this->assertSame('submitted', $claim->refresh()->status);
        $this->assertSame(6000, $claim->claim_amount_sen);
        $this->assertNull($claim->accepted_amount_sen);
        $this->assertSame(2, $claim->lock_version);
        $this->assertDatabaseHas('panel_claim_events', ['event_type' => 'amount_adjusted', 'note' => 'Synthetic reason']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.amount_adjusted']);
        $this->patch(route('panel-claims.amount', $claim), $data + ['reason' => 'Synthetic reason', 'confirm_over_invoice' => true])->assertSessionHasErrors('claim');
        $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page->where('claim.canEditAmount', true)->where('claim.canSubmit', false));
        $this->assertSame(3000, $allocation->refresh()->amount_sen);
        $this->assertSame(4000, $invoice->refresh()->total_sen);
    }

    public function test_legacy_prepared_queried_rejected_edits_keep_existing_reset_to_prepared_behaviour(): void
    {
        [, $visit, , $allocation] = $this->approvedPanelFixture();
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();
        foreach (['prepared', 'queried', 'rejected'] as $index => $status) {
            $claim->forceFill(['status' => $status, 'accepted_amount_sen' => $status === 'rejected' ? 0 : null])->save();
            $this->patch(route('panel-claims.amount', $claim), [
                'expected_branch_id' => $claim->branch_id, 'lock_version' => $claim->lock_version,
                'amount_sen' => 3100 + $index, 'reason' => 'Synthetic legacy edit',
            ])->assertRedirect();
            $this->assertSame('prepared', $claim->refresh()->status);
            $this->assertNull($claim->accepted_amount_sen);
        }
    }

    public function test_accepted_partially_accepted_partially_paid_and_paid_claims_remain_not_editable(): void
    {
        [, , $invoice, $allocation] = $this->approvedPanelFixture();
        $this->post(route('panel-claims.store', $allocation), $this->creationPayload($allocation, $invoice))->assertRedirect();
        $claim = PanelClaim::query()->sole();
        foreach (['accepted', 'partially_accepted', 'partially_paid', 'paid'] as $status) {
            $claim->forceFill(['status' => $status, 'accepted_amount_sen' => $status === 'partially_accepted' ? 2000 : 3000])->save();
            $this->patch(route('panel-claims.amount', $claim), [
                'expected_branch_id' => $claim->branch_id, 'lock_version' => $claim->lock_version,
                'amount_sen' => 3500, 'reason' => 'Synthetic refused edit',
            ])->assertSessionHasErrors('claim');
            $this->assertSame($status, $claim->refresh()->status);
            $this->assertSame(3000, $claim->claim_amount_sen);
            $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page->where('claim.canEditAmount', false));
        }
    }

    /** @return array{mixed, Visit, Invoice, CoverageAllocation} */
    public function test_claim_page_offers_a_copy_ready_message_filled_from_the_template(): void
    {
        [$panelOfficer, $visit, , $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])->assertRedirect();
        $claim = PanelClaim::query()->sole();

        $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page->where('claim.message', null));

        DB::table('panel_claim_message_templates')->insert([
            'organisation_id' => $claim->organisation_id, 'kind' => 'single', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            'body' => "Dear {panel_name}\nClaim {claim_number} for {patient_name} ({patient_nric}), {staff_id}, {claimed_amount}, {submission_date}\n{claim_link} {clinic_name} {not_a_field}",
        ]);
        $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('claim.message', function ($message) use ($claim): bool {
                return str_contains($message, 'Dear '.$claim->panel_name_snapshot)
                    && str_contains($message, 'Claim '.$claim->id.' for '.$claim->patient_name_snapshot)
                    && str_contains($message, 'RM 30.00')
                    && str_contains($message, route('panel-claims.show', $claim))
                    && str_contains($message, $claim->branch_name_snapshot)
                    && str_contains($message, 'not yet submitted')
                    && str_contains($message, '{not_a_field}')
                    && ! str_contains($message, '{patient_nric}')
                    && ! str_contains($message, '(not available)');
            }));
    }

    private function approvedPanelFixture(): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $this->selectBranch($ca, $this->branch);
        $allocation = app(ResponsibilityService::class)->propose($ca, $visit, $invoice, 'panel', [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version,
            'amount_sen' => 3000,
            'reason' => 'Synthetic Panel responsibility for tests',
            'panel_id' => $panel->id,
            'member_reference' => 'SYNTHETIC-TEST-REF',
        ]);
        $panelOfficer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert([
            'organisation_id' => $visit->organisation_id,
            'branch_id' => $visit->branch_id,
            'user_id' => $panelOfficer->id,
            'capability' => 'panel',
            'limit_sen' => 4000,
        ]);
        $this->selectBranch($panelOfficer, $this->branch);
        app(ResponsibilityService::class)->approve($panelOfficer, $visit, $invoice, 'panel', $allocation->public_id, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version,
        ]);

        return [$panelOfficer, $visit, $invoice->refresh(), $allocation->refresh()];
    }
}
