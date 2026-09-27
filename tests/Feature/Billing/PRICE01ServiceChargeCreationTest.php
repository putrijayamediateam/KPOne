<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * PRICE-01: reproduces the operator's exact action on /pricing - select Type =
 * Clinical Service, pick the only offered service, enter a code and display
 * name, click Create Charge Definition - through the real HTTP route, not the
 * domain service directly, and checks both that the row exists and that the
 * next page load actually shows it (the two things the operator reported were
 * both missing). This is the exact payload useForm() sends for chargeForm when
 * type === 'service': service_public_id is set, but medicine_public_id keeps
 * its initial empty-string value because Pricing/Index.vue never clears it
 * when the type switches away from 'medicine'.
 */
class PRICE01ServiceChargeCreationTest extends ClinicalTestCase
{
    public function test_creating_a_clinical_service_charge_through_the_real_http_route_persists_and_is_listed(): void
    {
        $director = $this->actor('director');
        $service = app(ClinicalServiceCatalogueAdministrationService::class)->create($director, [
            'code' => 'UAT-CONSULT-SVC', 'display_name' => 'Synthetic Consultation Service', 'order_unit' => 'session',
        ]);
        $this->selectBranch($director);
        $this->actingAs($director);

        $response = $this->from(route('pricing.index'))->post(route('pricing.charges.store'), [
            'type' => 'service',
            'medicine_public_id' => '',
            'service_public_id' => $service->public_id,
            'code' => 'UAT-CONSULT-SVC-CHG',
            'display_name' => 'UAT Consultation Service',
        ]);

        $response->assertRedirect(route('pricing.index'));
        $response->assertSessionHasNoErrors();

        $charge = ChargeDefinition::query()->where('code', 'UAT-CONSULT-SVC-CHG')->first();
        $this->assertNotNull($charge, 'No charge_definitions row was created for the service.');
        $this->assertSame('service:'.$service->id, $charge->source_key);
        $this->assertSame('session', $charge->unit);

        $listing = $this->get(route('pricing.index'))->assertOk();
        $listing->assertInertia(fn ($page) => $page
            ->component('Pricing/Index')
            ->where('charges', fn ($charges) => collect($charges)->contains(fn ($c) => $c['code'] === 'UAT-CONSULT-SVC-CHG')));
    }
}
