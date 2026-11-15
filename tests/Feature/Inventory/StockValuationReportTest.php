<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Inventory\Services\StockValuationReportService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/** STK-28: the stock value, cost and losses report is the Director's alone, reads invoices without changing them and writes one audit row. */
final class StockValuationReportTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_only_a_director_holds_the_permission_and_it_is_not_people_or_access_authority(): void
    {
        $holders = [];
        foreach (PermissionCatalogue::roles() as $role => $permissions) {
            if (in_array(StockValuationReportService::PERMISSION, $permissions, true)) {
                $holders[] = $role;
            }
        }
        $this->assertSame(['director'], $holders);
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority(StockValuationReportService::PERMISSION));
        $this->assertSame(['director'], Role::query()->whereHas('permissions', fn ($q) => $q->where('name', StockValuationReportService::PERMISSION))->pluck('name')->all());
    }

    public function test_every_other_role_is_refused(): void
    {
        foreach (['ca', 'ca_supervisor', 'finance_officer', 'administrator', 'technical_admin', 'panel_officer'] as $role) {
            $user = $this->actor($role);
            $this->selectBranch($user);
            $this->get(route('inventory.stock-value'))->assertForbidden();
        }
    }

    public function test_the_director_sees_value_cost_sales_and_margin_and_only_an_audit_row_is_written(): void
    {
        $f = $this->fixture();
        // The fixture's opening has no cost; a second batch is opened at 100 sen a unit, so the average is 100 sen.
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-VAL-2', 'expiry_date' => now($this->branch->timezone)->addDays(120)->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => 'available'])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $f['visit']->branch_id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000', 'unit_cost_sen' => 100]);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->price($f, 250);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '2.000'));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $invoice = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
        app(BillingBuilderService::class)->finalize($f['ca'], $f['visit']->refresh(), $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);

        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);
        $tables = collect(DB::select('select tablename from pg_tables where schemaname = current_schema() order by tablename'))->pluck('tablename')->reject(fn (string $t): bool => $t === 'audit_logs');
        $snapshot = fn (): array => $tables->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $before = $snapshot();
        $invoices = DB::table('invoices')->orderBy('id')->get()->toJson().DB::table('invoice_lines')->orderBy('id')->get()->toJson();
        $audits = DB::table('audit_logs')->where('event', 'inventory.valuation.viewed')->count();

        $this->get(route('inventory.stock-value'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Inventory/StockValue')
            ->where('report.banner', StockValuationReportService::BANNER)
            ->where('report.filters.branch', (string) $f['visit']->branch_id)
            // 18 on hand: 10 with no cost (named, never valued), 8 at 100 sen.
            ->where('report.summary.stockValueSen', 800)
            ->where('report.summary.uncostedSkus', 1)
            ->where('report.stock.0.quantity', '18')
            ->where('report.stock.0.uncostedQuantity', '10')
            ->where('report.stock.0.averageMilliSen', 100_000)
            // Dispensed 2 at the average (costed stock first); sold 2 at 250 sen.
            ->where('report.summary.dispenseCostSen', 200)
            ->where('report.summary.cogsSen', 200)
            ->where('report.summary.salesSen', 500)
            ->where('report.summary.marginSen', 300)
            ->where('report.summary.marginPercent', 60)
            ->where('report.medicines.0.medicine', 'Synthetic OTC medicine')
            ->where('report.medicines.0.salesSen', 500)
            ->where('report.medicines.0.costSen', 200)
            ->where('report.medicines.0.marginSen', 300)
            ->where('report.medicines.0.reason', null)
            ->where('report.summary.lossesSen', 0)
            ->has('report.losses.rows', 5));

        $this->assertSame($before, $snapshot());
        $this->assertSame($invoices, DB::table('invoices')->orderBy('id')->get()->toJson().DB::table('invoice_lines')->orderBy('id')->get()->toJson());
        $this->assertSame($audits + 1, DB::table('audit_logs')->where('event', 'inventory.valuation.viewed')->count());
        $metadata = json_decode((string) DB::table('audit_logs')->where('event', 'inventory.valuation.viewed')->latest('id')->value('metadata'), true);
        $this->assertSame(['branch_id', 'from', 'scope', 'to'], collect($metadata)->keys()->sort()->values()->all());
    }

    public function test_dispensing_stock_with_no_cost_shows_a_reason_never_a_zero_margin(): void
    {
        $f = $this->fixture();
        $this->price($f, 250);
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '2.000'));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $invoice = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
        app(BillingBuilderService::class)->finalize($f['ca'], $f['visit']->refresh(), $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);
        $director = $this->actor('director');
        $this->selectBranch($director, $f['visit']->branch);

        $this->get(route('inventory.stock-value'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.medicines.0.salesSen', 500)
            ->where('report.medicines.0.marginSen', null)
            ->where('report.medicines.0.marginPercent', null)
            ->where('report.medicines.0.reason', 'Part of the stock dispensed had no cost recorded')
            ->where('report.summary.stockValueSen', 0)
            ->where('report.stock.0.uncostedQuantity', '8'));
    }

    public function test_a_period_over_92_days_a_future_date_or_an_unknown_branch_is_refused_with_its_reason(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $today = now($this->branch->timezone)->toDateString();
        $from = now($this->branch->timezone)->subDays(92)->toDateString();

        $this->get(route('inventory.stock-value', ['from' => $from, 'to' => $today]))->assertRedirect(route('inventory.stock-value'))->assertSessionHasErrors(['to' => 'Choose 92 days or fewer.']);
        $this->get(route('inventory.stock-value', ['to' => now($this->branch->timezone)->addDay()->toDateString()]))->assertSessionHasErrors(['to' => 'Choose a date up to today.']);
        $this->get(route('inventory.stock-value', ['branch' => '999999']))->assertSessionHasErrors(['branch' => 'Choose a branch, or All branches.']);
        $this->get(route('inventory.stock-value', ['from' => now($this->branch->timezone)->subDays(91)->toDateString(), 'to' => $today, 'branch' => 'all']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.filters.branch', 'all')->where('report.scopeLabel', 'All branches'));
    }

    /** @param array<string, mixed> $f */
    private function price(array $f, int $sen): void
    {
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic valuation prices', 'currency' => 'MYR'])->save();
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => 'medicine', 'code' => 'VAL-'.Str::upper(Str::random(5)), 'display_name' => 'Synthetic valuation charge', 'source_key' => 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'unit' => $f['medicine']->order_unit, 'is_active' => true])->save();
        (new PriceEntry)->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $sen, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
    }
}
