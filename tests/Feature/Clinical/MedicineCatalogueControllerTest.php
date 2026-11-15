<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;

class MedicineCatalogueControllerTest extends ClinicalTestCase
{
    public function test_every_route_requires_the_organisation_permission(): void
    {
        $medicine = app(MedicineAdministrationService::class)->create($this->actor('director'), $this->attributes());

        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->actingAs($actor);

            $this->get(route('medicines.index'))->assertForbidden();
            $this->post(route('medicines.store'), $this->attributes())->assertForbidden();
            $this->patch(route('medicines.update', $medicine), ['display_name' => 'Denied update'])->assertForbidden();
            $this->post(route('medicines.deactivate', $medicine))->assertForbidden();
            $this->post(route('medicines.activate', $medicine))->assertForbidden();
        }

        $this->assertSame('Synthetic governed medicine', $medicine->refresh()->display_name);
        $this->assertTrue($medicine->is_active);
    }

    public function test_happy_path_lists_creates_updates_and_toggles_a_medicine(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);

        $this->get(route('medicines.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Medicine/Index'));

        $this->post(route('medicines.store'), $this->attributes(['code' => 'HTTP-MED']))
            ->assertRedirect(route('medicines.index'));
        $medicine = MedicineCatalogueItem::query()->where('code', 'HTTP-MED')->sole();
        $this->assertSame($director->organisation_id, $medicine->organisation_id);

        $this->patch(route('medicines.update', $medicine), ['display_name' => 'HTTP updated medicine'])
            ->assertRedirect(route('medicines.index'));
        $this->assertSame('HTTP updated medicine', $medicine->refresh()->display_name);

        $this->post(route('medicines.deactivate', $medicine))->assertRedirect(route('medicines.index'));
        $this->assertFalse($medicine->refresh()->is_active);

        $this->post(route('medicines.activate', $medicine))->assertRedirect(route('medicines.index'));
        $this->assertTrue($medicine->refresh()->is_active);
    }

    public function test_a_foreign_organisation_medicine_is_not_found(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director);

        $foreignOrganisationDirector = $this->foreignOrganisationDirector();
        $foreignMedicine = app(MedicineAdministrationService::class)->create($foreignOrganisationDirector, $this->attributes(['code' => 'FOREIGN-HTTP']));

        $this->patch(route('medicines.update', $foreignMedicine), ['display_name' => 'Cross tenant'])->assertNotFound();
    }

    /** @return array<string, mixed> */
    private function attributes(array $overrides = []): array
    {
        return [
            'code' => 'MED-'.strtoupper(bin2hex(random_bytes(4))),
            'display_name' => 'Synthetic governed medicine',
            'strength_text' => null,
            'dosage_form' => null,
            'order_unit' => 'unit',
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
