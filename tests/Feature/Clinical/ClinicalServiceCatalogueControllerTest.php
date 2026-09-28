<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;

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
            $this->post(route('clinical-services.store'), $this->attributes())->assertForbidden();
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

    public function test_a_foreign_organisation_service_is_not_found(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);

        $foreignOrganisationDirector = $this->foreignOrganisationDirector();
        $foreignService = app(ClinicalServiceCatalogueAdministrationService::class)->create($foreignOrganisationDirector, $this->attributes(['code' => 'FOREIGN-HTTP']));

        $this->patch(route('clinical-services.update', $foreignService), ['display_name' => 'Cross tenant'])->assertNotFound();
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
