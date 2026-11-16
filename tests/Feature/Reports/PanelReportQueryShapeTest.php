<?php

namespace Tests\Feature\Reports;

use App\Domain\Shared\Services\PanelSummaryReportService;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelClaimFollowUpService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Billing\ApprovedPanelAllocations;
use Tests\Feature\Billing\BillingTestCase;

/**
 * PNL-23 (Panel pilot work): the Panel summary and the Follow-up list must not issue queries per claim or per
 * allocation. Growing the data from 4 to 12 approved allocations must not add a single query to either, and the
 * rows must still add up. This proves the shape of the work (a bounded number of set-based queries), not a time or
 * a volume: the 2,001-allocation measurement is in docs/reports/PILOT-SCALE-LIMITS.md.
 */
class PanelReportQueryShapeTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    private const QUERY_BUDGET = 40;

    public function test_the_panel_summary_and_the_follow_up_list_issue_the_same_queries_for_four_and_for_twelve_allocations(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'claim_follow_up_days' => 1]);
        $finance = $this->actor('finance_officer');
        $today = CarbonImmutable::now($this->branch->timezone)->startOfDay()->addHours(10);

        $this->travelTo($today->subDays(5));
        $this->addAllocations($panel, 4, claimed: 3);
        $this->travelTo($today);
        $small = $this->measure($finance);

        $this->travelTo($today->subDays(4));
        $this->addAllocations($panel, 8, claimed: 6);
        $this->travelTo($today);
        $large = $this->measure($finance);

        $this->assertSame(4, $small['approved']);
        $this->assertSame(12, $large['approved']);
        $this->assertSame(3, $small['followUp']);
        $this->assertSame(9, $large['followUp']);
        $this->assertLessThanOrEqual(self::QUERY_BUDGET, $small['summaryQueries']);
        $this->assertLessThanOrEqual(self::QUERY_BUDGET, $small['followUpQueries']);
        $this->assertSame($small['summaryQueries'], $large['summaryQueries'], 'Panel summary query count grew with the data.');
        $this->assertSame($small['followUpQueries'], $large['followUpQueries'], 'Follow-up query count grew with the data.');
    }

    private function addAllocations(Panel $panel, int $count, int $claimed): void
    {
        $officer = $this->officer();
        for ($i = 0; $i < $count; $i++) {
            [$allocation, $invoice] = $this->approvedAllocation($panel);
            if ($i >= $claimed) {
                continue;
            }
            $this->selectBranch($officer, $this->branch);
            $this->post(route('panel-claims.store', $allocation), [
                'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
                'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
                'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            ])->assertSessionHasNoErrors();
        }
    }

    /** @return array{approved: int, followUp: int, summaryQueries: int, followUpQueries: int} */
    private function measure(User $finance): array
    {
        $from = CarbonImmutable::now($this->branch->timezone)->subDays(7)->toDateString();
        $to = CarbonImmutable::now($this->branch->timezone)->toDateString();
        $this->selectBranch($finance);
        // One unmeasured call first, so loading the permission cache is not counted as work that grows with the data.
        app(PanelSummaryReportService::class)->execute($finance, ['expected_branch_id' => $this->branch->id, 'from' => $from, 'to' => $to], true, false);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $summary = app(PanelSummaryReportService::class)->execute($finance, ['expected_branch_id' => $this->branch->id, 'from' => $from, 'to' => $to], true, false);
        $summaryQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        app(PanelClaimFollowUpService::class)->page($officer, []);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $followUp = app(PanelClaimFollowUpService::class)->page($officer, []);
        $followUpQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(PanelClaim::query()->count(), $followUp['claims']['total']);

        return [
            'approved' => (int) $summary['result']['totals']['Approved invoices'],
            'followUp' => $followUp['claims']['total'],
            'summaryQueries' => $summaryQueries,
            'followUpQueries' => $followUpQueries,
        ];
    }
}
