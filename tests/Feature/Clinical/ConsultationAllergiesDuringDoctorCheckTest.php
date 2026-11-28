<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Services\PatientAllergyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DoctorCheckFixtures;

/**
 * DSP-30 (owner, 27 Nov 2026, option (a)): while the attending doctor's check of prepared medicine is waiting, the
 * consultation header shows the allergy list read only, instead of "Allergy information is unavailable", so it agrees
 * with the check panel's allergy line. Writes keep their own Serving check. Synthetic data only.
 */
class ConsultationAllergiesDuringDoctorCheckTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @param array<string, mixed> $f */
    private function sendForCheck(array $f): void
    {
        $this->switchOn($f['visit']->branch);
        $this->selectBranch($f['ca'], $f['visit']->branch);
        app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(),
        ]);
        $this->selectBranch($f['doctor'], $f['visit']->branch);
    }

    public function test_while_the_check_waits_the_header_shows_the_allergies_read_only(): void
    {
        $f = $this->prepared();
        $this->sendForCheck($f);
        // Hakim: one real record, so the read-only projection is shown to carry data. Fixture data written directly:
        // the allergy service refuses writes once the medicine has gone to Dispensary.
        $profile = PatientAllergyProfile::query()->where('patient_id', $f['visit']->patient_id)->sole();
        $profile->forceFill(['status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'lock_version' => $profile->lock_version + 1])->save();
        (new PatientAllergyRecord)->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $profile->organisation_id, 'patient_allergy_profile_id' => $profile->id,
            'allergen_text' => 'Synthetic allergen', 'category' => 'medication', 'reaction_text' => 'Synthetic rash', 'severity' => 'moderate',
            'status' => PatientAllergyRecord::STATUS_ACTIVE, 'recorded_at' => now()->utc(), 'recorded_by_user_id' => $f['doctor']->id,
            'updated_by_user_id' => $f['doctor']->id, 'entered_in_error_at' => null, 'entered_in_error_by_user_id' => null,
        ])->save();

        $this->actingAs($f['doctor'])->get(route('encounters.show', $f['visit']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Clinical/Show')
            ->whereNot('clinical.dispensaryCheck', null)
            ->where('clinical.allergies.status', 'has_allergies')
            ->where('clinical.allergies.canUpdate', false)
            ->where('clinical.allergies.canReview', false)
            ->where('clinical.allergies.readOnlyReason', 'doctor_check')
            ->has('clinical.allergies.records', 1)
            ->where('clinical.allergies.records.0.allergen', 'Synthetic allergen')
            ->where('clinical.allergies.records.0.reaction', 'Synthetic rash'));
    }

    public function test_the_read_only_list_still_refuses_a_write_from_the_consultation(): void
    {
        $f = $this->prepared();
        $this->sendForCheck($f);

        $this->expectException(AuthorizationException::class);
        app(PatientAllergyService::class)->add($f['doctor'], $f['visit']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'profile_lock_version' => null,
            'allergen_text' => 'Synthetic allergen', 'category' => 'medication', 'reaction_text' => null, 'severity' => null,
        ]);
    }

    public function test_without_a_waiting_check_the_header_is_unchanged(): void
    {
        $f = $this->prepared();
        $this->selectBranch($f['doctor'], $f['visit']->branch);

        // Sent to Dispensary but not for a check (the branch switch is off): no list, as before.
        $this->actingAs($f['doctor'])->get(route('encounters.show', $f['visit']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('clinical.dispensaryCheck', null)
            ->where('clinical.allergies', null));
    }

    public function test_another_doctor_never_gets_the_list(): void
    {
        $f = $this->prepared();
        $this->sendForCheck($f);
        $other = $this->doctor();
        $this->selectBranch($other, $f['visit']->branch);

        $response = $this->actingAs($other)->get(route('encounters.show', $f['visit']));
        $this->assertContains($response->status(), [403, 404]);
    }
}
