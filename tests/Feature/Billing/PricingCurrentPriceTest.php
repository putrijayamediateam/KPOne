<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * CAT-6 (B.E.R.N.I.S.S for the owner, 2026-11-06): Pricing's "Current price" shows the version in effect now, while
 * the publish form keeps the newest version of that book as its expected version.
 */
class PricingCurrentPriceTest extends ClinicalTestCase
{
    private User $supervisor;

    private PricingReferenceAdministrationService $references;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($this->supervisor);
        $this->references = app(PricingReferenceAdministrationService::class);
    }

    private function charge(string $code): ChargeDefinition
    {
        $medicine = MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id, 'code' => $code, 'display_name' => 'Synthetic '.$code,
            'order_unit' => 'tablet', 'created_by_user_id' => $this->supervisor->id, 'updated_by_user_id' => $this->supervisor->id,
        ]);

        return $this->references->createMedicineCharge($this->supervisor, $medicine, ['code' => $code, 'display_name' => 'Synthetic '.$code]);
    }

    private function publish(PriceBook $book, ChargeDefinition $charge, int $sen): void
    {
        $version = (int) DB::table('price_entries')->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->max('version');
        app(PricePublicationService::class)->publish($this->supervisor, $book, $charge, $sen, $version, $this->branch->id);
        $this->travel(1)->minutes();
    }

    /** @return array<string, mixed> */
    private function row(string $code): array
    {
        $row = null;
        $this->actingAs($this->supervisor)->get(route('pricing.index'))->assertOk()
            ->assertInertia(function (Assert $page) use ($code, &$row): void {
                $row = collect($page->toArray()['props']['charges'])->firstWhere('code', $code);
            });

        return $row;
    }

    public function test_current_price_is_the_version_in_effect_and_the_publish_version_is_the_newest_in_that_book(): void
    {
        $book = $this->references->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay', 'price_tier' => 'self_pay']);
        $charge = $this->charge('CUR-1');
        $this->publish($book, $charge, 500);
        $this->publish($book, $charge, 600);
        // A version that takes effect tomorrow (prices published in the app take effect at once; this one is entered
        // directly, as data from elsewhere could be).
        PriceEntry::query()->forceCreate([
            'organisation_id' => $this->organisation->id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id,
            'unit_price_sen' => 900, 'version' => 3, 'effective_at' => now()->utc()->addDay(), 'published_by_user_id' => $this->supervisor->id,
        ]);

        $row = $this->row('CUR-1');

        $this->assertSame(600, $row['currentPrice']['amountSen']);
        $this->assertSame(3, $row['currentPrice']['version']);
        $this->assertSame($book->public_id, $row['currentPrice']['priceBookPublicId']);
        // Daniel: the scheduled version shows as a second line.
        $this->assertSame(900, $row['scheduledPrice']['amountSen']);
        $this->assertSame(now()->utc()->addDay()->toDateString(), substr($row['scheduledPrice']['effectiveAt'], 0, 10));
    }

    public function test_with_every_version_in_effect_nothing_changes_and_publishing_with_that_version_still_works(): void
    {
        $book = $this->references->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay', 'price_tier' => 'self_pay']);
        $charge = $this->charge('CUR-2');
        $this->publish($book, $charge, 700);
        $this->publish($book, $charge, 750);

        $row = $this->row('CUR-2');
        $this->assertSame([750, 2], [$row['currentPrice']['amountSen'], $row['currentPrice']['version']]);
        $this->assertNull($row['scheduledPrice']);

        app(PricePublicationService::class)->publish($this->supervisor, $book, $charge, 800, $row['currentPrice']['version'], $this->branch->id);
        $this->assertSame(800, $this->row('CUR-2')['currentPrice']['amountSen']);
    }

    public function test_a_charge_with_no_price_has_no_current_price(): void
    {
        $this->charge('CUR-3');

        $this->assertNull($this->row('CUR-3')['currentPrice']);
    }
}
