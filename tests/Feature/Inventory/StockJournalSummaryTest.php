<?php

namespace Tests\Feature\Inventory;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\StockJournalSummaryService;
use App\Domain\Organisation\Inventory\Services\WeightedAverageCostService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/** STK-29: the monthly journal summary CSV balances exactly, ties to the STK-28 report, and is the Director's alone. */
final class StockJournalSummaryTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /**
     * Last month: the fixture's opening (10, no cost) and a second batch opened at 100 sen a unit (10).
     * This month: 2 dispensed and sold at 250 sen.
     *
     * @return array{director: User, last: string, this: string}
     */
    private function twoMonths(): array
    {
        $zone = $this->branch->timezone;
        $this->travelTo(now($zone)->subMonthNoOverflow()->startOfMonth()->addDays(3)->setTime(10, 0));
        $last = now($zone)->format('Y-m');
        $f = $this->fixture();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-JNL-2',
            'expiry_date' => now($zone)->addDays(200)->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => 'available'])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $f['visit']->branch_id, 'location_public_id' => $f['location']->public_id,
            'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000', 'unit_cost_sen' => 100]);
        $this->travelBack();
        $this->travelTo(now($zone)->setTime(11, 0));

        $this->selectBranch($f['ca'], $f['visit']->branch);
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic journal prices', 'currency' => 'MYR'])->save();
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => 'medicine', 'code' => 'JNL-'.Str::upper(Str::random(5)), 'display_name' => 'Synthetic journal charge',
            'source_key' => 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'unit' => $f['medicine']->order_unit, 'is_active' => true])->save();
        (new PriceEntry)->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => 250,
            'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
        // Recreated in this month, because the fixture's visit belongs to last month.
        $visit = $this->register($f['ca'], $this->patient());
        $g = ['ca' => $f['ca'], 'visit' => $visit, 'medicine' => $f['medicine'], 'location' => $f['location'], 'sku' => $f['sku'], 'batch' => $batch];
        $case = $this->open($g);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($g, $case, '2.000'));
        $this->confirmAllergy($g, $case);
        $this->complete($g, $case);
        $invoice = app(BillingBuilderService::class)->build($f['ca'], $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        app(BillingBuilderService::class)->finalize($f['ca'], $visit->refresh(), $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version]);

        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);

        return ['director' => $director, 'last' => $last, 'this' => now($zone)->format('Y-m')];
    }

    /** @return array<string, mixed> */
    private function summary(User $director, string $month, string $branch): array
    {
        return app(StockJournalSummaryService::class)->summary($director, $this->branch, ['branch' => $branch, 'month' => $month]);
    }

    public function test_the_check_line_is_exactly_zero_for_each_month_and_scope(): void
    {
        $t = $this->twoMonths();
        foreach ([$t['last'], $t['this']] as $month) {
            foreach ([(string) $this->branch->id, 'all'] as $scope) {
                $this->assertSame(0, $this->summary($t['director'], $month, $scope)['checkMilliSen'], "{$month} {$scope}");
            }
        }

        // Last month: 1,000 sen of costed opening balances in, nothing out; this month: 200 sen dispensed out.
        $last = $this->summary($t['director'], $t['last'], (string) $this->branch->id);
        $this->assertSame([0, 1_000_000, 1_000_000], [$last['openingMilliSen'], $last['lines']['opening'], $last['closingMilliSen']]);
        $this->assertTrue($last['uncostedSkus']);
        $now = $this->summary($t['director'], $t['this'], (string) $this->branch->id);
        $this->assertSame([1_000_000, 200_000, 800_000], [$now['openingMilliSen'], $now['lines']['cogs_dispensed'], $now['closingMilliSen']]);
        $this->assertSame(0, $now['lines']['opening']);
    }

    public function test_every_line_ties_to_the_stock_value_report_for_the_same_month(): void
    {
        $t = $this->twoMonths();
        $first = $t['this'].'-01';
        $today = now($this->branch->timezone)->toDateString();
        foreach ([(string) $this->branch->id, 'all'] as $scope) {
            $journal = $this->summary($t['director'], $t['this'], $scope);
            $report = $this->actingAs($t['director'])->get(route('inventory.stock-value', ['branch' => $scope, 'from' => $first, 'to' => $today]))->assertOk()
                ->viewData('page')['props']['report'];
            $sen = fn (int $milli): int => WeightedAverageCostService::sen($milli);
            $this->assertSame($report['summary']['dispenseCostSen'], $sen($journal['lines']['cogs_dispensed']), $scope);
            $this->assertSame($report['summary']['usageCostSen'], $sen($journal['lines']['cogs_treatment']), $scope);
            $losses = collect($report['losses']['rows'])->keyBy('key');
            $this->assertSame($losses['expiry']['sen'], $sen($journal['lines']['expiry']), $scope);
            $this->assertSame($losses['writeoff']['sen'], $sen($journal['lines']['writeoff']), $scope);
            $this->assertSame($losses['waste']['sen'], $sen($journal['lines']['waste']), $scope);
            $this->assertSame($losses['count']['sen'], $sen($journal['lines']['count_losses'] - $journal['lines']['count_gains']), $scope);
            $this->assertSame($losses['credit']['sen'], $sen($journal['creditDifference']['milliSen']), $scope);
            // The report's stock value excludes stock in transit; All branches in the journal counts it.
            $this->assertSame($report['summary']['stockValueSen'] + ($scope === 'all' ? $report['summary']['inTransitSen'] : 0), $sen($journal['closingMilliSen']), $scope);
        }
    }

    public function test_the_csv_is_safe_carries_the_disclaimer_and_writes_only_one_audit_row(): void
    {
        $t = $this->twoMonths();
        $tables = collect(DB::select('select tablename from pg_tables where schemaname = current_schema() order by tablename'))->pluck('tablename')->reject(fn (string $t): bool => $t === 'audit_logs');
        $snapshot = fn (): array => $tables->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $before = $snapshot();

        $response = $this->actingAs($t['director'])->get(route('inventory.stock-value.journal', ['branch' => (string) $this->branch->id, 'month' => $t['this']]))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('kpone-stock-journal-'.$t['this'].'-to-date-branch-'.$this->branch->id.'.csv', (string) $response->headers->get('Content-Disposition'));
        $csv = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $csv);
        $this->assertStringContainsString('not professional accounting advice', (string) $csv);
        $this->assertStringContainsString('No real accounting system receives this file.', (string) $csv);
        $this->assertStringContainsString('Line,Date,Description,Account,"Debit (RM)","Credit (RM)",Note', (string) $csv);
        $this->assertStringContainsString('"Cost of goods sold, dispensed","Cost of goods sold",2.00,', (string) $csv);
        $this->assertStringContainsString('"Cost of goods sold, dispensed",Inventory,,2.00,', (string) $csv);
        $this->assertStringContainsString('Difference 0.00', (string) $csv);
        // No negative amounts: debits and credits are positive.
        $this->assertDoesNotMatchRegularExpression('/,-\d/', (string) $csv);

        $this->assertSame($before, $snapshot());
        $audit = DB::table('audit_logs')->where('event', 'inventory.valuation.journal_exported')->sole();
        $this->assertSame(['branch_id', 'month', 'rows', 'scope'], collect(json_decode((string) $audit->metadata, true))->keys()->sort()->values()->all());
    }

    public function test_only_the_director_may_download_and_a_future_month_or_unknown_branch_is_refused(): void
    {
        $month = now($this->branch->timezone)->format('Y-m');
        foreach (['ca_supervisor', 'finance_officer', 'administrator', 'ca'] as $role) {
            $user = $this->actor($role);
            $this->selectBranch($user);
            $this->get(route('inventory.stock-value.journal', ['branch' => (string) $this->branch->id, 'month' => $month]))->assertForbidden();
        }
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->get(route('inventory.stock-value.journal', ['branch' => (string) $this->branch->id, 'month' => now($this->branch->timezone)->addMonthNoOverflow()->format('Y-m')]))
            ->assertSessionHasErrors(['month' => 'Choose a month up to this month.']);
        $this->get(route('inventory.stock-value.journal', ['branch' => '999999', 'month' => $month]))->assertSessionHasErrors(['branch' => 'Choose a branch, or All branches.']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'inventory.valuation.journal_exported')->count());
        // The report page offers the download only for one calendar month (frontend), and the route is Director only.
        $this->get(route('inventory.stock-value'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Inventory/StockValue'));
    }

    public function test_a_journal_that_does_not_balance_is_refused_and_a_running_month_is_month_to_date(): void
    {
        StockJournalSummaryService::ensureBalanced(0);
        try {
            StockJournalSummaryService::ensureBalanced(1);
            $this->fail('An unbalanced journal was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['journal' => ['The journal does not balance, so no file was made.']], $e->errors());
        }

        $director = $this->actor('director');
        $this->selectBranch($director);
        $month = now($this->branch->timezone)->format('Y-m');
        $response = $this->get(route('inventory.stock-value.journal', ['branch' => (string) $this->branch->id, 'month' => $month]))->assertOk();
        $this->assertStringContainsString('kpone-stock-journal-'.$month.'-to-date-branch-', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('(month to date, up to '.now($this->branch->timezone)->toDateString().')', (string) $response->getContent());
        $this->assertStringContainsString(','.now($this->branch->timezone)->toDateString().',"Memo: opening stock value"', (string) $response->getContent());
    }
}
