<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Tests\Support\PanelPriceFixture;

class ClinicalServiceCatalogueControllerTest extends ClinicalTestCase
{
    public function test_every_route_requires_the_organisation_permission(): void
    {
        $service = app(ClinicalServiceCatalogueAdministrationService::class)->create($this->actor('director'), $this->attributes());

        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor);

            $this->get(route('clinical-services.index'))->assertForbidden();
            $this->get(route('clinical-services.edit-setup', $service))->assertForbidden();
            $this->post(route('clinical-services.store'), $this->attributes())->assertForbidden();
            $this->post(route('clinical-services.consultation-tariffs.store'), [])->assertForbidden();
            $this->patch(route('clinical-services.update', $service), ['display_name' => 'Denied update'])->assertForbidden();
            $this->post(route('clinical-services.deactivate', $service))->assertForbidden();
            $this->post(route('clinical-services.activate', $service))->assertForbidden();
        }

        $this->assertSame('Synthetic governed clinical service', $service->refresh()->display_name);
        $this->assertTrue($service->is_active);
    }

    public function test_happy_path_lists_creates_updates_and_toggles_a_clinical_service(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);

        $this->get(route('clinical-services.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('ClinicalService/Index'));

        $this->post(route('clinical-services.store'), $this->attributes(['code' => 'HTTP-SVC']))
            ->assertRedirect(route('clinical-services.index'));
        $service = ClinicalServiceCatalogueItem::query()->where('code', 'HTTP-SVC')->sole();
        $this->assertSame($director->organisation_id, $service->organisation_id);

        $this->patch(route('clinical-services.update', $service), ['display_name' => 'HTTP updated service'])
            ->assertRedirect(route('clinical-services.index'));
        $this->assertSame('HTTP updated service', $service->refresh()->display_name);

        $this->post(route('clinical-services.deactivate', $service))->assertRedirect(route('clinical-services.index'));
        $this->assertFalse($service->refresh()->is_active);

        $this->post(route('clinical-services.activate', $service))->assertRedirect(route('clinical-services.index'));
        $this->assertTrue($service->refresh()->is_active);
    }

    public function test_edit_setup_loads_current_prices_and_updates_them_as_new_versions(): void
    {
        $actor = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $this->actingAs($actor);
        $panel = Panel::factory()->create([
            'organisation_id' => $actor->organisation_id,
            'code' => 'EDIT-SERVICE-PANEL',
            'name' => 'Synthetic Edit Service Panel',
        ]);
        $this->post(route('clinical-services.store'), [
            ...$this->attributes(['code' => 'EDIT-PRICED-SVC']),
            'expected_branch_id' => $this->branch->id,
            'prices' => ['self_pay_sen' => 100],
        ])->assertRedirect(route('clinical-services.index'));
        $service = ClinicalServiceCatalogueItem::query()
            ->where('code', 'EDIT-PRICED-SVC')
            ->sole();
        // Panel prices are not saved from this form (owner decision 27): they are put in place the way an approved
        // proposal leaves them.
        $charge = ChargeDefinition::query()->where('clinical_service_catalogue_item_id', $service->id)->sole();
        $references = app(PricingReferenceAdministrationService::class);
        $defaultBook = $references->createPriceBook($actor, null, ['name' => 'Panel', 'price_tier' => 'panel', 'panel_id' => null]);
        $ownBook = $references->createPriceBook($actor, null, ['name' => 'Own', 'price_tier' => 'panel', 'panel_id' => $panel->id]);
        PanelPriceFixture::insert($defaultBook, $charge, 120, $actor);
        PanelPriceFixture::insert($ownBook, $charge, 110, $actor);

        $this->get(route('clinical-services.edit-setup', $service))
            ->assertOk()
            ->assertJsonPath('service.code', 'EDIT-PRICED-SVC')
            ->assertJsonPath('prices.self_pay_rm', '1.00')
            ->assertJsonPath('prices.panel_default_rm', '1.20')
            ->assertJsonPath('prices.panel_overrides.0.panel_id', (string) $panel->id)
            ->assertJsonPath('prices.panel_overrides.0.amount_rm', '1.10');

        $this->patch(route('clinical-services.update', $service), [
            'expected_branch_id' => $this->branch->id,
            'prices' => ['self_pay_sen' => 150],
        ])->assertRedirect(route('clinical-services.index'));
        $selfPayBook = PriceBook::query()->where('scope_key', 'organisation')->sole();
        $panelDefaultBook = PriceBook::query()->where('scope_key', 'panel:default')->sole();
        $overrideBook = PriceBook::query()->where('scope_key', 'panel:'.$panel->id)->sole();

        $this->assertDatabaseHas('price_entries', [
            'charge_definition_id' => $charge->id,
            'price_book_id' => $selfPayBook->id,
            'unit_price_sen' => 150,
            'version' => 2,
        ]);
        $this->assertDatabaseHas('price_entries', [
            'charge_definition_id' => $charge->id,
            'price_book_id' => $panelDefaultBook->id,
            'unit_price_sen' => 120,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('price_entries', [
            'charge_definition_id' => $charge->id,
            'price_book_id' => $overrideBook->id,
            'unit_price_sen' => 110,
            'version' => 1,
        ]);
        $this->assertSame(4, PriceEntry::query()->where('charge_definition_id', $charge->id)->count());

        // A save that carries a changed Panel price is refused whole; nothing is written.
        $this->patch(route('clinical-services.update', $service), [
            'expected_branch_id' => $this->branch->id,
            'prices' => ['self_pay_sen' => 160, 'panel_default_sen' => 125],
        ])->assertSessionHasErrors('prices');
        $this->assertSame(4, PriceEntry::query()->where('charge_definition_id', $charge->id)->count());

        $this->get(route('clinical-services.index'))->assertInertia(fn ($page) => $page
            ->where('services.data.0.selfPayAmountRm', '1.50')
            ->where('services.data.0.panelDefaultAmountRm', '1.20'));
    }

    public function test_a_foreign_organisation_service_is_not_found(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);

        $foreignOrganisationDirector = $this->foreignOrganisationDirector();
        $foreignService = app(ClinicalServiceCatalogueAdministrationService::class)->create($foreignOrganisationDirector, $this->attributes(['code' => 'FOREIGN-HTTP']));

        $this->patch(route('clinical-services.update', $foreignService), ['display_name' => 'Cross tenant'])->assertNotFound();
        $this->get(route('clinical-services.edit-setup', $foreignService))->assertNotFound();
    }

    public function test_consultation_tariff_is_managed_from_the_clinical_service_screen_and_billing_records(): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $panel = Panel::factory()->create([
            'organisation_id' => $supervisor->organisation_id,
            'code' => 'CONSULT-PANEL',
            'name' => 'Synthetic Consultation Panel',
        ]);
        $payload = [
            'code' => 'CONSULTATION',
            'display_name' => 'Consultation fee',
            'expected_branch_id' => $this->branch->id,
            'prices' => ['self_pay_sen' => 2500],
            'expected_versions' => ['self_pay' => 0, 'panel_default' => 0],
        ];

        $this->get(route('clinical-services.index'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ClinicalService/Index')
                ->where('consultationTariff.chargeExists', false)
                ->where('setup.canPublishPrices', true));

        $this->post(route('clinical-services.consultation-tariffs.store'), $payload)
            ->assertRedirect(route('clinical-services.index'));

        $charge = ChargeDefinition::query()->where('source_key', 'consultation')->sole();
        $selfPayBook = PriceBook::query()
            ->where('scope_key', 'organisation')->sole();
        // Panel prices are not saved from this form (owner decision 27): put in place as an approved proposal leaves them.
        $references = app(PricingReferenceAdministrationService::class);
        $panelBook = $references->createPriceBook($supervisor, null, ['name' => 'Panel', 'price_tier' => 'panel', 'panel_id' => null]);
        $overrideBook = $references->createPriceBook($supervisor, null, ['name' => 'Own', 'price_tier' => 'panel', 'panel_id' => $panel->id]);
        PanelPriceFixture::insert($panelBook, $charge, 2000, $supervisor);
        PanelPriceFixture::insert($overrideBook, $charge, 1800, $supervisor);
        $this->assertDatabaseHas('price_entries', [
            'charge_definition_id' => $charge->id,
            'price_book_id' => $selfPayBook->id,
            'unit_price_sen' => 2500,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('price_entries', [
            'charge_definition_id' => $charge->id,
            'price_book_id' => $panelBook->id,
            'unit_price_sen' => 2000,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('price_entries', [
            'charge_definition_id' => $charge->id,
            'price_book_id' => $overrideBook->id,
            'unit_price_sen' => 1800,
            'version' => 1,
        ]);
        $this->assertSame(3, PriceEntry::query()->where('charge_definition_id', $charge->id)->count());

        $payload['expected_versions'] = ['self_pay' => 1, 'panel_default' => 1];
        $this->post(route('clinical-services.consultation-tariffs.store'), $payload)
            ->assertRedirect(route('clinical-services.index'));
        $this->assertSame(3, PriceEntry::query()->where('charge_definition_id', $charge->id)->count());

        // A save that carries a changed Panel price is refused whole; nothing is written.
        $this->post(route('clinical-services.consultation-tariffs.store'), [
            ...$payload,
            'prices' => ['self_pay_sen' => 2500, 'panel_default_sen' => 2100],
            'expected_versions' => ['self_pay' => 1, 'panel_default' => 1],
        ])->assertSessionHasErrors('prices');
        $this->assertSame(3, PriceEntry::query()->where('charge_definition_id', $charge->id)->count());

        $this->get(route('clinical-services.index'))->assertInertia(fn ($page) => $page
            ->where('consultationTariff.selfPayAmountRm', '25.00')
            ->where('consultationTariff.panelDefaultAmountRm', '20.00')
            ->where('consultationTariff.panelOverrides.0.amountRm', '18.00'));
    }

    public function test_director_can_publish_consultation_tariff_with_ordinary_role_permissions(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);

        $this->post(route('clinical-services.consultation-tariffs.store'), [
            'code' => 'CONSULTATION',
            'display_name' => 'Consultation fee',
            'expected_branch_id' => $this->branch->id,
            'prices' => ['self_pay_sen' => 2500, 'panel_default_sen' => null, 'panel_overrides' => []],
            'expected_versions' => ['self_pay' => 0, 'panel_default' => 0, 'panel_overrides' => []],
        ])->assertRedirect(route('clinical-services.index'))->assertSessionHasNoErrors();

        $charge = ChargeDefinition::query()->where('organisation_id', $director->organisation_id)
            ->where('source_key', 'consultation')->sole();
        $book = PriceBook::query()->where('organisation_id', $director->organisation_id)
            ->where('scope_key', 'organisation')->where('price_tier', 'self_pay')->sole();
        $this->assertDatabaseHas('price_entries', [
            'charge_definition_id' => $charge->id,
            'price_book_id' => $book->id,
            'unit_price_sen' => 2500,
            'version' => 1,
        ]);
        $this->assertSame(1, PriceEntry::query()->where('charge_definition_id', $charge->id)->count());
    }

    public function test_consultation_tariff_publication_remains_separate_from_reference_management(): void
    {
        $director = $this->actor('director');
        $director->roles()->firstOrFail()->revokePermissionTo('prices.publish.organisation');
        $this->selectBranch($director);

        $this->post(route('clinical-services.consultation-tariffs.store'), [
            'code' => 'CONSULTATION',
            'display_name' => 'Consultation fee',
            'expected_branch_id' => $this->branch->id,
            'prices' => [
                'self_pay_sen' => 2500,
                'panel_default_sen' => null,
                'panel_overrides' => [],
            ],
            'expected_versions' => [
                'self_pay' => 0,
                'panel_default' => 0,
                'panel_overrides' => [],
            ],
        ])->assertForbidden();

        $this->assertDatabaseMissing('charge_definitions', ['source_key' => 'consultation']);
        $this->assertDatabaseCount('price_entries', 0);
    }

    /** @return array<string, mixed> */
    private function attributes(array $overrides = []): array
    {
        return [
            'code' => 'SVC-'.strtoupper(bin2hex(random_bytes(4))),
            'display_name' => 'Synthetic governed clinical service',
            'order_unit' => 'session',
            ...$overrides,
        ];
    }

    private function foreignOrganisationDirector(): User
    {
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'FOREIGN-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Synthetic Foreign Organisation',
            'is_active' => true,
        ])->save();
        $branch = new Branch;
        $branch->forceFill([
            'organisation_id' => $organisation->id,
            'code' => 'FOREIGN',
            'name' => 'Synthetic Foreign Branch',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();

        return $this->actor('director', $branch);
    }
}
