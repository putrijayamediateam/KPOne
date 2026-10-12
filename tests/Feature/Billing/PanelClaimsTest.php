<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\BillingPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class PanelClaimsTest extends BillingTestCase
{
    public function test_panel_officer_prepares_a_minimized_claim_pack_from_an_approved_allocation(): void
    {
        [$panelOfficer, $visit, $invoice, $allocation] = $this->approvedPanelFixture();
        $this->selectBranch($panelOfficer, $this->branch);

        $this->get(route('panel-claims.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PanelClaims/Index')
            ->has('work.eligible.data', 1)
            ->where('work.eligible.data.0.invoiceNumber', $invoice->invoice_number)
            ->where('work.eligible.data.0.panelName', $allocation->panel_name_snapshot)
            ->has('work.claims.data', 0));

        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $visit->branch_id])
            ->assertRedirect();
        $claim = PanelClaim::query()->sole();

        $this->assertSame('prepared', $claim->status);
        $this->assertSame($allocation->amount_sen, $claim->claim_amount_sen);
        $this->assertSame($invoice->total_sen, $claim->invoice_total_sen);
        $this->assertSame($visit->visit_number, $claim->visit_number);
        $this->assertDatabaseCount('panel_claim_lines', 1);
        $this->assertDatabaseHas('panel_claim_events', ['panel_claim_id' => $claim->id, 'event_type' => 'prepared']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim.prepared', 'subject_id' => $claim->id]);

        $response = $this->get(route('panel-claims.show', $claim))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PanelClaims/Show')
            ->where('claim.patientName', $claim->patient_name_snapshot)
            ->where('claim.memberReference', $claim->member_reference)
            ->where('claim.invoiceNumber', $invoice->invoice_number)
            ->where('claim.adjustmentSen', 0)
            ->has('claim.lines', 1)
            ->has('claim.events', 1)
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

    /** @return array{mixed, Visit, Invoice, CoverageAllocation} */
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
