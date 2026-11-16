<?php

namespace Tests\Feature\Reports;

use App\Domain\Agents\Tools\MoneyDaySummaryTool;
use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\PanelClaimAdjustmentService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestCase;

/**
 * PCA-3 (design note v3.2, section 6): day-story reports count a confirmed Panel claim adjustment on the day it was
 * confirmed, so a past day never changes; per-invoice reports show the current figure with the adjustment on its own.
 * All data is synthetic.
 */
class PanelAdjustmentReportsTest extends BillingTestCase
{
    private CarbonImmutable $saleDay;

    private CarbonImmutable $confirmDay;

    public function test_day_story_reports_count_an_adjustment_on_its_confirmation_day_and_never_change_a_past_day(): void
    {
        [$officer, $visit, $invoice, $allocation] = $this->panelVisitWithAdjustments();
        $sale = $this->saleDay->toDateString();
        $confirm = $this->confirmDay->toDateString();

        // Daily summary: the Panel column keeps the original share on the sale day; the new last column carries the
        // signed adjustments on their confirmation day; Total received never includes them.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $daily = fn (string $from, string $to): array => $this->postJson(route('reports.daily-summary.run'), ['expected_branch_id' => $this->branch->id, 'from' => $from, 'to' => $to])
            ->assertOk()->json('result');
        $both = $daily($sale, $confirm);
        $this->assertSame('Panel claim adjustments (confirmation date, MYR)', $both['columns'][16]);
        $this->assertSame([$sale, '30.00', '0.00', '10.00'], [$both['rows'][0][0], $both['rows'][0][7], $both['rows'][0][16], $both['rows'][0][11]]);
        $this->assertSame([$confirm, '0.00', '30.00', '0.00'], [$both['rows'][1][0], $both['rows'][1][7], $both['rows'][1][16], $both['rows'][1][11]]);
        $this->assertSame('30.00', $both['totals']['Panel claim adjustments (confirmation date, MYR)']);
        $this->assertSame('10.00', $both['totals']['Total received (Cash + Non-cash) (receipt date, MYR)']);
        // The sale day alone is exactly what it was before the adjustments.
        $this->assertSame([$sale, '30.00', '0.00'], [$daily($sale, $sale)['rows'][0][0], $daily($sale, $sale)['rows'][0][7], $daily($sale, $sale)['rows'][0][16]]);
        // A visit-type filter that excludes the visit also excludes its adjustments.
        $otc = $this->postJson(route('reports.daily-summary.run'), ['expected_branch_id' => $this->branch->id, 'from' => $confirm, 'to' => $confirm, 'visit_types' => ['otc']])->assertOk()->json('result');
        $this->assertSame('0.00', $otc['rows'][0][16]);

        // Insights Sales: the sale day keeps its figure; the confirmation day carries +50 - 20 = +30 as its own row.
        $director = $this->actor('director');
        $sales = fn (string $from, string $to): array => app(ClinicInsightsReportService::class)->report($director, 'sales', 'all', $from, $to, null)['report'];
        $onSale = $sales($sale, $sale);
        $this->assertSame(4000, $onSale['summary']['totalSen']);
        $this->assertSame(0, $onSale['summary']['reconciliation']['panelAdjustmentsSen']);
        $onConfirm = $sales($confirm, $confirm);
        $this->assertSame(['grossSen' => 0, 'discountsSen' => 0, 'surchargesSen' => 0, 'refundCreditsSen' => 0, 'panelAdjustmentsSen' => 3000, 'netSen' => 3000], $onConfirm['summary']['reconciliation']);
        $this->assertSame(3000, $onConfirm['summary']['totalSen']);
        $this->assertSame([['date' => $confirm, 'salesSen' => 3000, 'patients' => 0]], $onConfirm['dailyTrend']);
        $this->assertSame(3000, array_sum(array_column($onConfirm['providers'], 'salesSen')), 'Provider rows add up to the headline.');
        $range = $sales($sale, $confirm);
        $this->assertSame(7000, $range['summary']['totalSen']);
        $this->assertSame(7000, array_sum(array_column($range['dailyTrend'], 'salesSen')));
        $this->assertSame(7000, array_sum(array_column($range['providers'], 'salesSen')));
        $this->assertSame(4000, $range['summary']['reconciliation']['grossSen'], 'Item values stay gross.');

        // Insights Payments: Panel sales and the Panel's approved figure gain the adjustment on its confirmation day.
        $payments = fn (string $from, string $to): array => app(ClinicInsightsReportService::class)->report($director, 'payments', 'all', $from, $to, null)['report'];
        $paidOnConfirm = $payments($confirm, $confirm);
        $this->assertSame(3000, $paidOnConfirm['summary']['panelSalesSen']);
        $this->assertSame(3000, collect($paidOnConfirm['panels'])->firstWhere('name', $allocation->panel_name_snapshot)['approvedSen']);
        // The sale day keeps its original sales (this synthetic visit was registered as self-pay, so they sit there).
        $onSaleDay = $payments($sale, $sale)['summary'];
        $this->assertSame(4000, $onSaleDay['selfPaySalesSen'] + $onSaleDay['panelSalesSen']);

        // The money tool gives the same day figures as the reports.
        $money = app(MoneyDaySummaryTool::class)->run($director, $this->branch, ['date' => $confirm]);
        $this->assertSame([3000, 0], [$money['branches'][0]['panelAdjustmentSen'], $money['branches'][0]['panelApprovedSen']]);

        unset($officer, $visit, $invoice);
    }

    public function test_per_invoice_reports_show_the_current_panel_share_with_the_adjustment_on_its_own(): void
    {
        [$officer, $visit, $invoice, $allocation] = $this->panelVisitWithAdjustments();
        $sale = $this->saleDay->toDateString();

        // Billing Detail: the current Panel share (30 + 50 - 20 = 60) and the adjustment column (30).
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $filters = ['expected_branch_id' => $this->branch->id, 'from' => $sale, 'to' => $sale];
        $this->postJson(route('patient-billing-report.run'), $filters)->assertOk()
            ->assertJsonPath('result.rows.0.panelSen', 6000)->assertJsonPath('result.rows.0.panelAdjustmentSen', 3000)
            ->assertJsonPath('result.totals.panelAdjustmentSen', 3000)->assertJsonPath('result.rows.0.dueSen', 0);
        $csv = $this->postJson(route('patient-billing-report.download'), $filters)->assertOk()->streamedContent();
        $this->assertStringContainsString('"Panel adjustment (MYR)"', $csv);
        $this->assertStringContainsString('"Total Panel adjustment (MYR)",30.00', $csv);

        // Panel invoices: the invoice total and Panel amount are the current figures; the adjustment has its own column.
        $this->selectBranch($officer);
        $params = ['panel' => $allocation->panel_name_snapshot, 'from' => $sale, 'to' => $sale];
        $this->get(route('panel-invoices.index', $params))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.invoiceTotalSen', 7000)->where('rows.0.panelAmountSen', 6000)->where('rows.0.panelAdjustmentSen', 3000));
        $csv = $this->get(route('panel-invoices.download', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Panel amount (RM)","Panel adjustment (RM)","Claim number"', $csv);
        $this->assertStringContainsString(',70.00,60.00,30.00,', $csv);

        // A minus adjustment prints as a plain number, not a neutralised text cell.
        $this->travelTo($this->confirmDay->addDay());
        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'deduct', 4000));
        $csv = $this->get(route('panel-invoices.download', $params))->assertOk()->streamedContent();
        $this->assertStringContainsString(',30.00,20.00,-10.00,', $csv);
        $this->assertStringNotContainsString("'-10.00", $csv);

        unset($visit);
    }

    public function test_waiting_and_withdrawn_requests_count_nowhere(): void
    {
        [$officer, , $invoice, $allocation] = $this->panelVisitWithAdjustments();
        $confirm = $this->confirmDay->toDateString();
        $waiting = $this->request($officer, $invoice, $allocation, 'add', 900);
        $director = $this->actor('director');
        $this->assertSame(3000, app(ClinicInsightsReportService::class)->report($director, 'sales', 'all', $confirm, $confirm, null)['report']['summary']['reconciliation']['panelAdjustmentsSen']);
        $this->selectBranch($officer);
        app(PanelClaimAdjustmentService::class)->withdraw($officer, $waiting, ['expected_branch_id' => $waiting->branch_id, 'lock_version' => $waiting->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(3000, app(ClinicInsightsReportService::class)->report($director, 'sales', 'all', $confirm, $confirm, null)['report']['summary']['reconciliation']['panelAdjustmentsSen']);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->postJson(route('reports.daily-summary.run'), ['expected_branch_id' => $this->branch->id, 'from' => $confirm, 'to' => $confirm])->assertOk()
            ->assertJsonPath('result.rows.0.16', '30.00');
    }

    /**
     * A completed Panel visit sold at 11:00 Kuala Lumpur time on the sale day (RM 40: RM 30 Panel, RM 10 cash), then a
     * +RM 50 and a -RM 20 Panel claim adjustment confirmed on the next day.
     *
     * @return array{0: User, 1: mixed, 2: Invoice, 3: CoverageAllocation}
     */
    private function panelVisitWithAdjustments(): array
    {
        $this->saleDay = CarbonImmutable::parse('2026-10-15 11:00', $this->branch->timezone);
        $this->confirmDay = $this->saleDay->addDay();
        $this->travelTo($this->saleDay);
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $officer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '3000',
            'panel_id' => $panel->id, 'reason' => 'Synthetic Panel responsibility'])->assertSessionHasNoErrors();
        $allocation = CoverageAllocation::query()->where('invoice_id', $invoice->id)->sole();
        $this->selectBranch($officer);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', $allocation->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1000',
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])
            ->assertSessionHasNoErrors();

        $this->travelTo($this->confirmDay);
        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'add', 5000));
        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'deduct', 2000));

        return [$officer, $visit->refresh(), $invoice->refresh(), $allocation->refresh()];
    }

    private function request(User $actor, Invoice $invoice, CoverageAllocation $allocation, string $direction, int $sen): PanelClaimAdjustment
    {
        $this->selectBranch($actor);

        return app(PanelClaimAdjustmentService::class)->request($actor, $allocation, [
            'expected_branch_id' => $invoice->branch_id, 'invoice_lock_version' => $invoice->refresh()->lock_version, 'direction' => $direction,
            'amount_sen' => (string) $sen, 'reason_code' => 'panel_service_charge', 'note' => null, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function confirm(User $actor, PanelClaimAdjustment $adjustment): void
    {
        $this->selectBranch($actor);
        app(PanelClaimAdjustmentService::class)->confirm($actor, $adjustment, [
            'expected_branch_id' => $adjustment->branch_id, 'lock_version' => $adjustment->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
