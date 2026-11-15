<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * PRICE-01 (expanded scope): the same "required_if + format rule with no
 * nullable" defect that silently broke Clinical Service charge creation also
 * blocks Medicine and Consultation charge creation, because
 * Pricing/Index.vue's chargeForm always posts both medicine_public_id and
 * service_public_id regardless of the selected type, and the global
 * ConvertEmptyStringsToNull middleware turns the untouched one into null
 * before validation ever sees it. This file proves the symmetric cases, and
 * separately proves the Price Book scope-uniqueness failure is a real,
 * correctly-thrown error that was simply never rendered anywhere on the page.
 */
class PRICE01SilentCreateFailureTest extends ClinicalTestCase
{
    public function test_medicine_charge_creation_with_the_real_browser_payload_shape_succeeds(): void
    {
        $director = $this->actor('director');
        $medicine = app(MedicineAdministrationService::class)->create($director, [
            'code' => 'PRICE01-MED', 'display_name' => 'Price01 medicine',
            'strength_text' => null, 'dosage_form' => null, 'order_unit' => 'tablet',
        ]);
        $this->selectBranch($director);
        $this->actingAs($director);

        // Exactly what chargeForm sends today: both id fields present, only one relevant.
        $response = $this->from(route('pricing.index'))->post(route('pricing.charges.store'), [
            'type' => 'medicine',
            'medicine_public_id' => $medicine->public_id,
            'service_public_id' => '',
            'code' => 'PRICE01-MED-CHG',
            'display_name' => 'Price01 medicine charge',
        ]);

        $response->assertRedirect(route('pricing.index'));
        $response->assertSessionHasNoErrors();

        $charge = ChargeDefinition::query()->where('code', 'PRICE01-MED-CHG')->first();
        $this->assertNotNull($charge, 'No charge_definitions row was created for the medicine.');
        $this->assertSame('medicine:'.$medicine->id, $charge->source_key);

        $listing = $this->get(route('pricing.index'))->assertOk();
        $listing->assertInertia(fn ($page) => $page
            ->component('Pricing/Index')
            ->where('charges', fn ($charges) => collect($charges)->contains(fn ($c) => $c['code'] === 'PRICE01-MED-CHG')));
    }

    public function test_consultation_charge_creation_with_the_real_browser_payload_shape_succeeds(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);

        // Exactly what chargeForm sends today: both id fields present as empty
        // strings, since neither is relevant to type = consultation.
        $response = $this->from(route('pricing.index'))->post(route('pricing.charges.store'), [
            'type' => 'consultation',
            'medicine_public_id' => '',
            'service_public_id' => '',
            'code' => 'PRICE01-CONSULT-CHG',
            'display_name' => 'Price01 consultation charge',
        ]);

        $response->assertRedirect(route('pricing.index'));
        $response->assertSessionHasNoErrors();

        $charge = ChargeDefinition::query()->where('code', 'PRICE01-CONSULT-CHG')->first();
        $this->assertNotNull($charge, 'No charge_definitions row was created for the consultation charge.');
        $this->assertSame('consultation', $charge->source_key);

        $listing = $this->get(route('pricing.index'))->assertOk();
        $listing->assertInertia(fn ($page) => $page
            ->component('Pricing/Index')
            ->where('charges', fn ($charges) => collect($charges)->contains(fn ($c) => $c['code'] === 'PRICE01-CONSULT-CHG')));
    }

    public function test_organisation_wide_price_book_creation_with_the_real_browser_payload_shape_succeeds(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->actingAs($finance);

        // Exactly what bookForm sends for the Organisation-wide scope: branch_id is ''.
        $response = $this->post(route('pricing.price-books.store'), [
            'branch_id' => '',
            'name' => 'Price01 org-wide book',
            'currency' => 'MYR',
        ]);

        $response->assertRedirect(route('pricing.index'));
        $response->assertSessionHasNoErrors();

        $book = PriceBook::query()->where('name', 'Price01 org-wide book')->first();
        $this->assertNotNull($book, 'No price_books row was created for the organisation-wide scope.');
        $this->assertNull($book->branch_id);
        $this->assertSame('organisation', $book->scope_key);

        $listing = $this->get(route('pricing.index'))->assertOk();
        $listing->assertInertia(fn ($page) => $page
            ->component('Pricing/Index')
            ->where('priceBooks', fn ($books) => collect($books)->contains(fn ($b) => $b['name'] === 'Price01 org-wide book')));
    }

    public function test_branch_scoped_price_book_creation_with_the_real_browser_payload_shape_succeeds(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->actingAs($finance);

        $response = $this->post(route('pricing.price-books.store'), [
            'branch_id' => (string) $this->branch->id,
            'name' => 'Price01 branch book',
            'currency' => 'MYR',
        ]);

        $response->assertRedirect(route('pricing.index'));
        $response->assertSessionHasNoErrors();

        $book = PriceBook::query()->where('name', 'Price01 branch book')->first();
        $this->assertNotNull($book, 'No price_books row was created for the branch scope.');
        $this->assertSame($this->branch->id, $book->branch_id);

        $listing = $this->get(route('pricing.index'))->assertOk();
        $listing->assertInertia(fn ($page) => $page
            ->component('Pricing/Index')
            ->where('priceBooks', fn ($books) => collect($books)->contains(fn ($b) => $b['name'] === 'Price01 branch book')));
    }

    public function test_a_second_organisation_wide_price_book_is_rejected_with_a_real_scope_keyed_error(): void
    {
        // This is NOT the PRICE-01 defect: only one Price Book per scope is
        // intentional (PricingReferenceAdministrationService::createPriceBook()).
        // It reproduces exactly what the operator saw: a genuine, correctly
        // thrown ValidationException keyed on a synthetic 'scope' field that
        // Pricing/Index.vue never bound an <InputError> to, so it rendered
        // nowhere on the page even though the request legitimately failed.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->actingAs($finance);

        $this->from(route('pricing.index'))->post(route('pricing.price-books.store'), [
            'branch_id' => '', 'name' => 'First org-wide book', 'currency' => 'MYR',
        ])->assertRedirect(route('pricing.index'));

        $response = $this->from(route('pricing.index'))->post(route('pricing.price-books.store'), [
            'branch_id' => '', 'name' => 'Second org-wide book', 'currency' => 'MYR',
        ]);

        $response->assertRedirect(route('pricing.index'));
        $response->assertSessionHasErrors('scope');
        $this->assertNull(PriceBook::query()->where('name', 'Second org-wide book')->first());
    }
}
