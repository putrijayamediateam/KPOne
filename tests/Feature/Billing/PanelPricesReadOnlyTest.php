<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelPriceLines;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;
use Tests\Support\PanelPriceFixture;

/**
 * Owner decision 27 (21 Nov 2026), slice 3: a Panel price changes only by proposal and approval, so direct publishing
 * is closed for every Panel price book. Self-pay prices keep direct publishing. Synthetic data only.
 */
class PanelPricesReadOnlyTest extends ClinicalTestCase
{
    private function charge(string $code): ChargeDefinition
    {
        $actor = $this->actor('ca_supervisor');
        $medicine = MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id, 'code' => $code, 'display_name' => 'Synthetic '.$code, 'order_unit' => 'tablet',
            'created_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id,
        ]);

        return app(PricingReferenceAdministrationService::class)->createMedicineCharge($actor, $medicine, ['code' => $code, 'display_name' => 'Synthetic '.$code]);
    }

    public function test_publishing_to_a_panel_book_is_refused_and_a_self_pay_book_still_publishes(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $references = app(PricingReferenceAdministrationService::class);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'RO-PNL', 'name' => 'Read-only Panel']);
        $panelBook = $references->createPriceBook($supervisor, null, ['name' => 'Read-only Panel book', 'price_tier' => 'panel', 'panel_id' => $panel->id]);
        $selfPayBook = $references->createPriceBook($supervisor, null, ['name' => 'Read-only Self-pay book']);
        $charge = $this->charge('RO-MED');
        $service = app(PricePublicationService::class);
        $audits = DB::table('audit_logs')->where('event', 'billing.price_published')->count();

        try {
            $service->publish($supervisor, $panelBook, $charge, 1500, 0, $this->branch->id);
            $this->fail('A Panel price must not publish directly.');
        } catch (ValidationException $e) {
            $this->assertSame([PricePublicationService::PANEL_BY_PROPOSAL_MESSAGE], $e->errors()['prices']);
        }
        $this->assertSame(0, PriceEntry::query()->where('price_book_id', $panelBook->id)->count());
        $this->assertSame($audits, DB::table('audit_logs')->where('event', 'billing.price_published')->count());

        $entry = $service->publish($supervisor, $selfPayBook, $charge, 900, 0, $this->branch->id);
        $this->assertSame(900, $entry->unit_price_sen);
    }

    public function test_a_catalogue_save_cannot_carry_a_panel_price_but_a_self_pay_price_saves(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $this->actingAs($actor);
        $base = [
            'display_name' => 'Synthetic read-only medicine', 'generic_name' => 'Synthetic ingredient', 'category' => 'Synthetic category', 'group_name' => 'Synthetic group',
            'strength_text' => '10 mg', 'dosage_form' => 'tablet', 'order_unit' => 'tablet', 'default_dosage_amount' => '1', 'default_dosage_unit' => 'tablet',
            'default_instruction' => 'After food', 'route' => 'oral', 'manufacturer' => 'Synthetic Manufacturer', 'expected_branch_id' => $this->branch->id,
        ];

        $this->post(route('medicines.store'), [...$base, 'code' => 'RO-WITH-PANEL', 'prices' => ['self_pay_sen' => 250, 'panel_default_sen' => 200]])
            ->assertSessionHasErrors('prices');
        $this->assertSame(0, MedicineCatalogueItem::query()->where('code', 'RO-WITH-PANEL')->count());
        $this->assertSame(0, PriceEntry::query()->count());

        $this->post(route('medicines.store'), [...$base, 'code' => 'RO-SELF-ONLY', 'prices' => ['self_pay_sen' => 250]])->assertRedirect(route('medicines.index'));
        $this->assertSame(1, PriceEntry::query()->count());
        $this->assertSame(0, PriceBook::query()->where('price_tier', 'panel')->count());
    }

    public function test_the_pricing_page_marks_panel_books_and_offers_the_propose_link_to_proposers_only(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'RO-PNL2', 'name' => 'Read-only Panel 2']);
        app(PricingReferenceAdministrationService::class)->createPriceBook($supervisor, null, ['name' => 'Read-only Panel 2 book', 'price_tier' => 'panel', 'panel_id' => $panel->id]);

        $this->actingAs($supervisor);
        $this->get(route('pricing.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('priceBooks', fn ($books) => collect($books)->firstWhere('name', 'Read-only Panel 2 book')['isPanel'] === true));

        $officer = $this->actor('panel_officer');
        $this->assertTrue($officer->can('panel.prices.propose.organisation'));
        $this->assertFalse($supervisor->can('panel.prices.propose.organisation'));
    }

    public function test_the_catalogue_payloads_list_every_panel_price_in_effect_and_a_later_one(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);
        $references = app(PricingReferenceAdministrationService::class);
        $charge = $this->charge('RO-LINES');
        $panelA = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'RO-A', 'name' => 'Alpha Panel']);
        $panelB = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'RO-B', 'name' => 'Beta Panel']);
        $default = $references->createPriceBook($supervisor, null, ['name' => 'Panel', 'price_tier' => 'panel', 'panel_id' => null]);
        $bookA = $references->createPriceBook($supervisor, null, ['name' => 'Alpha', 'price_tier' => 'panel', 'panel_id' => $panelA->id]);
        $bookB = $references->createPriceBook($supervisor, null, ['name' => 'Beta', 'price_tier' => 'panel', 'panel_id' => $panelB->id]);
        PanelPriceFixture::insert($default, $charge, 1800, $supervisor);
        PanelPriceFixture::insert($bookA, $charge, 2000, $supervisor);
        PanelPriceFixture::insert($bookB, $charge, 2100, $supervisor);
        // A later price for Beta: in effect only from next week, so it is a scheduled line, never the current one.
        $later = new PriceEntry;
        $later->forceFill(['organisation_id' => $this->organisation->id, 'price_book_id' => $bookB->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => 2500, 'version' => 2, 'effective_at' => now()->utc()->addWeek(), 'published_by_user_id' => $supervisor->id])->save();

        $lines = app(PanelPriceLines::class)->forCharge($this->organisation->id, $charge->id, $this->branch->timezone);
        $this->assertSame(['Default Panel price', 'Alpha Panel', 'Beta Panel'], array_column($lines, 'name'));
        $this->assertSame(['18.00', '20.00', '21.00'], array_column($lines, 'amountRm'));
        $this->assertNull($lines[0]['scheduled']);
        $this->assertSame('25.00', $lines[2]['scheduled']['amountRm']);

        $medicine = MedicineCatalogueItem::query()->where('code', 'RO-LINES')->sole();
        $this->getJson(route('medicines.setup', $medicine->public_id))->assertOk()
            ->assertJsonPath('panel_prices.2.name', 'Beta Panel')->assertJsonPath('panel_prices.2.amountRm', '21.00')
            ->assertJsonPath('panel_prices.2.scheduled.amountRm', '25.00');
    }

    public function test_the_pricing_page_never_offers_a_panel_price_as_the_current_price_to_repeat(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);
        $references = app(PricingReferenceAdministrationService::class);
        $charge = $this->charge('RO-CURRENT');
        $panelBook = $references->createPriceBook($supervisor, null, ['name' => 'Panel', 'price_tier' => 'panel', 'panel_id' => null]);
        PanelPriceFixture::insert($panelBook, $charge, 1800, $supervisor);

        $current = fn () => collect($this->get(route('pricing.index'))->viewData('page')['props']['charges'])->firstWhere('code', 'RO-CURRENT')['currentPrice'];
        $this->assertNull($current(), 'a charge with only a Panel price has no Self-pay current price');

        $selfPay = $references->createPriceBook($supervisor, null, ['name' => 'Self-pay']);
        app(PricePublicationService::class)->publish($supervisor, $selfPay, $charge, 900, 0, $this->branch->id);
        $this->assertSame(900, $current()['amountSen']);
        $this->assertSame($selfPay->public_id, $current()['priceBookPublicId']);
    }
}
