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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-1c (owner decision 2026-11-06): catalogue editors add other names to a medicine (brand names and common
 * misspellings), and the Dispensary allergy warning matches a recorded allergy against them too. Synthetic data only.
 */
class AllergyAliasesTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    private function allergy(int $patientId, User $by, string $allergen): PatientAllergyRecord
    {
        $profile = PatientAllergyProfile::query()->where('patient_id', $patientId)->first()
            ?? PatientAllergyProfile::factory()->create(['patient_id' => $patientId, 'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'reviewed_at' => now()->utc(), 'reviewed_by_user_id' => $by->id, 'updated_by_user_id' => $by->id]);

        return PatientAllergyRecord::factory()->create(['patient_allergy_profile_id' => $profile->id, 'allergen_text' => $allergen, 'recorded_by_user_id' => $by->id]);
    }

    public function test_only_catalogue_editors_set_other_names_which_are_cleaned_and_audited_by_count(): void
    {
        $f = $this->fixture();
        $service = app(MedicineAdministrationService::class);
        foreach (['ca', 'resident_doctor', 'finance_officer'] as $role) {
            try {
                $service->setAllergyAliases($this->actor($role), $f['medicine'], ['Synthobrand'], []);
                $this->fail($role.' must not set other names.');
            } catch (AuthorizationException) {
            }
        }

        $saved = $service->setAllergyAliases($this->actor('ca_supervisor'), $f['medicine'], ['  Synthobrand ', 'synthobrand', '', "Synth   o\tcillin"], []);

        $this->assertSame(['Synthobrand', 'Synth o cillin'], $saved->allergyAliasList());
        $this->assertSame([], $saved->allergyGroupList(), 'other names never touch the drug groups');
        $audit = AuditLog::query()->where('event', 'medicine_catalogue.allergy_aliases_changed')->sole();
        $this->assertSame(['medicine_public_id', 'changed_fields', 'alias_count'], array_keys($audit->metadata));
        $this->assertSame(2, $audit->metadata['alias_count']);
        $this->assertStringNotContainsString('Synthobrand', json_encode($audit->metadata, JSON_THROW_ON_ERROR));

        $service->setAllergyAliases($this->actor('administrator'), $f['medicine'], ['Synthobrand', 'Synth o cillin'], $saved->allergyAliasList());
        $this->assertSame(1, AuditLog::query()->where('event', 'medicine_catalogue.allergy_aliases_changed')->count(), 'saving the same list is a no-op');
    }

    public function test_invalid_lists_and_a_change_made_meanwhile_are_refused(): void
    {
        $f = $this->fixture();
        $service = app(MedicineAdministrationService::class);
        $supervisor = $this->actor('ca_supervisor');
        foreach ([['ab'], [str_repeat('x', 61)], array_map(fn (int $i): string => 'Synthetic name '.$i, range(1, 11)), ["Bad\u{202E}name"], [12]] as $invalid) {
            try {
                $service->setAllergyAliases($supervisor, $f['medicine'], $invalid, []);
                $this->fail('Invalid other names must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('allergy_aliases', $e->errors());
            }
        }
        $service->setAllergyAliases($supervisor, $f['medicine'], ['Synthobrand'], []);

        try {
            $service->setAllergyAliases($this->actor('director'), $f['medicine'], ['Othersynth'], []);
            $this->fail('A list changed meanwhile must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['This medicine changed. Reload and review it again.'], $e->errors()['allergy_aliases']);
        }
        $this->assertSame(['Synthobrand'], $f['medicine']->refresh()->allergyAliasList());
    }

    public function test_the_database_refuses_a_malformed_list(): void
    {
        $f = $this->fixture();
        foreach (['\'{"a": 1}\'::jsonb', '\'[1]\'::jsonb', "(SELECT jsonb_agg('Synthetic name ' || g) FROM generate_series(1, 11) g)"] as $value) {
            try {
                DB::transaction(fn () => DB::statement('UPDATE medicine_catalogue_items SET allergy_aliases = '.$value.' WHERE id = ?', [$f['medicine']->id]));
                $this->fail('PostgreSQL must refuse '.$value);
            } catch (QueryException $e) {
                $this->assertStringContainsString('medicine_catalogue_allergy_aliases_shape', $e->getMessage());
            }
        }
    }

    public function test_an_allergy_to_a_brand_name_needs_the_confirmation_and_is_shown_before_saving(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthobrand (rash)');
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $item = DispensaryItem::query()->sole();
        $this->assertSame(0, DispensaryAllergyConfirmation::query()->count(), 'no other name yet, so no match');
        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->assertSame([], app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh())['items'][0]['allergyMatches']);

        app(MedicineAdministrationService::class)->setAllergyAliases($this->actor('ca_supervisor'), $f['medicine'], ['Synthobrand'], []);
        $this->selectBranch($f['ca'], $f['visit']->branch);

        $expected = [['publicId' => $record->public_id, 'allergen' => 'Synthobrand (rash)']];
        $this->assertSame($expected, app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh())['items'][0]['allergyMatches']);
        $this->assertSame($expected, app(DispensaryDirectoryService::class)->searchMedicines($f['ca'], $case, 'Synthetic OTC')[0]['allergyMatches']);

        $edit = fn (array $override = []): array => [...$this->addPayload($f, $case->refresh(), '1.000', $override), 'item_lock_version' => $item->refresh()->lock_version];
        try {
            app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $edit());
            $this->fail('A save matching another name must need the confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_confirmation', $e->errors());
        }
        app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $edit(['allergy_confirmed_record_ids' => [$record->public_id]]));
        $this->assertSame($record->id, DispensaryAllergyConfirmation::query()->sole()->patient_allergy_record_id);
    }

    public function test_adding_a_medicine_whose_misspelling_matches_needs_the_confirmation(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Allergic to synthocillin');
        app(MedicineAdministrationService::class)->setAllergyAliases($this->actor('ca_supervisor'), $f['medicine'], ['synthocillin'], []);
        $case = $this->open($f);

        try {
            app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
            $this->fail('Adding a medicine whose other name matches must need the confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_confirmation', $e->errors());
        }
        $this->assertSame(0, DispensaryItem::query()->count());
        app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case->refresh(), '1.000', ['allergy_confirmed_record_ids' => [$record->public_id]]));
        $this->assertSame($record->id, DispensaryAllergyConfirmation::query()->sole()->patient_allergy_record_id);
    }

    public function test_an_other_name_added_after_the_line_was_saved_blocks_complete_until_confirmed(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthobrand');
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '1.000'));
        $this->confirmAllergy($f, $case->refresh());
        $item = DispensaryItem::query()->sole();

        // A catalogue editor adds the brand name only after the CA saved the line.
        app(MedicineAdministrationService::class)->setAllergyAliases($this->actor('ca_supervisor'), $f['medicine'], ['Synthobrand'], []);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        try {
            $this->complete($f, $case);
            $this->fail('Complete must be refused until the new match is confirmed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_confirmation', $e->errors());
        }

        app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), [...$this->addPayload($f, $case->refresh(), '1.000', ['allergy_confirmed_record_ids' => [$record->public_id]]), 'item_lock_version' => $item->refresh()->lock_version]);
        $this->assertSame('completed', $this->complete($f, $case)->refresh()->status);
    }

    public function test_the_catalogue_route_saves_other_names_and_the_list_shows_them(): void
    {
        $f = $this->fixture();
        $this->actingAs($this->actor('director'));

        $this->post(route('medicines.allergy-aliases', $f['medicine']), ['allergy_aliases' => ['Synthobrand'], 'expected_allergy_aliases' => []])->assertRedirect(route('medicines.index'));
        $this->post(route('medicines.allergy-aliases', $f['medicine']), ['allergy_aliases' => ['Othersynth'], 'expected_allergy_aliases' => []])
            ->assertRedirect(route('medicines.index'))->assertSessionHasErrors('allergy_aliases');
        $this->assertSame(['Synthobrand'], $f['medicine']->refresh()->allergyAliasList());
        $this->get(route('medicines.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('medicines.data.0.allergyAliases', ['Synthobrand']));

        $this->actingAs($f['ca']);
        $this->post(route('medicines.allergy-aliases', $f['medicine']), ['allergy_aliases' => [], 'expected_allergy_aliases' => ['Synthobrand']])->assertForbidden();
        $this->assertSame(['Synthobrand'], $f['medicine']->refresh()->allergyAliasList());
    }
}
