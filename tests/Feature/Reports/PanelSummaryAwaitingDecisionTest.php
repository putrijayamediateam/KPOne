<?php

namespace Tests\Feature\Reports;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReportEvidence;

/**
 * PNL-17: submitted or queried claims with no Panel decision yet show in their own
 * "Awaiting decision" columns at the end of the Panel summary; every earlier figure,
 * including Accepted outstanding, is unchanged.
 */
class PanelSummaryAwaitingDecisionTest extends BillingTestCase
{
    use OtcDispensaryFixtures, ReportEvidence;

    private const AWAITING = ['Awaiting decision claims', 'Awaiting decision (MYR)', 'Awaiting decision 0-30 days (MYR)', 'Awaiting decision 31-60 days (MYR)', 'Awaiting decision 61-90 days (MYR)', 'Awaiting decision over 90 days (MYR)'];

    public function test_undecided_claims_get_their_own_trailing_columns_and_leave_outstanding_unchanged(): void
    {
        $origin = CarbonImmutable::parse('2026-01-01 10:00:00', 'Asia/Kuala_Lumpur');
        $this->travelTo($origin);
        $first = $this->finalizedFixture(10000);
        $officer = $this->actor('panel_officer');
        $finance = $this->actor('finance_officer');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic Panel']);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $officer->id, 'capability' => 'panel', 'limit_sen' => 10000]);
        $svc = app(ResponsibilityService::class);
        $claims = app(PanelClaimService::class);
        // 0: submitted, undecided; 1: queried, undecided; 2: accepted; 3: prepared, never submitted.
        foreach ([3000, 2000, 5000, 1000] as $i => $amount) {
            [, , $visit, $invoice] = $i === 0 ? $first : $this->anotherReportInvoice();
            $this->selectBranch($finance);
            $proposal = $svc->propose($finance, $visit, $invoice, 'panel', ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'panel_id' => $panel->id, 'reason' => 'Synthetic responsibility']);
            $this->selectBranch($officer);
            $svc->approve($officer, $visit, $invoice->refresh(), 'panel', $proposal->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
            $allocation = $proposal->refresh();
            $this->assertInstanceOf(CoverageAllocation::class, $allocation);
            $claim = $claims->prepare($officer, $allocation, ['expected_branch_id' => $this->branch->id]);
            if ($i === 3) {
                continue;
            }
            $claim = $claims->submit($officer, $claim, ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version]);
            if ($i === 1) {
                $claims->recordQuery($officer, $claim, ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version, 'note' => 'Synthetic query']);
            }
            if ($i === 2) {
                $claims->recordDecision($officer, $claim, ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version, 'decision' => 'accepted', 'accepted_amount_sen' => 5000, 'note' => 'Synthetic decision']);
            }
        }
        $this->selectBranch($finance);
        $filters = ['expected_branch_id' => $this->branch->id, 'from' => '2026-01-01', 'to' => '2026-01-01'];
        $before = $this->sourceEvidence();
        foreach ([0 => 0, 30 => 0, 31 => 1, 60 => 1, 61 => 2, 90 => 2, 91 => 3] as $age => $bucket) {
            $this->travelTo($origin->addDays($age));
            $result = $this->postJson(route('reports.panel-summary.run'), $filters)->assertOk()->json('result');
            $this->assertCount(25, $result['columns']);
            $this->assertSame('Unclaimed over 90 days (MYR)', $result['columns'][18]);
            $this->assertSame(self::AWAITING, array_slice($result['columns'], 19));
            $row = $result['rows'][0];
            // Earlier figures: Outstanding counts only the accepted claim.
            $this->assertSame(['Synthetic Panel', '4', '110.00', '3', '100.00', '1', '10.00', '50.00', '0.00', '0.00', '50.00'], array_slice($row, 0, 11));
            $this->assertSame('2', $row[19]);
            $this->assertSame('50.00', $row[20]);
            for ($j = 0; $j < 4; $j++) {
                $this->assertSame($j === $bucket ? '50.00' : '0.00', $row[21 + $j]);
            }
            $this->assertSame('2', $result['totals']['Awaiting decision claims']);
            $this->assertSame('50.00', $result['totals']['Awaiting decision (MYR)']);
            [, $columns, $rows] = $this->decodeReportCsv($this->postJson(route('reports.panel-summary.download'), $filters)->assertOk()->streamedContent());
            $this->assertSame($result['columns'], $columns);
            $this->assertSame($result['rows'], $rows);
            $this->assertSame($before, $this->sourceEvidence());
        }
        // A decision moves the claim out of Awaiting decision.
        $this->selectBranch($officer);
        $submitted = PanelClaim::query()->where('status', 'submitted')->firstOrFail();
        $claims->recordDecision($officer, $submitted, ['expected_branch_id' => $this->branch->id, 'lock_version' => $submitted->lock_version, 'decision' => 'rejected', 'accepted_amount_sen' => 0, 'note' => 'Synthetic decision']);
        $this->selectBranch($finance);
        $this->postJson(route('reports.panel-summary.run'), $filters)->assertOk()
            ->assertJsonPath('result.rows.0.19', '1')
            ->assertJsonPath('result.rows.0.20', '20.00');
    }
}
