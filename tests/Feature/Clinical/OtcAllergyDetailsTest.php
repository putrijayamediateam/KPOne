<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryAllergyConfirmation;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\ClinicalEncounterAllergyReview;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyProfileVersion;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Services\PatientAllergyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-5 (owner decisions 2026-11-05): for an OTC walk-in who has an allergy, the CA adds the allergy itself to the
 * patient's allergy profile (add only), so the DSP-1 warning can match it. Synthetic data only.
 */
class OtcAllergyDetailsTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function allergyPayload(array $f, DispensaryCase $case, array $override = []): array
    {
        $profile = PatientAllergyProfile::query()->where('patient_id', $f['visit']->patient_id)->first();

        return ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->refresh()->lock_version, 'profile_lock_version' => $profile?->lock_version,
            'allergen_text' => '  Synthetic OTC  ', 'category' => 'medication', 'reaction_text' => 'Synthetic rash', 'severity' => 'moderate', ...$override];
    }

    public function test_only_ca_and_ca_supervisor_hold_the_new_permission(): void
    {
        $holders = array_keys(array_filter(PermissionCatalogue::roles(), fn (array $permissions): bool => in_array('allergies.record_otc.branch', $permissions, true)));
        sort($holders);

        $this->assertSame(['ca', 'ca_supervisor'], $holders);
        $this->assertTrue($this->actor('ca')->can('allergies.record_otc.branch'));
        $this->assertFalse($this->actor('resident_doctor')->can('allergies.record_otc.branch'));
    }

    public function test_the_ca_adds_an_allergy_that_the_dispensary_warning_then_matches(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);

        app(DispensaryService::class)->addOtcAllergy($f['ca'], $case, $this->allergyPayload($f, $case));

        $profile = PatientAllergyProfile::query()->where('patient_id', $f['visit']->patient_id)->sole();
        $record = PatientAllergyRecord::query()->where('patient_allergy_profile_id', $profile->id)->sole();
        $this->assertSame(PatientAllergyProfile::STATUS_HAS_ALLERGIES, $profile->status);
        $this->assertSame(1, $profile->lock_version);
        $this->assertSame(['Synthetic OTC', 'medication', 'Synthetic rash', 'moderate', PatientAllergyRecord::STATUS_ACTIVE, $f['ca']->id],
            [$record->allergen_text, $record->category, $record->reaction_text, $record->severity, $record->status, $record->recorded_by_user_id]);
        $this->assertSame(1, PatientAllergyProfileVersion::query()->where('patient_allergy_profile_id', $profile->id)->where('version', 1)->count());
        $this->assertSame(0, ClinicalEncounterAllergyReview::query()->count(), 'an OTC add never counts as an encounter review');
        foreach (['allergy_record.created', 'allergy_profile.updated', 'dispensary.otc_allergy_added'] as $event) {
            $audit = AuditLog::query()->where('event', $event)->sole();
            $this->assertStringNotContainsString('Synthetic', (string) json_encode($audit->metadata), $event.' carries no allergy text');
        }

        // The DSP-1 warning now matches the new record: a matching medicine needs the CA's confirmation.
        try {
            app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case));
            $this->fail('A medicine matching the new allergy must need the confirmation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_confirmation', $e->errors());
        }
        app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case, '2.000', ['allergy_confirmed_record_ids' => [$record->public_id]]));
        $this->assertSame($record->id, DispensaryAllergyConfirmation::query()->sole()->patient_allergy_record_id);

        // A second add bumps the profile version again.
        app(DispensaryService::class)->addOtcAllergy($f['ca'], $case->refresh(), $this->allergyPayload($f, $case, ['allergen_text' => 'Synthetic food', 'category' => 'food']));
        $this->assertSame(2, $profile->refresh()->lock_version);
    }

    public function test_has_allergy_needs_an_active_record_and_none_is_unchanged(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        try {
            $this->confirmAllergy($f, $case, 'has_allergy');
            $this->fail('"Has an allergy" without a recorded allergy must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['Add the allergy before you confirm.'], $e->errors()['allergy_statement']);
        }
        $this->assertNull($case->handoffs()->sole()->otc_allergy_statement);

        $this->confirmAllergy($f, $case->refresh(), 'none');
        $this->assertSame('none', $case->handoffs()->sole()->otc_allergy_statement);
        $this->assertNull(PatientAllergyProfile::query()->where('patient_id', $f['visit']->patient_id)->first(), '"none" does not touch the allergy profile');

        app(DispensaryService::class)->addOtcAllergy($f['ca'], $case->refresh(), $this->allergyPayload($f, $case, ['allergen_text' => 'Synthetic latex', 'category' => null, 'reaction_text' => null, 'severity' => null]));
        $this->confirmAllergy($f, $case->refresh(), 'has_allergy');
        $this->assertSame('has_allergy', $case->handoffs()->sole()->otc_allergy_statement);
    }

    public function test_wrong_actors_cases_and_stale_or_invalid_input_are_refused(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $service = app(DispensaryService::class);

        foreach (['resident_doctor', 'finance_officer'] as $role) {
            try {
                $service->addOtcAllergy($this->actor($role), $case->refresh(), $this->allergyPayload($f, $case));
                $this->fail($role.' must not add an OTC allergy.');
            } catch (AuthorizationException) {
            }
        }
        $other = $this->actor('ca_supervisor');
        $this->selectBranch($other, $f['visit']->branch);
        try {
            $service->addOtcAllergy($other, $case->refresh(), $this->allergyPayload($f, $case));
            $this->fail('Only the CA handling the case may add the allergy.');
        } catch (AuthorizationException) {
        }
        foreach ([['allergen_text' => '   '], ['allergen_text' => str_repeat('x', 501)], ['severity' => 'extreme'], ['profile_lock_version' => 7]] as $override) {
            try {
                $service->addOtcAllergy($f['ca'], $case->refresh(), $this->allergyPayload($f, $case, $override));
                $this->fail('Invalid or stale input must be refused.');
            } catch (ValidationException) {
            }
        }
        try {
            $service->addOtcAllergy($f['ca'], $case->refresh(), [...$this->allergyPayload($f, $case), 'case_lock_version' => $case->lock_version - 1]);
            $this->fail('A stale case version must be refused.');
        } catch (ValidationException) {
        }
        $this->assertSame(0, PatientAllergyRecord::query()->count(), 'nothing was saved');

        try {
            app(PatientAllergyService::class)->addForOtcCase($other, $f['visit']->branch, $case->refresh(), $this->allergyPayload($f, $case));
            $this->fail('The allergy service itself refuses someone who is not handling the case.');
        } catch (AuthorizationException) {
        }
    }

    public function test_a_medicine_saved_before_the_allergy_needs_its_confirmation_before_complete(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $service = app(DispensaryService::class);
        $service->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $this->confirmAllergy($f, $case->refresh(), 'none');

        $service->addOtcAllergy($f['ca'], $case->refresh(), $this->allergyPayload($f, $case));
        $this->assertNull($case->handoffs()->sole()->otc_allergy_confirmed_at, 'a new allergy reopens the statement');
        try {
            $this->complete($f, $case);
            $this->fail('Complete needs the statement confirmed again.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_safety', $e->errors());
        }
        $this->confirmAllergy($f, $case->refresh(), 'has_allergy');
        $item = $case->handoffs()->sole()->items()->sole();
        $this->actingAs($f['ca']);
        $this->get(route('dispensary.show', $case))->assertInertia(fn (Assert $page) => $page->where('dispensary.items.0.allergyUnconfirmed', true));
        try {
            $this->complete($f, $case);
            $this->fail('A line matching an allergy recorded after it was saved must be confirmed first.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_confirmation', $e->errors());
        }

        $record = PatientAllergyRecord::query()->sole();
        $service->editItem($f['ca'], $case->refresh(), $item->refresh(), [...$this->addPayload($f, $case), 'item_lock_version' => $item->lock_version, 'allergy_confirmed_record_ids' => [$record->public_id]]);
        $this->get(route('dispensary.show', $case))->assertInertia(fn (Assert $page) => $page->where('dispensary.items.0.allergyUnconfirmed', false));
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $this->complete($f, $case)->status);
    }

    public function test_an_otc_add_keeps_who_reviewed_a_profile_that_already_lists_allergies(): void
    {
        $f = $this->fixture();
        $doctor = $this->actor('resident_doctor');
        $reviewedAt = now()->utc()->subDay()->startOfSecond();
        $profile = PatientAllergyProfile::factory()->create(['patient_id' => $f['visit']->patient_id, 'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'reviewed_at' => $reviewedAt, 'reviewed_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id]);
        PatientAllergyRecord::factory()->create(['patient_allergy_profile_id' => $profile->id, 'allergen_text' => 'Synthetic dust', 'recorded_by_user_id' => $doctor->id]);
        $case = $this->open($f);
        $version = $profile->refresh()->lock_version;

        app(DispensaryService::class)->addOtcAllergy($f['ca'], $case, $this->allergyPayload($f, $case));

        $profile->refresh();
        $this->assertSame($doctor->id, $profile->reviewed_by_user_id);
        $this->assertTrue($reviewedAt->equalTo($profile->reviewed_at));
        $this->assertSame($f['ca']->id, $profile->updated_by_user_id);
        $this->assertSame($version + 1, $profile->lock_version, 'the version still moves on, so an earlier encounter review is no longer current');
        $this->assertSame(2, PatientAllergyRecord::query()->where('status', PatientAllergyRecord::STATUS_ACTIVE)->count());
    }

    public function test_the_route_adds_the_allergy_and_the_page_offers_the_form_only_to_permitted_handlers(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $this->actingAs($f['ca']);

        $this->get(route('dispensary.show', $case))->assertInertia(fn (Assert $page) => $page->where('dispensary.can.addOtcAllergy', true));
        $this->post(route('dispensary.otc-allergy-records', $case), $this->allergyPayload($f, $case, ['allergen_text' => '']))
            ->assertRedirect(route('dispensary.show', $case))->assertSessionHasErrors('allergen_text');
        $this->post(route('dispensary.otc-allergy-records', $case), $this->allergyPayload($f, $case))->assertRedirect(route('dispensary.show', $case));
        $this->assertSame(1, PatientAllergyRecord::query()->count());
        $this->get(route('dispensary.show', $case))->assertInertia(fn (Assert $page) => $page->where('dispensary.allergySafety.allergies.0.allergen', 'Synthetic OTC'));

        $doctor = $this->actor('resident_doctor');
        $this->actingAs($doctor);
        $this->post(route('dispensary.otc-allergy-records', $case), $this->allergyPayload($f, $case))->assertForbidden();
        $this->assertSame(1, PatientAllergyRecord::query()->count());
    }
}
