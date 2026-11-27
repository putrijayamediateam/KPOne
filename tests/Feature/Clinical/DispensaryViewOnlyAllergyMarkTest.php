<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DoctorCheckFixtures;

/**
 * DSP-29 part A (owner ship-it 27 Nov 2026 covers DSP-29): the Resident Doctor's view-only Dispensary case page gets the
 * same allergy matches the CA's warning uses, so the card can show the read-only mark. Read only. Synthetic data only.
 */
class DispensaryViewOnlyAllergyMarkTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    public function test_the_doctors_view_only_case_carries_the_line_allergy_matches(): void
    {
        $f = $this->prepared();
        // A synthetic allergy recorded while the case is with Dispensary (fixture data, written directly).
        $profile = PatientAllergyProfile::query()->where('patient_id', $f['visit']->patient_id)->sole();
        $profile->forceFill(['status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'lock_version' => $profile->lock_version + 1])->save();
        (new PatientAllergyRecord)->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $profile->organisation_id, 'patient_allergy_profile_id' => $profile->id,
            'allergen_text' => 'Synthetic medicine', 'category' => 'medication', 'reaction_text' => null, 'severity' => null,
            'status' => PatientAllergyRecord::STATUS_ACTIVE, 'recorded_at' => now()->utc(), 'recorded_by_user_id' => $f['doctor']->id,
            'updated_by_user_id' => $f['doctor']->id, 'entered_in_error_at' => null, 'entered_in_error_by_user_id' => null,
        ])->save();
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        $this->actingAs($f['doctor'])->get(route('dispensary.show', $f['case']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/Show')
            ->where('dispensary.viewOnly', true)
            ->where('dispensary.can.update', false)
            ->where('dispensary.items.0.allergyMatches.0.allergen', 'Synthetic medicine')
            // Hakim: names and ids only, never the reaction or severity.
            ->missing('dispensary.items.0.allergyMatches.0.reaction')
            ->missing('dispensary.items.0.allergyMatches.0.severity'));
    }
}
