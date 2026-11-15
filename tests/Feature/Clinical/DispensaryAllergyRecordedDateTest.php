<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use Carbon\CarbonImmutable;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-22 (approved for the owner by the coordinator, 2026-11-15): the Dispensary case sends when the patient's
 * allergy status was last set or reviewed, in the branch time zone, so the page can show "No known allergies,
 * recorded <date>". Read only: no permission, route or write. Synthetic data only.
 */
class DispensaryAllergyRecordedDateTest extends ClinicalTestCase
{
    use OtcDispensaryFixtures;

    public function test_the_case_sends_when_the_allergy_status_was_recorded_in_the_branch_time_zone(): void
    {
        $f = $this->fixture();
        // 18:30 UTC is 02:30 the next day in Kuala Lumpur: the date the CA sees must be the branch-local one.
        $recorded = CarbonImmutable::parse('2026-11-01 18:30:00', 'UTC');
        PatientAllergyProfile::factory()->create([
            'patient_id' => $f['visit']->patient_id, 'status' => PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES,
            'reviewed_at' => $recorded, 'reviewed_by_user_id' => $f['ca']->id, 'updated_by_user_id' => $f['ca']->id,
        ]);
        $case = $this->open($f);

        $safety = app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh())['allergySafety'];

        $this->assertSame(PatientAllergyProfile::STATUS_NO_KNOWN_ALLERGIES, $safety['status']);
        $this->assertSame(
            $recorded->setTimezone($f['visit']->branch->timezone)->toIso8601String(),
            $safety['reviewedAt'],
        );
        $this->assertStringStartsWith('2026-11-02T02:30:00', (string) $safety['reviewedAt']);
    }

    public function test_no_date_is_sent_when_nothing_was_recorded(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);

        $safety = app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh())['allergySafety'];

        $this->assertArrayHasKey('reviewedAt', $safety);
        $this->assertNull($safety['reviewedAt']);
    }
}
