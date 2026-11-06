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

    private function future(PriceBook $book, ChargeDefinition $charge, int $sen, int $version, \DateTimeInterface $at): void
    {
        PriceEntry::query()->forceCreate([
            'organisation_id' => $this->organisation->id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id,
            'unit_price_sen' => $sen, 'version' => $version, 'effective_at' => $at, 'published_by_user_id' => $this->supervisor->id,
        ]);
    }

    public function test_current_price_is_the_version_in_effect_and_the_publish_version_is_the_newest_in_that_book(): void
    {
        $this->freezeTime();
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

    public function test_with_two_books_the_price_that_took_effect_last_is_current_whatever_the_version_numbers(): void
    {
        $this->freezeTime();
        $first = $this->references->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay', 'price_tier' => 'self_pay']);
        $second = $this->references->createPriceBook($this->supervisor, $this->branch, ['name' => 'Synthetic branch self-pay', 'price_tier' => 'self_pay']);
        $charge = $this->charge('CUR-4');
        $this->publish($first, $charge, 500);
        $this->publish($first, $charge, 550);
        $this->publish($second, $charge, 620);
        // The first book's v3 starts later; it must not pull the row back to that book.
        $this->future($first, $charge, 990, 3, now()->utc()->addDays(2));

        $row = $this->row('CUR-4');
        $this->assertSame([620, 1, $second->public_id], [$row['currentPrice']['amountSen'], $row['currentPrice']['version'], $row['currentPrice']['priceBookPublicId']]);
        $this->assertNull($row['scheduledPrice']);
    }

    public function test_a_charge_whose_only_prices_start_later_has_no_current_amount_and_the_nearest_one_is_scheduled(): void
    {
        $this->freezeTime();
        $book = $this->references->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay', 'price_tier' => 'self_pay']);
        $charge = $this->charge('CUR-5');
        $this->future($book, $charge, 700, 1, now()->utc()->addDay());
        $this->future($book, $charge, 800, 2, now()->utc()->addDays(5));

        $row = $this->row('CUR-5');
        $this->assertNull($row['currentPrice']['amountSen']);
        $this->assertSame(2, $row['currentPrice']['version']);
        $this->assertSame(700, $row['scheduledPrice']['amountSen']);
    }

    public function test_prices_in_an_inactive_book_are_left_out_as_billing_leaves_them_out(): void
    {
        $book = $this->references->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay', 'price_tier' => 'self_pay']);
        $charge = $this->charge('CUR-6');
        $this->publish($book, $charge, 500);
        $book->forceFill(['is_active' => false])->save();

        $this->assertNull($this->row('CUR-6')['currentPrice']);
    }

    public function test_the_scheduled_date_is_shown_in_the_active_branch_time_zone(): void
    {
        // 17:30 UTC is 01:30 the next day in Kuala Lumpur.
        $this->travelTo(now()->utc()->setTime(10, 0));
        $book = $this->references->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay', 'price_tier' => 'self_pay']);
        $charge = $this->charge('CUR-7');
        $this->publish($book, $charge, 500);
        $at = now()->utc()->addDay()->setTime(17, 30);
        $this->future($book, $charge, 600, 2, $at);

        $expected = $at->copy()->setTimezone($this->branch->timezone)->format('j M Y, g:i A');
        $this->assertSame($expected, $this->row('CUR-7')['scheduledPrice']['effectiveLabel']);
    }
}
