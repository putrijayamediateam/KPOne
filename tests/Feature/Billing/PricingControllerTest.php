<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use Tests\Feature\Clinical\ClinicalTestCase;

class PricingControllerTest extends ClinicalTestCase
{
    public function test_reference_routes_require_pricing_permission(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $charge = app(PricingReferenceAdministrationService::class)->createConsultationCharge($finance, [
            'code' => 'HTTP-DENY-CONSULT', 'display_name' => 'Synthetic denied consultation',
        ]);
        $book = app(PricingReferenceAdministrationService::class)->createPriceBook($finance, null, ['name' => 'Synthetic denied book']);

        foreach (['ca', 'resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor);

            $this->get(route('pricing.index'))->assertForbidden();
            $this->post(route('pricing.charges.store'), ['type' => 'consultation', 'code' => 'DENY-'.$role, 'display_name' => 'Denied'])->assertForbidden();
            $this->post(route('pricing.charges.activate', $charge))->assertForbidden();
            $this->post(route('pricing.charges.deactivate', $charge))->assertForbidden();
            $this->post(route('pricing.price-books.store'), ['name' => 'Denied book'])->assertForbidden();
            $this->post(route('pricing.price-books.activate', $book))->assertForbidden();
            $this->post(route('pricing.price-books.deactivate', $book))->assertForbidden();
        }

        // PX-01 (owner decision, 2026-09-27): ca_supervisor manages pricing references like finance_officer.
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->actingAs($supervisor);

        $this->get(route('pricing.index'))->assertOk();
        $service = app(ClinicalServiceCatalogueAdministrationService::class)->create($this->actor('director'), [
            'code' => 'SUP-REACH-SVC', 'display_name' => 'Supervisor reach service', 'order_unit' => 'session',
        ]);
        $this->selectBranch($supervisor);
        $this->post(route('pricing.charges.store'), ['type' => 'service', 'service_public_id' => $service->public_id, 'code' => 'SUP-REACH-CHARGE', 'display_name' => 'Supervisor reach'])->assertRedirect(route('pricing.index'));
        $this->assertDatabaseHas('charge_definitions', ['code' => 'SUP-REACH-CHARGE', 'type' => 'service']);
        $this->post(route('pricing.charges.deactivate', $charge))->assertRedirect(route('pricing.index'));
        $this->post(route('pricing.charges.activate', $charge))->assertRedirect(route('pricing.index'));
        $this->post(route('pricing.price-books.store'), ['branch_id' => $this->branch->id, 'name' => 'Supervisor reach book', 'currency' => 'MYR'])->assertRedirect(route('pricing.index'));
        $this->assertDatabaseHas('price_books', ['name' => 'Supervisor reach book', 'branch_id' => $this->branch->id]);
        $this->post(route('pricing.price-books.deactivate', $book))->assertRedirect(route('pricing.index'));
        $this->post(route('pricing.price-books.activate', $book))->assertRedirect(route('pricing.index'));
    }

    public function test_publish_requires_its_own_permission_separate_from_reference_management(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $charge = app(PricingReferenceAdministrationService::class)->createConsultationCharge($finance, [
            'code' => 'HTTP-PUBLISH-BOUNDARY', 'display_name' => 'Synthetic publish boundary',
        ]);
        $book = app(PricingReferenceAdministrationService::class)->createPriceBook($finance, null, ['name' => 'Synthetic publish book']);

        // Director holds pricing.references.manage.organisation (can reach the reference
        // screen) but not prices.publish.organisation (cannot publish a price) - these are
        // deliberately distinct permissions per the P0 phase design.
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);
        $this->get(route('pricing.index'))->assertOk();
        $this->post(route('pricing.charges.publish', $charge), [
            'price_book_public_id' => $book->public_id,
            'amount_sen' => 100,
            'expected_version' => 0,
            'expected_branch_id' => $this->branch->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('price_entries', ['charge_definition_id' => $charge->id]);
    }

    public function test_happy_path_creates_a_charge_a_price_book_and_publishes_a_price(): void
    {
        $director = $this->actor('director');
        $medicine = app(MedicineAdministrationService::class)->create($director, [
            'code' => 'HTTP-PRICE-MED', 'display_name' => 'Synthetic priced medicine',
            'strength_text' => null, 'dosage_form' => null, 'order_unit' => 'tablet',
        ]);

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->actingAs($finance);

        $this->get(route('pricing.index'))->assertOk()->assertInertia(fn ($page) => $page->component('Pricing/Index'));

        $this->post(route('pricing.charges.store'), [
            'type' => 'medicine', 'medicine_public_id' => $medicine->public_id,
            'code' => 'HTTP-PRICE-CHARGE', 'display_name' => 'Synthetic priced charge',
        ])->assertRedirect(route('pricing.index'));
        $charge = ChargeDefinition::query()->where('code', 'HTTP-PRICE-CHARGE')->sole();
        $this->assertSame('medicine:'.$medicine->id, $charge->source_key);

        $this->post(route('pricing.price-books.store'), ['name' => 'Synthetic HTTP price book', 'currency' => 'MYR'])
            ->assertRedirect(route('pricing.index'));
        $book = PriceBook::query()->where('name', 'Synthetic HTTP price book')->sole();

        $this->post(route('pricing.charges.publish', $charge), [
            'price_book_public_id' => $book->public_id,
            'amount_sen' => 250,
            'expected_version' => 0,
            'expected_branch_id' => $this->branch->id,
        ])->assertRedirect(route('pricing.index'));
        $this->assertDatabaseHas('price_entries', ['charge_definition_id' => $charge->id, 'unit_price_sen' => 250, 'version' => 1]);

        $this->post(route('pricing.charges.deactivate', $charge))->assertRedirect(route('pricing.index'));
        $this->assertFalse($charge->refresh()->is_active);
        $this->post(route('pricing.charges.activate', $charge))->assertRedirect(route('pricing.index'));
        $this->assertTrue($charge->refresh()->is_active);
    }
}
