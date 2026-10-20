<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Shared\Services\ClinicInsightsService;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimLine;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\PanelClaimDirectoryService;
use App\Domain\Visit\Billing\Services\PanelClaimEligibility;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class InvoiceAdjustmentReportingTest extends BillingTestCase
{
    private function nextInvoice(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca);
        $invoice = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $invoice = app(BillingBuilderService::class)->finalize($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);

        return [$doctor, $ca, $visit, $invoice];
    }

    private function adjust($visit, $invoice, string $direction = 'deduct', int $amount = 1000): array
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $record = app(InvoiceAdjustmentService::class)->add($actor, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => $direction, 'input_type' => 'fixed', 'input_value' => $amount, 'confirm_above_limit' => true, 'reason' => 'SYNTHETIC-PRIVATE-ADJUSTMENT', 'idempotency_key' => (string) Str::uuid()]);

        return [$actor, $record];
    }

    private function sales($actor, string $from, string $to, string $branch = 'all', ?int $doctor = null): array
    {
        return app(ClinicInsightsReportService::class)->report($actor, 'sales', $branch, $from, $to, $doctor)['report'];
    }

    public function test_net_and_gross_reconcile_in_original_date_cohort_including_reversal_and_unadjusted_invoice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 23:55', 'Asia/Kuala_Lumpur'));
        $first = $this->finalizedFixture(10000);
        $second = $this->nextInvoice();
        $third = $this->nextInvoice();
        $fourth = $this->nextInvoice();
        $this->travelTo(CarbonImmutable::parse('2026-10-16 00:05', 'Asia/Kuala_Lumpur'));
        $this->adjust($first[2], $first[3]);
        [, $reversed] = $this->adjust($second[2], $second[3], 'add', 2000);
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(InvoiceAdjustmentService::class)->reverse($director, $second[2], $second[3]->refresh(), $reversed->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $second[3]->lock_version, 'reason' => 'SYNTHETIC-PRIVATE-REVERSAL', 'idempotency_key' => (string) Str::uuid()]);
        $this->adjust($fourth[2], $fourth[3], 'add', 1500);
        $actor = $this->actor('marketing');
        $report = $this->sales($actor, '2026-10-15', '2026-10-15');
        $this->assertSame(['grossSen' => 40000, 'discountsSen' => 1000, 'surchargesSen' => 1500, 'netSen' => 40500], $report['summary']['reconciliation']);
        $this->assertSame(40500, $report['summary']['totalSen']);
        $this->assertSame([['date' => '2026-10-15', 'salesSen' => 40500, 'patients' => 4]], $report['dailyTrend']);
        $this->assertSame(40000, array_sum(array_column($report['services'], 'salesSen')));
        $this->assertSame(40500, array_sum(array_column($report['providers'], 'salesSen')));
        $this->assertSame(9000, $this->sales($actor, '2026-10-15', '2026-10-15', 'all', $first[0]->id)['summary']['totalSen']);
        $this->assertSame(0, $this->sales($actor, '2026-10-16', '2026-10-16')['summary']['totalSen']);
        $this->assertSame(40500, $this->sales($actor, '2026-10-16', '2026-10-16')['summary']['previousTotalSen']);
        $today = app(ClinicInsightsService::class)->today($actor);
        $this->assertSame(0, $today['sales']['totalSen']);
        $this->assertSame(40500, $today['sales']['previousTotalSen']);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 23:59', 'Asia/Kuala_Lumpur'));
        $today = app(ClinicInsightsService::class)->today($actor);
        $this->assertSame($report['summary']['reconciliation'], $today['sales']['reconciliation']);
        $this->assertSame(40500, array_sum(array_column($today['salesTrend'], 'salesSen')));
        $this->assertSame(40000, array_sum(array_column($today['rankings']['services'], 'salesSen')));
        $this->assertSame(0, $this->sales($actor, '2026-10-15', '2026-10-15', 'PUCHONG')['summary']['totalSen']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE', json_encode([$report, $today]));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_receipts_remain_actual_and_patient_outstanding_is_net(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Kuala_Lumpur'));
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $this->adjust($visit, $invoice);
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $this->selectBranch($ca);
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 2000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $report = app(ClinicInsightsReportService::class)->report($ca, 'payments', 'all', '2026-10-16', '2026-10-16', null)['report'];
        $this->assertSame(9000, $report['summary']['selfPaySalesSen']);
        $this->assertSame(2000, $report['summary']['receivedSen']);
        $this->assertSame(7000, $report['summary']['outstandingSen']);
        $this->assertSame(1, $report['summary']['outstandingInvoices']);
        $this->get(route('billing.receipt', [$visit, $invoice, $payment]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('receipt.amountSen', 2000));
    }

    private function approvedAdjustedClaim(string $direction = 'deduct', bool $legacy = false): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $this->adjust($visit, $invoice, $direction, 2000);
        $amount = $direction === 'add' ? 12000 : 8000;
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $this->selectBranch($ca);
        $allocation = app(ResponsibilityService::class)->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'panel_id' => $panel->id, 'reason' => 'Synthetic approved coverage']);
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => $amount]);
        app(ResponsibilityService::class)->approve($officer, $visit, $invoice->refresh(), 'panel', $allocation->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $allocation->lock_version]);
        $this->assertTrue(app(PanelClaimEligibility::class)->query($visit->organisation_id, $visit->branch_id)->where('c.id', $allocation->id)->exists());
        if ($legacy) {
            // Synthetic fixture for a pre-INV-05b claim: its saved total was the original invoice value.
            $claim = new PanelClaim;
            $claim->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'coverage_allocation_id' => $allocation->id, 'invoice_id' => $invoice->id, 'patient_id' => $visit->patient_id, 'panel_id' => $panel->id, 'invoice_number' => $invoice->invoice_number, 'visit_number' => $visit->visit_number, 'patient_name_snapshot' => 'Synthetic legacy patient', 'panel_name_snapshot' => $panel->name, 'branch_name_snapshot' => 'Synthetic branch', 'visit_date' => $visit->registered_at->toDateString(), 'invoice_total_sen' => $invoice->total_sen, 'claim_amount_sen' => $amount, 'status' => 'prepared', 'lock_version' => 1, 'created_by_user_id' => $officer->id])->save();
            foreach (InvoiceLine::query()->where('invoice_id', $invoice->id)->get() as $index => $line) {
                $copy = new PanelClaimLine;
                $copy->forceFill(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'panel_claim_id' => $claim->id, 'line_number' => $index + 1, 'line_type' => $line->line_type, 'display_name' => $line->display_name, 'code_snapshot' => $line->code_snapshot, 'unit_snapshot' => $line->unit_snapshot, 'quantity' => $line->quantity, 'unit_price_sen' => $line->unit_price_sen, 'line_total_sen' => $line->line_total_sen])->save();
            }
        } else {
            $claim = app(PanelClaimService::class)->prepare($officer, $allocation, ['expected_branch_id' => $visit->branch_id]);
        }

        return [$officer, $visit, $invoice, $claim];
    }

    public function test_new_claim_snapshot_has_gross_lines_invoice_discount_and_net_separate_from_claim_only_adjustment(): void
    {
        [$officer, $visit, $invoice, $claim] = $this->approvedAdjustedClaim();
        $this->assertSame(8000, $claim->invoice_total_sen);
        $this->assertSame(10000, (int) $claim->lines()->sum('line_total_sen'));
        $before = [$claim->invoice_total_sen, $claim->lines->toArray(), $invoice->refresh()->getAttributes()];
        app(PanelClaimService::class)->adjustAmount($officer, $claim, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'amount_sen' => 8500, 'confirm_over_invoice' => true, 'reason' => 'Synthetic claim-only change']);
        $data = app(PanelClaimDirectoryService::class)->show($officer, $claim)['claim'];
        $this->assertSame(10000, $data['invoiceSubtotalSen']);
        $this->assertSame(-2000, $data['invoiceAdjustmentSen']);
        $this->assertSame(8000, $data['invoiceTotalSen']);
        $this->assertSame(500, $data['claimOnlyAdjustmentSen']);
        $this->assertCount(1, $data['lines']);
        $this->assertSame($before, [$claim->refresh()->invoice_total_sen, $claim->lines()->get()->toArray(), $invoice->refresh()->getAttributes()]);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-ADJUSTMENT', json_encode($data));
    }

    public function test_partial_panel_claim_does_not_label_patient_share_as_a_claim_only_adjustment(): void
    {
        [$officer, $visit, , $claim] = $this->approvedAdjustedClaim();
        app(PanelClaimService::class)->adjustAmount($officer, $claim, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'amount_sen' => 6000, 'reason' => 'Synthetic lower claim']);
        $data = app(PanelClaimDirectoryService::class)->show($officer, $claim)['claim'];
        $this->assertSame(6000, $data['claimAmountSen']);
        $this->assertSame(8000, $data['invoiceTotalSen']);
        $this->assertSame(0, $data['claimOnlyAdjustmentSen']);
        $this->assertSame(0, $data['adjustmentSen']);
        $this->assertSame(-2000, $data['invoiceAdjustmentSen']);
    }

    public function test_legacy_claim_snapshot_is_not_reconstructed_from_live_adjusted_invoice(): void
    {
        [$officer, , $invoice, $claim] = $this->approvedAdjustedClaim('deduct', true);
        $before = [$claim->refresh()->getAttributes(), $claim->lines()->get()->toArray()];
        $data = app(PanelClaimDirectoryService::class)->show($officer, $claim)['claim'];
        $this->assertSame(10000, $data['invoiceSubtotalSen']);
        $this->assertSame(10000, $data['invoiceTotalSen']);
        $this->assertSame(0, $data['invoiceAdjustmentSen']);
        $this->assertSame(8000, app(FinancialLedger::class)->state($invoice)['total']);
        $this->assertSame($before, [$claim->refresh()->getAttributes(), $claim->lines()->get()->toArray()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_surcharge_claim_eligibility_uses_adjusted_payable_above_original_total(): void
    {
        [$officer, , , $claim] = $this->approvedAdjustedClaim('add');
        $this->assertSame(12000, $claim->invoice_total_sen);
        $this->assertSame(2000, app(PanelClaimDirectoryService::class)->show($officer, $claim)['claim']['invoiceAdjustmentSen']);
    }

    public function test_adjusted_claim_remittance_remains_actual_received_money(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-16 10:00', 'Asia/Kuala_Lumpur'));
        [$officer, $visit, $invoice, $claim] = $this->approvedAdjustedClaim();
        $service = app(PanelClaimService::class);
        $service->submit($officer, $claim, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version]);
        $service->recordDecision($officer, $claim->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'decision' => 'accepted', 'accepted_amount_sen' => 8000, 'note' => 'Synthetic decision']);
        $service->recordPayment($officer, $claim->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'amount_sen' => 3000, 'received_date' => '2026-10-16', 'idempotency_key' => (string) Str::uuid()]);
        $report = app(ClinicInsightsReportService::class)->report($officer, 'payments', 'all', '2026-10-16', '2026-10-16', null)['report'];
        $this->assertSame(3000, $report['summary']['panelRemittancesReceivedSen']);
        $this->assertSame(0, $report['summary']['receivedSen']);
        $this->assertSame(10000, $invoice->refresh()->total_sen);
        $this->assertSame(8000, $claim->refresh()->invoice_total_sen);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_financial_views_and_print_keep_role_and_branch_isolation_and_private_reason_hidden(): void
    {
        [$officer, $visit, $invoice, $claim] = $this->approvedAdjustedClaim();
        $this->get(route('panel-claims.show', $claim))->assertOk()->assertDontSee('SYNTHETIC-PRIVATE-ADJUSTMENT');
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.invoice.adjustment.subtotalSen', 10000)->where('billing.invoice.adjustment.adjustmentSen', -2000)->where('billing.invoice.state.total', 8000))->assertDontSee('SYNTHETIC-PRIVATE-ADJUSTMENT');
        foreach (['administrator', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('billing.print', [$visit, $invoice]))->assertForbidden();
            $this->get(route('panel-claims.show', $claim))->assertForbidden();
            $this->get(route('insights.today'))->assertOk()->assertDontSee($visit->patient->full_name)->assertDontSee('SYNTHETIC-PRIVATE-ADJUSTMENT');
        }
        $other = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '!=', $visit->branch_id)->firstOrFail();
        $outsider = $this->actor('panel_officer', $other);
        $this->selectBranch($outsider, $other);
        $this->get(route('panel-claims.show', $claim))->assertNotFound();
        $outsider = $this->actor('ca', $other);
        $this->selectBranch($outsider, $other);
        $this->get(route('billing.print', [$visit, $invoice]))->assertNotFound();
    }
}
