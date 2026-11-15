<?php

namespace Tests\Feature\Insights;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Shared\Services\OtcProviderRow;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-4 (owner decisions 2026-11-05): the Provider · net invoice sales table on Insights Today and
 * Insights Sales gains one fixed "OTC (no doctor)" row, always last, only without a doctor filter.
 * Doctor rows and every other figure are unchanged. Synthetic data only.
 */
class OtcProviderRowTest extends BillingTestCase
{
    use OtcDispensaryFixtures;

    private function billedOtcInvoice(int $unitPriceSen = 123): Invoice
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);
        $this->complete($f, $case);
        $this->selectBranch($f['ca']);
        $charge = (new ChargeDefinition)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'type' => 'medicine', 'code' => 'SYN-OTC-'.Str::upper(Str::random(5)), 'display_name' => 'Synthetic OTC charge', 'source_key' => 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'unit' => 'unit']);
        $charge->save();
        (new PriceEntry)->forceFill(['organisation_id' => $this->organisation->id, 'price_book_id' => PriceBook::query()->firstOrFail()->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $unitPriceSen, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($f['ca'], $f['visit'], ['expected_branch_id' => $this->branch->id, 'lock_version' => null]);

        return $builder->finalize($f['ca'], $f['visit'], $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version]);
    }

    public function test_today_and_sales_tables_add_the_otc_row_last_and_rows_sum_to_the_headline_total(): void
    {
        [$doctor, , , $consultation] = $this->finalizedFixture(12345);
        $otc = $this->billedOtcInvoice();
        $this->assertNull($otc->consultation_checkout_id);
        $total = $consultation->total_sen + $otc->total_sen;
        $this->actingAs($this->actor('director'));
        $today = now()->setTimezone($this->branch->timezone)->toDateString();

        $this->get(route('insights.today'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.sales.totalSen', $total)
            ->has('report.rankings.providers', 2)
            ->where('report.rankings.providers.0.name', $doctor->name)
            ->where('report.rankings.providers.0.salesSen', $consultation->total_sen)
            ->where('report.rankings.providers.1', ['name' => OtcProviderRow::LABEL, 'salesSen' => $otc->total_sen, 'patients' => 1, 'fixed' => true]));

        $this->get(route('insights.report', ['section' => 'sales', 'from' => $today, 'to' => $today]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.report.summary.totalSen', $total)
            ->has('data.report.providers', 2)
            ->where('data.report.providers.0.name', $doctor->name)
            ->where('data.report.providers.1', ['name' => OtcProviderRow::LABEL, 'salesSen' => $otc->total_sen, 'patients' => 1, 'fixed' => true])
            ->where('data.report.rankingTruncated.providers', false));
    }

    public function test_the_otc_row_is_kept_last_even_when_it_outsells_a_doctor(): void
    {
        [$doctor] = $this->finalizedFixture(100);
        $otc = $this->billedOtcInvoice(90000);
        $this->actingAs($this->actor('director'));

        $this->get(route('insights.today'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.rankings.providers.0.name', $doctor->name)
            ->where('report.rankings.providers.1.name', OtcProviderRow::LABEL)
            ->where('report.rankings.providers.1.salesSen', $otc->total_sen));
    }

    public function test_a_doctor_filter_hides_the_otc_row_and_no_otc_sale_means_no_row(): void
    {
        [$doctor, , , $consultation] = $this->finalizedFixture(12345);
        $this->actingAs($this->actor('director'));
        $today = now()->setTimezone($this->branch->timezone)->toDateString();

        $this->get(route('insights.report', ['section' => 'sales', 'from' => $today, 'to' => $today]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('data.report.providers', 1)
            ->where('data.report.providers.0.name', $doctor->name));
        $this->get(route('insights.today'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.rankings.providers', 1));

        $this->billedOtcInvoice();
        $this->actingAs($this->actor('director'));
        $this->get(route('insights.report', ['section' => 'sales', 'from' => $today, 'to' => $today, 'doctor' => $doctor->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('data.report.providers', 1)
            ->where('data.report.providers.0.name', $doctor->name)
            ->where('data.report.providers.0.salesSen', $consultation->total_sen));
    }
}
