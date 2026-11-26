<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Models\StockMovement;
use App\Domain\Organisation\Inventory\Services\HandCostRestatement;
use App\Domain\Organisation\Inventory\Services\HandCostService;
use App\Domain\Organisation\Inventory\Services\ReceiptCorrectionService;
use App\Domain\Organisation\Inventory\Services\StockJournalSummaryService;
use App\Domain\Organisation\Inventory\Services\StockValuationReportService;
use App\Domain\Organisation\Inventory\Services\WeightedAverageCostService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\GovernedStockFixture;
use Tests\Support\HandCostFixture;

final class HandCostRestatementTest extends VisitTestCase
{
    public function test_acceptance_restates_historical_closing_and_next_opening_with_current_provenance_only(): void
    {
        $today = now()->toImmutable();
        $this->travelTo($today->subMonthNoOverflow()->startOfMonth()->addDays(4));
        $f = (new GovernedStockFixture($this->branch))->build('10.000');
        $month = now($this->branch->timezone)->format('Y-m');
        $historicalEnd = now()->endOfMonth()->toImmutable();
        $movement = StockMovement::query()->where('inventory_batch_id', $f['batch']->id)->sole();
        $this->travelTo($today);
        $finance = $this->actor('finance_officer');
        $director = $this->actor('director');
        $wac = app(WeightedAverageCostService::class);
        $before = $wac->replay($this->organisation->id, $historicalEnd);
        $ledger = DB::table('stock_movements')->get()->toJson();
        $enter = function (int $amount) use ($finance, $movement) {
            $this->selectBranch($finance);

            return app(HandCostService::class)->enter($finance, ['expected_branch_id' => $this->branch->id,
                'movement_public_id' => $movement->public_id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(),
                'cost_total_sen' => $amount, 'reason_code' => 'other', 'note' => 'SYN-PRIVATE-NOTE']);
        };
        $decide = function ($cost, string $status = 'accepted') use ($director): void {
            $this->selectBranch($director);
            app(HandCostService::class)->decide($director, $cost->public_id, ['expected_branch_id' => $this->branch->id,
                'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => $status, 'reason' => $status === 'rejected' ? 'Synthetic rejection' : null]);
        };
        $first = $enter(20000);
        $this->assertSame($before, $wac->replay($this->organisation->id, $historicalEnd));
        $decide($first);
        $accepted = $wac->replay($this->organisation->id, $historicalEnd);
        $this->assertSame(20000000, $accepted['positions'][0]['value_milli_sen']);
        $waiting = $enter(30000);
        $this->assertSame($accepted, $wac->replay($this->organisation->id, $historicalEnd));
        $decide($waiting, 'rejected');
        $this->assertSame($accepted, $wac->replay($this->organisation->id, $historicalEnd));
        $replacement = $enter(25000);
        $decide($replacement); // same timestamp: latest id breaks the tie deterministically.
        $this->selectBranch($director);
        $summary = app(StockJournalSummaryService::class)->summary($director, $this->branch, ['branch' => 'all', 'month' => $month]);
        $this->assertSame(25000000, $summary['closingMilliSen']);
        $this->assertSame(0, $summary['checkMilliSen']);
        $this->assertSame([$replacement->id], array_column($summary['handCostChanges'], 'id'));
        $next = app(StockJournalSummaryService::class)->summary($director, $this->branch, ['branch' => 'all', 'month' => $today->format('Y-m')]);
        $this->assertSame(25000000, $next['openingMilliSen']);
        $this->assertSame(25000000, $next['closingMilliSen']);
        $this->assertSame(0, $next['checkMilliSen']);
        $this->assertSame($summary['handCostChanges'], $next['handCostChanges']);
        $report = app(StockValuationReportService::class)->report($director, $this->branch, ['branch' => 'all', 'from' => $month.'-01', 'to' => $historicalEnd->setTimezone($this->branch->timezone)->toDateString()]);
        $this->assertSame(25000, $report['summary']['stockValueSen']);
        $this->assertSame($summary['handCostChanges'], $report['handCostChanges']);
        $csv = app(StockJournalSummaryService::class)->csv($director, $this->branch, ['branch' => 'all', 'month' => $month])['csv'];
        $this->assertStringContainsString('Changed on ', $csv);
        $this->assertStringContainsString($f['sku']->sku_code, $csv);
        $this->assertStringContainsString('Historical figures have been recalculated', $csv);
        $this->assertStringNotContainsString('SYN-PRIVATE-NOTE', $csv);
        $this->assertSame($ledger, DB::table('stock_movements')->get()->toJson());
    }

    public function test_a_reversed_receipt_remains_in_historical_replay_with_accepted_provenance(): void
    {
        $fixture = new HandCostFixture($this->branch);
        $f = $fixture->receipt();
        $this->selectBranch($f['finance']);
        $cost = app(HandCostService::class)->enter($f['finance'], ['expected_branch_id' => $this->branch->id,
            'movement_public_id' => $f['movement']->public_id, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(),
            'cost_total_sen' => 4000, 'reason_code' => 'supplier_invoice_found']);
        $this->selectBranch($f['director']);
        app(HandCostService::class)->decide($f['director'], $cost->public_id, ['expected_branch_id' => $this->branch->id,
            'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => 'accepted']);
        $before = app(WeightedAverageCostService::class)->replay($this->organisation->id);
        $correction = $fixture->correction($f);
        $this->selectBranch($f['receiver']);
        app(ReceiptCorrectionService::class)->reverse($f['receiver'], $correction['review'], $correction['input']);
        $after = app(WeightedAverageCostService::class)->replay($this->organisation->id);
        $this->assertSame($before['movements'][$f['movement']->id], $after['movements'][$f['movement']->id]);
        $this->assertSame('hand', $after['movements'][$f['movement']->id]['basis']);
        $this->assertSame(4000000, $after['movements'][$f['movement']->id]['value_milli_sen']);
        $reversal = collect($after['movements'])->last();
        $this->assertSame(4000000, $reversal['value_milli_sen']);
        $this->assertSame([$cost->id], array_keys($reversal['hand_costs']));
    }

    public function test_notices_follow_scoped_positions_transit_and_period_outflows_without_leaking_other_branches(): void
    {
        $f = (new GovernedStockFixture($this->branch))->build();
        $provenance = [50 => ['id' => 50, 'sku_id' => $f['sku']->id, 'accepted_at' => '2026-11-25 16:30:00']];
        $replay = ['positions' => [['branch_key' => 20, 'hand_costs' => $provenance]],
            'inTransit' => [['hand_costs' => $provenance]],
            'movements' => [9 => ['in_branch' => null, 'out_branch' => 30, 'hand_costs' => $provenance]]];
        $notices = fn (array $keys, bool $all, array $ids): array => HandCostRestatement::notices(
            $this->organisation->id, [$replay], $keys, $all, $ids, 'Asia/Kuala_Lumpur');
        $this->assertSame([], $notices([10], false, [9]));
        $this->assertSame([], $notices([30], false, []));
        $this->assertSame([50], array_column($notices([20], false, []), 'id'));
        $this->assertSame([50], array_column($notices([30], false, [9]), 'id'));
        $this->assertSame([50], array_column($notices([10], true, []), 'id'));
        $this->assertSame('26 Nov 2026, 12:30 AM', $notices([20], false, [9])[0]['changedOn']);
    }
}
