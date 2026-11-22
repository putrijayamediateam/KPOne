<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Q-12 (owner decisions 21 Nov 2026: 1A 2A 3A 4A): the doctor's own consultation carries the triage guideline's RED limits and
 * the patient's age band, for the "Check" line on vitals read from the note. No guideline in force means null. Reference limits
 * only; no signs, no new permission (the encounter is the doctor's own). Synthetic data only.
 */
class ConsultationVitalLimitsTest extends TriageTestCase
{
    /** @return array{doctor: User, visit: Visit, encounter: ClinicalEncounter} */
    private function consultation(): array
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);

        return ['doctor' => $doctor, 'visit' => $visit->refresh(), 'encounter' => $encounter];
    }

    /** @return array<string, mixed> */
    private function detail(User $doctor, Visit $visit): array
    {
        $this->selectBranch($doctor, $visit->branch);

        return app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit);
    }

    public function test_no_guideline_in_force_means_null_and_so_no_check_flag(): void
    {
        $c = $this->consultation();

        $this->assertNull($this->detail($c['doctor'], $c['visit'])['vitalLimits']);
    }

    public function test_the_payload_carries_only_the_red_limits_the_version_number_and_the_age_band(): void
    {
        $guideline = $this->approvedGuideline();
        $c = $this->consultation();
        DB::table('patients')->where('id', $c['visit']->patient_id)->update(['date_of_birth' => now()->subYears(30)->toDateString()]);

        $limits = $this->detail($c['doctor'], $c['visit'])['vitalLimits'];

        $this->assertSame(['versionNumber', 'ageBand', 'limits'], array_keys($limits));
        $this->assertSame($guideline->version_number, $limits['versionNumber']);
        $this->assertSame('adult', $limits['ageBand']);
        $this->assertCount(3, $limits['limits'], 'The yellow pulse limit is not sent: red only.');
        $this->assertSame(['red'], array_values(array_unique(array_column($limits['limits'], 'zone'))));
        foreach ($limits['limits'] as $limit) {
            $this->assertSame(['reading', 'age_band', 'direction', 'limit_value', 'zone'], array_keys($limit));
        }
        // no sign labels, no red flags, no names anywhere in the payload
        $json = json_encode($limits, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Synthetic red flag', $json);
        $this->assertStringNotContainsString('Synthetic yellow sign', $json);
        $this->assertStringNotContainsString(self::DOCTOR_NAME, $json);
    }

    public function test_the_age_band_follows_the_date_of_birth_and_is_unknown_without_one(): void
    {
        $this->approvedGuideline();
        $c = $this->consultation();

        DB::table('patients')->where('id', $c['visit']->patient_id)->update(['date_of_birth' => now()->subYears(5)->toDateString()]);
        $this->assertSame('child', $this->detail($c['doctor'], $c['visit'])['vitalLimits']['ageBand']);

        DB::table('patients')->where('id', $c['visit']->patient_id)->update(['date_of_birth' => null]);
        $this->assertSame('unknown', $this->detail($c['doctor'], $c['visit'])['vitalLimits']['ageBand']);
    }

    public function test_a_replaced_or_draft_version_is_never_used(): void
    {
        $first = $this->approvedGuideline();
        $c = $this->consultation();
        $this->assertSame($first->version_number, $this->detail($c['doctor'], $c['visit'])['vitalLimits']['versionNumber']);

        // a draft entered later is not in force
        $this->enterGuideline($this->actor('hr_manager'));
        $this->assertSame($first->version_number, $this->detail($c['doctor'], $c['visit'])['vitalLimits']['versionNumber']);
    }

    public function test_only_the_attending_doctor_gets_the_consultation_at_all(): void
    {
        $this->approvedGuideline();
        $c = $this->consultation();

        $this->expectException(ModelNotFoundException::class);
        $this->detail($this->doctor(), $c['visit']);
    }
}
