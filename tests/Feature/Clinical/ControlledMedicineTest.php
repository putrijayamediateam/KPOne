<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelLayout;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-3 (owner decision 2026-11-05): catalogue editors (medicines.manage.organisation) mark a medicine
 * as controlled; the drug label prints "Ubat Terkawal / Controlled Medicine"; every change is audited.
 * Synthetic data only.
 */
class ControlledMedicineTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_only_existing_catalogue_editors_can_set_the_flag_and_no_permission_is_added(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $permissions): bool => in_array('medicines.manage.organisation', $permissions, true))->keys()->sort()->values()->all();
        $this->assertSame(['administrator', 'ca_supervisor', 'director'], $holders);
        $this->assertNotContains('medicines.controlled.manage.organisation', PermissionCatalogue::all());

        $f = $this->fixture();
        foreach (['ca', 'resident_doctor', 'finance_officer', 'technical_admin'] as $role) {
            try {
                app(MedicineAdministrationService::class)->setControlled($this->actor($role), $f['medicine'], true, false);
                $this->fail($role.' must not set the controlled flag.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertFalse($f['medicine']->refresh()->is_controlled);
        $this->assertSame(0, AuditLog::query()->where('event', 'medicine_catalogue.controlled_changed')->count());
    }

    public function test_a_supervisor_marks_and_unmarks_and_each_change_is_audited_without_text(): void
    {
        $f = $this->fixture();
        $supervisor = $this->actor('ca_supervisor');
        $service = app(MedicineAdministrationService::class);

        $service->setControlled($supervisor, $f['medicine'], true, false);
        $this->assertTrue($f['medicine']->refresh()->is_controlled);
        $service->setControlled($supervisor, $f['medicine'], true, false);
        $this->assertSame(1, AuditLog::query()->where('event', 'medicine_catalogue.controlled_changed')->count(), 'repeating the same change is a no-op');
        $service->setControlled($supervisor, $f['medicine'], false, true);
        $this->assertFalse($f['medicine']->refresh()->is_controlled);

        $audits = AuditLog::query()->where('event', 'medicine_catalogue.controlled_changed')->orderBy('id')->get();
        $this->assertSame([true, false], $audits->map(fn (AuditLog $audit): bool => $audit->metadata['is_controlled'])->all());
        $this->assertSame(['medicine_public_id', 'changed_fields', 'is_controlled'], array_keys($audits[0]->metadata));
        $this->assertSame(['is_controlled'], $audits[0]->metadata['changed_fields']);
        $this->assertSame($supervisor->id, $audits[0]->actor_user_id);
        $this->assertStringNotContainsStringIgnoringCase('synthetic', json_encode($audits[0]->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_a_change_made_meanwhile_is_refused_instead_of_reversed(): void
    {
        $f = $this->fixture();
        $supervisor = $this->actor('ca_supervisor');
        app(MedicineAdministrationService::class)->setControlled($supervisor, $f['medicine'], true, false);

        // A second editor still looking at the unmarked state asks to "unmark" something they saw as unmarked.
        $this->expectException(ValidationException::class);
        app(MedicineAdministrationService::class)->setControlled($this->actor('director'), $f['medicine'], false, false);
    }

    public function test_the_catalogue_route_sets_the_flag_and_the_list_shows_it(): void
    {
        $f = $this->fixture();
        $director = $this->actor('director');
        $this->actingAs($director);

        $this->post(route('medicines.controlled', $f['medicine']), ['is_controlled' => true, 'expected_is_controlled' => false])->assertRedirect(route('medicines.index'));
        $this->post(route('medicines.controlled', $f['medicine']), ['is_controlled' => false, 'expected_is_controlled' => false])
            ->assertRedirect(route('medicines.index'))->assertSessionHasErrors('is_controlled');
        $this->assertTrue($f['medicine']->refresh()->is_controlled);
        $this->get(route('medicines.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Medicine/Index')->where('medicines.data.0.isControlled', true));

        $this->actingAs($f['ca']);
        $this->post(route('medicines.controlled', $f['medicine']), ['is_controlled' => false, 'expected_is_controlled' => true])->assertForbidden();
        $this->assertTrue($f['medicine']->refresh()->is_controlled);
    }

    public function test_the_label_prints_the_marker_under_the_name_in_bold_only_for_a_controlled_medicine(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case);
        $service = app(DrugLabelService::class);

        $plain = $service->preview($f['ca'], $case->refresh());
        $this->assertNotContains(DrugLabelLayout::CONTROLLED_MARKER, $plain['items'][0]['lines']);

        app(MedicineAdministrationService::class)->setControlled($this->actor('ca_supervisor'), $f['medicine'], true, false);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $marked = $service->preview($f['ca'], $case->refresh());
        $lines = $marked['items'][0]['lines'];
        $at = array_search(DrugLabelLayout::CONTROLLED_MARKER, $lines, true);
        $this->assertIsInt($at);
        $this->assertSame('Synthetic OTC medicine', $lines[$at - 1], 'the marker follows the medicine name');
        $this->assertContains($at, $marked['items'][0]['emphasis'], 'the marker is bold');
        $this->assertNotSame($plain['token'], $marked['token'], 'an open preview is retired when the flag changes');
        $this->assertSame(array_keys($plain['items'][0]), array_keys($marked['items'][0]), 'the label payload shape is unchanged');
    }
}
