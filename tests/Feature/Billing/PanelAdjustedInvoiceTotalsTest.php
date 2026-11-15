<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PNL-15: two Panel screens read the invoice total before any discount or surcharge. The Create claim page
 * must compare the Panel amount with the payable total, as creation itself does, and the Panel invoices
 * report and its CSV must show the payable total, as the invoice and the claim do.
 */
class PanelAdjustedInvoiceTotalsTest extends BillingTestCase
{
    public function test_create_claim_page_opens_for_a_panel_share_above_the_original_total_after_a_surcharge(): void
    {
        [$officer, $allocation] = $this->adjustedPanelInvoice('add', 12000);
        $this->selectBranch($officer, $this->branch);

        $this->get(route('panel-claims.create', $allocation))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('PanelClaims/Create')
                ->where('allocation.amountSen', 12000)
                ->where('allocation.invoiceTotalSen', 12000));
    }

    /** @return array<string, array{string, int, string}> */
    public static function adjustments(): array
    {
        return [
            'discount' => ['deduct', 8000, ',80.00,50.00,'],
            'surcharge' => ['add', 12000, ',120.00,50.00,'],
        ];
    }

    #[DataProvider('adjustments')]
    public function test_panel_invoices_report_and_csv_show_the_total_after_a_discount_or_surcharge(string $direction, int $payable, string $csvAmounts): void
    {
        [$officer, $allocation, $invoice] = $this->adjustedPanelInvoice($direction, 5000);
        $this->selectBranch($officer, $this->branch);
        $params = ['panel' => $allocation->panel_name_snapshot];

        $this->get(route('panel-invoices.index', $params))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('total', 1)
            ->where('rows.0.invoiceNumber', $invoice->invoice_number)
            ->where('rows.0.invoiceTotalSen', $payable)
            ->where('rows.0.panelAmountSen', 5000));

        $csv = $this->get(route('panel-invoices.download', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString($invoice->invoice_number, $csv);
        $this->assertStringContainsString($csvAmounts, $csv, 'Invoice total then Panel amount.');
        $this->assertStringNotContainsString(',100.00,', $csv, 'The original total is not exported.');
        $this->assertSame(10000, $invoice->refresh()->total_sen, 'The invoice row itself is unchanged.');
    }

    public function test_a_reversed_adjustment_leaves_the_original_total_on_both_screens(): void
    {
        [$officer, $allocation, $invoice] = $this->adjustedPanelInvoice('deduct', 5000, reverse: true);
        $this->selectBranch($officer, $this->branch);

        $this->get(route('panel-claims.create', $allocation))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('allocation.invoiceTotalSen', 10000));
        $this->get(route('panel-invoices.index', ['panel' => $allocation->panel_name_snapshot]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.invoiceTotalSen', 10000)->where('rows.0.invoiceNumber', $invoice->invoice_number));
    }

    /** @return array{0: User, 1: CoverageAllocation, 2: Invoice} */
    private function adjustedPanelInvoice(string $direction, int $share, bool $reverse = false): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(10000);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $adjustment = app(InvoiceAdjustmentService::class)->add($finance, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => $direction, 'input_type' => 'fixed',
            'input_value' => 2000, 'confirm_above_limit' => true, 'reason' => 'SYNTHETIC-PRIVATE-ADJUSTMENT', 'idempotency_key' => (string) Str::uuid()]);
        if ($reverse) {
            $director = $this->actor('director');
            $this->selectBranch($director);
            app(InvoiceAdjustmentService::class)->reverse($director, $visit, $invoice->refresh(), $adjustment->public_id, ['expected_branch_id' => $visit->branch_id,
                'lock_version' => $invoice->lock_version, 'reason' => 'SYNTHETIC-PRIVATE-REVERSAL', 'idempotency_key' => (string) Str::uuid()]);
        }
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id, 'name' => 'Synthetic Adjusted Panel '.$direction]);
        $this->selectBranch($ca);
        $allocation = app(ResponsibilityService::class)->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version, 'amount_sen' => $share, 'panel_id' => $panel->id, 'reason' => 'Synthetic approved coverage',
            'member_reference' => 'SYNTHETIC-TEST-REF']);
        $this->assertInstanceOf(CoverageAllocation::class, $allocation);
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        DB::table('billing_approval_limits')->updateOrInsert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id,
            'user_id' => $officer->id, 'capability' => 'panel'], ['limit_sen' => $share]);
        app(ResponsibilityService::class)->approve($officer, $visit, $invoice->refresh(), 'panel', $allocation->public_id, ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $allocation->lock_version]);

        return [$officer, $allocation->refresh(), $invoice->refresh()];
    }
}
