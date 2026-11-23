<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\MedicineSalesReportService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
use App\Domain\Organisation\Inventory\Services\StockValuationReportService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\VerifiedReceivingFixture;

/** STK-44: the medicine sales page (P9: Director and Finance Officer; KPOne defaults, not accounting advice). */
final class MedicineSalesReportTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_only_the_director_and_finance_officer_hold_the_permission_and_it_is_not_people_or_access_authority(): void
    {
        $holders = [];
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            if (in_array(MedicineSalesReportService::PERMISSION, $permissions, true)) {
                $holders[] = $role;
            }
        }
        sort($holders);
        $this->assertSame(['director', 'finance_officer'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(MedicineSalesReportService::PERMISSION));
        $stored = Role::query()->whereHas('permissions', fn ($q) => $q->where('name', MedicineSalesReportService::PERMISSION))->pluck('name')->sort()->values()->all();
        $this->assertSame(['director', 'finance_officer'], $stored);
    }

    public function test_the_grant_migration_is_additive_and_its_down_changes_nothing(): void
    {
        $migration = require database_path('migrations/2026_11_07_003500_grant_medicine_sales_permission.php');
        Role::findByName('finance_officer', 'web')->revokePermissionTo(MedicineSalesReportService::PERMISSION);
        $migration->up();
        $migration->up();
        $this->assertTrue(Role::findByName('finance_officer', 'web')->hasPermissionTo(MedicineSalesReportService::PERMISSION));
        $migration->down();
        $this->assertTrue(Role::findByName('director', 'web')->hasPermissionTo(MedicineSalesReportService::PERMISSION));
    }

    public function test_every_other_role_is_refused_and_nothing_is_audited(): void
    {
        $audits = DB::table('audit_logs')->where('event', 'inventory.medicine_sales.viewed')->count();
        foreach (['ca', 'ca_supervisor', 'store_admin', 'account_assistant', 'resident_doctor', 'administrator', 'technical_admin', 'panel_officer'] as $role) {
            $user = $this->actor($role);
            $this->selectBranch($user);
            $this->get(route('inventory.medicine-sales'))->assertForbidden();
        }
        $this->assertSame($audits, DB::table('audit_logs')->where('event', 'inventory.medicine_sales.viewed')->count());
    }

    public function test_the_inventory_home_flag_is_true_for_holders_only(): void
    {
        foreach (['director' => true, 'finance_officer' => true, 'ca_supervisor' => false, 'store_admin' => false, 'account_assistant' => false] as $role => $expected) {
            $user = $this->actor($role);
            $this->selectBranch($user);
            $this->get(route('inventory.index'))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('referenceData.canViewMedicineSales', $expected));
        }
    }

    public function test_sales_cost_profit_and_margin_for_a_month_with_only_an_audit_row_written(): void
    {
        $f = $this->sold(250);

        foreach (['director', 'finance_officer'] as $role) {
            $viewer = $this->actor($role);
            $this->selectBranch($viewer, $f['visit']->branch);
            $tables = collect(DB::select('select tablename from pg_tables where schemaname = current_schema() order by tablename'))->pluck('tablename')->reject(fn (string $t): bool => $t === 'audit_logs');
            $before = $tables->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();
            $audits = DB::table('audit_logs')->where('event', 'inventory.medicine_sales.viewed')->count();

            $this->get(route('inventory.medicine-sales'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Inventory/MedicineSales')
                ->where('report.banner', StockValuationReportService::BANNER)
                ->where('report.filters.branch', (string) $f['visit']->branch_id)
                ->where('report.filters.rank', 'sales')
                ->where('report.monthLabel', CarbonImmutable::now($this->branch->timezone)->format('F Y').' (to date)')
                // Sold 2 at 250 sen; dispensed 2 at the 100 sen average.
                ->where('report.summary.salesSen', 500)
                ->where('report.summary.costSen', 200)
                ->where('report.summary.profitSen', 300)
                ->where('report.summary.marginPercent', 60)
                ->where('report.summary.incompleteMedicines', 0)
                ->where('report.summary.belowLines', 0)
                ->where('report.summary.freeLines', 0)
                ->where('report.medicineCount', 1)
                ->where('report.rows.0.rank', 1)
                ->where('report.rows.0.medicine', 'Synthetic OTC medicine')
                ->where('report.rows.0.units', [['unit' => 'unit', 'quantity' => '2']])
                ->where('report.rows.0.costComplete', true)
                ->where('report.rows.0.estimate', false)
                ->where('report.below.total', 0));

            $this->assertSame($before, $tables->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all());
            $this->assertSame($audits + 1, DB::table('audit_logs')->where('event', 'inventory.medicine_sales.viewed')->count());
            $metadata = json_decode((string) DB::table('audit_logs')->where('event', 'inventory.medicine_sales.viewed')->latest('id')->value('metadata'), true);
            $this->assertSame(['branch_id', 'month', 'rank', 'scope'], collect($metadata)->keys()->sort()->values()->all());
        }
    }

    public function test_the_page_data_holds_no_patient_or_doctor_field(): void
    {
        $f = $this->sold(250);
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        $props = $this->get(route('inventory.medicine-sales'))->assertOk()->viewData('page')['props']['report'];
        $json = json_encode($props, JSON_THROW_ON_ERROR);
        foreach (['patient', 'doctor', 'visit', 'nric', 'phone', (string) $f['visit']->patient?->full_name] as $word) {
            if ($word !== '') {
                $this->assertStringNotContainsStringIgnoringCase($word, $json);
            }
        }
    }

    public function test_cost_not_complete_hides_profit_and_leaves_the_medicine_out_of_the_profit_totals(): void
    {
        // The fixture's own opening stock has no cost.
        $f = $this->sold(250, costed: false);
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);

        $this->get(route('inventory.medicine-sales'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.rows.0.salesSen', 500)
            ->where('report.rows.0.costComplete', false)
            ->where('report.rows.0.costSen', null)
            ->where('report.rows.0.profitSen', null)
            ->where('report.rows.0.marginPercent', null)
            ->where('report.summary.salesSen', 500)
            ->where('report.summary.costSen', 0)
            ->where('report.summary.profitSen', 0)
            // The margin's denominator is the sales of complete-cost medicines only, so there is none.
            ->where('report.summary.marginPercent', null)
            ->where('report.summary.incompleteMedicines', 1));
    }

    public function test_a_line_under_its_cost_counts_as_below_cost_and_a_free_line_does_not(): void
    {
        $f = $this->sold(50);
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        $this->get(route('inventory.medicine-sales'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.rows.0.salesSen', 100)
            ->where('report.rows.0.profitSen', -100)
            ->where('report.rows.0.marginPercent', -100)
            ->where('report.rows.0.belowLines', 1)
            ->where('report.rows.0.shortfallSen', 100)
            ->where('report.summary.belowLines', 1)
            ->where('report.below.total', 1)
            ->where('report.below.rows.0.medicine', 'Synthetic OTC medicine'));

        $free = $this->sold(0);
        $this->selectBranch($director, $free['visit']->branch);
        $this->get(route('inventory.medicine-sales'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.freeLines', 1)
            // A free line is not sold below cost, and its cost still counts.
            ->where('report.summary.belowLines', 1)
            ->where('report.summary.costSen', 400));
    }

    public function test_a_line_costed_from_an_order_estimate_is_marked_estimate(): void
    {
        $f = (new VerifiedReceivingFixture($this->branch))->build('2.000');
        app(BranchAccessService::class)->select($f['receiver'], $this->branch);
        $payload = $f['payload'];
        // Expires before the fixture's own batch, so dispensing it needs no FEFO exception.
        $payload['lines'][0]['expiry_date'] = now($this->branch->timezone)->addDays(10)->toDateString();
        // No cost typed: the order's estimate of 125 sen a unit is used and marked as an estimate (STK-10B).
        app(ProcurementService::class)->receive($f['receiver'], $f['order'], $payload);
        $f['batch'] = InventoryBatch::query()->where('batch_number', $payload['lines'][0]['batch_number'])->sole();
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->price($f, 300);
        app(DispensaryService::class)->addItem($f['ca'], $f['case']->refresh(), $this->addPayload($f, $f['case'], '2.000'));
        $this->complete($f, $f['case']);
        $invoice = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
        app(BillingBuilderService::class)->finalize($f['ca'], $f['visit']->refresh(), $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);

        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        $this->get(route('inventory.medicine-sales'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.rows.0.salesSen', 600)
            ->where('report.rows.0.costSen', 250)
            ->where('report.rows.0.estimate', true)
            ->where('report.summary.estimate', true));
    }

    public function test_the_month_is_cut_in_the_branch_time_zone(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', $this->branch->timezone);
        // Finalised at 23:59 on 31 Oct, Kuala Lumpur time: it belongs to October, not November.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:59:00', 'Asia/Kuala_Lumpur'));
        $f = $this->sold(250);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Kuala_Lumpur'));
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);

        $this->get(route('inventory.medicine-sales', ['month' => '2026-10']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.summary.salesSen', 500)->where('report.monthLabel', 'October 2026'));
        $this->get(route('inventory.medicine-sales', ['month' => '2026-11']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.summary.salesSen', 0)->where('report.monthLabel', 'November 2026 (to date)')->where('report.rows', []));
    }

    public function test_a_voided_invoice_reissued_next_month_counts_once_in_the_reissue_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00:00', 'Asia/Kuala_Lumpur'));
        $f = $this->sold(250);
        $invoice = Invoice::query()->where('visit_id', $f['visit']->id)->where('status', 'finalized')->sole();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'Asia/Kuala_Lumpur'));
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $f['visit']->branch);
        app(InvoiceCorrectionService::class)->void($finance, $f['visit']->refresh(), $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'reason' => 'Synthetic correction']);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $reissue = app(BillingBuilderService::class)->build($f['ca'], $f['visit']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
        app(BillingBuilderService::class)->finalize($f['ca'], $f['visit']->refresh(), $reissue, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $reissue->lock_version]);

        // Across both months, each dispensary item is on exactly one finalised invoice line.
        $this->assertSame(0, DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('l.line_type', 'medicine')->where('i.status', 'finalized')
            ->groupBy('l.dispensary_item_id')->havingRaw('count(*) > 1')->get(['l.dispensary_item_id'])->count());

        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        $this->get(route('inventory.medicine-sales', ['month' => '2026-10']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.summary.salesSen', 0));
        $this->get(route('inventory.medicine-sales', ['month' => '2026-11']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.salesSen', 500)->where('report.summary.costSen', 200)->where('report.summary.lineCount', 1));
    }

    public function test_every_medicine_line_has_a_dispensary_item(): void
    {
        $this->sold(250);
        $this->assertSame(0, DB::table('invoice_lines')->where('line_type', 'medicine')->whereNull('dispensary_item_id')->count());
    }

    public function test_all_branches_adds_up_every_branch(): void
    {
        $f = $this->sold(250);
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        $one = $this->get(route('inventory.medicine-sales'))->viewData('page')['props']['report']['summary']['salesSen'];
        $this->get(route('inventory.medicine-sales', ['branch' => 'all']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.branch', 'all')->where('report.scopeLabel', 'All branches')->where('report.summary.salesSen', $one));
    }

    public function test_refused_filters_fall_back_to_the_default_view_with_the_reason(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->get(route('inventory.medicine-sales', ['month' => '2020-01']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.refused', ['Choose one of the last 24 months.']));
        $this->get(route('inventory.medicine-sales', ['rank' => 'units']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.refused', ['Rank by Sales or Gross profit.'])->where('report.filters.rank', 'sales'));
        $this->get(route('inventory.medicine-sales', ['branch' => '999999']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.refused', ['Choose a branch, or All branches.'])->where('report.filters.branch', (string) $this->branch->id));
        $this->get(route('inventory.medicine-sales', ['rank' => 'profit']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.filters.rank', 'profit')->missing('report.refused'));
    }

    public function test_an_audit_failure_leaves_the_page_unshown(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->andReturn(null));
        $this->get(route('inventory.medicine-sales'))->assertStatus(503);
    }

    public function test_only_dispensing_movements_are_read_so_a_return_is_never_subtracted(): void
    {
        $f = $this->sold(250);
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('inventory.medicine-sales'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.summary.salesSen', 500)->where('report.summary.costSen', 200));
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();
        $this->assertStringNotContainsString('dispensary_returns', $sql);
        $this->assertStringNotContainsString('dispensary_return\'', $sql);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_lines(): void
    {
        $f = $this->sold(250);
        $director = $this->actor('director');
        $count = function () use ($director, $f): int {
            $this->selectBranch($director, $f['visit']->branch);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('inventory.medicine-sales', ['branch' => 'all']))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        // The first request also loads the viewer's roles and permissions; measure from the second.
        $count();
        $one = $count();
        $this->sold(250);
        $this->sold(300);
        $this->assertSame($one, $count());
    }

    /**
     * One OTC visit: 10 units opened with no cost (the fixture) and, unless $costed is false, 10 more at 100 sen; 2
     * units dispensed, invoiced at $price sen each and finalised now.
     *
     * @return array<string, mixed>
     */
    private function sold(int $price, bool $costed = true): array
    {
        $f = $this->fixture();
        if ($costed) {
            $batch = new InventoryBatch;
            $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-MS-'.Str::upper(Str::random(5)), 'expiry_date' => now($this->branch->timezone)->addDays(20)->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => 'available'])->save();
            $supervisor = $this->actor('ca_supervisor');
            $this->selectBranch($supervisor, $f['visit']->branch);
            app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $f['visit']->branch_id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000', 'unit_cost_sen' => 100]);
            $f['batch'] = $batch;
            $this->selectBranch($f['ca'], $f['visit']->branch);
        }
        $this->price($f, $price);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '2.000'));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $invoice = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
        app(BillingBuilderService::class)->finalize($f['ca'], $f['visit']->refresh(), $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);

        return $f;
    }

    /** @param array<string, mixed> $f */
    private function price(array $f, int $sen): void
    {
        $book = PriceBook::query()->where('organisation_id', $f['ca']->organisation_id)->where('scope_key', 'organisation')->first();
        if ($book === null) {
            $book = new PriceBook;
            $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic medicine sales prices', 'currency' => 'MYR'])->save();
        }
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => 'medicine', 'code' => 'MS-'.Str::upper(Str::random(5)), 'display_name' => 'Synthetic medicine sales charge', 'source_key' => 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'unit' => $f['medicine']->order_unit, 'is_active' => true])->save();
        (new PriceEntry)->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $sen, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
    }
}
