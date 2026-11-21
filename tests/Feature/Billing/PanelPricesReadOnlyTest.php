<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

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
            ->where('canProposePanelPrices', false)
            ->where('priceBooks', fn ($books) => collect($books)->firstWhere('name', 'Read-only Panel 2 book')['isPanel'] === true));

        $officer = $this->actor('panel_officer');
        $this->assertTrue($officer->can('panel.prices.propose.organisation'));
        $this->assertFalse($supervisor->can('panel.prices.propose.organisation'));
    }
}
