<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryAllergyConfirmation;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-1b (owner decision 2026-11-05): catalogue editors tag medicines with drug groups, and the
 * Dispensary allergy warning matches a recorded allergy against those groups too. Synthetic data only.
 */
class AllergyDrugGroupsTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    private function allergy(int $patientId, User $by, string $allergen): PatientAllergyRecord
    {
        $profile = PatientAllergyProfile::query()->where('patient_id', $patientId)->first()
            ?? PatientAllergyProfile::factory()->create(['patient_id' => $patientId, 'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'reviewed_at' => now()->utc(), 'reviewed_by_user_id' => $by->id, 'updated_by_user_id' => $by->id]);

        return PatientAllergyRecord::factory()->create(['patient_allergy_profile_id' => $profile->id, 'allergen_text' => $allergen, 'recorded_by_user_id' => $by->id]);
    }

    public function test_only_catalogue_editors_set_groups_which_are_cleaned_and_audited_by_count(): void
    {
        $f = $this->fixture();
        $service = app(MedicineAdministrationService::class);
        foreach (['ca', 'resident_doctor', 'finance_officer'] as $role) {
            try {
                $service->setAllergyGroups($this->actor($role), $f['medicine'], ['Penicillins'], []);
                $this->fail($role.' must not set drug groups.');
            } catch (AuthorizationException) {
            }
        }

        $saved = $service->setAllergyGroups($this->actor('ca_supervisor'), $f['medicine'], ['  Penicillins ', 'Beta-lactams', 'penicillins', '', "Beta   lactam\tantibiotics"], []);

        $this->assertSame(['Penicillins', 'Beta-lactams', 'Beta lactam antibiotics'], $saved->allergyGroupList());
        $audit = AuditLog::query()->where('event', 'medicine_catalogue.allergy_groups_changed')->sole();
        $this->assertSame(['medicine_public_id', 'changed_fields', 'group_count'], array_keys($audit->metadata));
        $this->assertSame(3, $audit->metadata['group_count']);

        $service->setAllergyGroups($this->actor('administrator'), $f['medicine'], ['Penicillins', 'Beta-lactams', 'Beta lactam antibiotics'], $saved->allergyGroupList());
        $this->assertSame(1, AuditLog::query()->where('event', 'medicine_catalogue.allergy_groups_changed')->count(), 'saving the same list is a no-op');
    }

    public function test_invalid_lists_and_a_change_made_meanwhile_are_refused(): void
    {
        $f = $this->fixture();
        $service = app(MedicineAdministrationService::class);
        $supervisor = $this->actor('ca_supervisor');
        foreach ([['ab'], [str_repeat('x', 61)], array_map(fn (int $i): string => 'Synthetic group '.$i, range(1, 11)), ["Bad\u{202E}group"]] as $invalid) {
            try {
                $service->setAllergyGroups($supervisor, $f['medicine'], $invalid, []);
                $this->fail('Invalid drug groups must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('allergy_groups', $e->errors());
            }
        }
        $service->setAllergyGroups($supervisor, $f['medicine'], ['Penicillins'], []);

        $this->expectException(ValidationException::class);
        $service->setAllergyGroups($this->actor('director'), $f['medicine'], ['NSAIDs'], []);
    }

    public function test_an_allergy_to_a_drug_group_needs_the_confirmation_and_is_shown_before_saving(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Allergic to penicillin');
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $item = DispensaryItem::query()->sole();
        $this->assertSame(0, DispensaryAllergyConfirmation::query()->count(), 'no group yet, so no match');

        app(MedicineAdministrationService::class)->setAllergyGroups($this->actor('ca_supervisor'), $f['medicine'], ['Penicillins'], []);
        $this->selectBranch($f['ca'], $f['visit']->branch);

        $expected = [['publicId' => $record->public_id, 'allergen' => 'Allergic to penicillin']];
        $this->assertSame($expected, app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh())['items'][0]['allergyMatches']);
        $this->assertSame($expected, app(DispensaryDirectoryService::class)->searchMedicines($f['ca'], $case, 'Synthetic OTC')[0]['allergyMatches']);

        $edit = fn (array $override = []): array => [...$this->addPayload($f, $case->refresh(), '1.000', $override), 'item_lock_version' => $item->refresh()->lock_version];
        try {
            app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $edit());
            $this->fail('A save matching a drug group must need the confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_confirmation', $e->errors());
        }
        app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $edit(['allergy_confirmed_record_ids' => [$record->public_id]]));
        $this->assertSame($record->id, DispensaryAllergyConfirmation::query()->sole()->patient_allergy_record_id);
    }

    public function test_the_catalogue_route_saves_groups_and_the_list_shows_them(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->actor('director'));

        $this->post(route('medicines.allergy-groups', $f['medicine']), ['allergy_groups' => ['NSAIDs'], 'expected_allergy_groups' => []])->assertRedirect(route('medicines.index'));
        $this->post(route('medicines.allergy-groups', $f['medicine']), ['allergy_groups' => ['Opioids'], 'expected_allergy_groups' => []])
            ->assertRedirect(route('medicines.index'))->assertSessionHasErrors('allergy_groups');
        $this->assertSame(['NSAIDs'], $f['medicine']->refresh()->allergyGroupList());
        $this->get(route('medicines.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('medicines.data.0.allergyGroups', ['NSAIDs']));

        $this->actingAs($f['ca']);
        $this->post(route('medicines.allergy-groups', $f['medicine']), ['allergy_groups' => [], 'expected_allergy_groups' => ['NSAIDs']])->assertForbidden();
        $this->assertSame(['NSAIDs'], $f['medicine']->refresh()->allergyGroupList());
    }
}
