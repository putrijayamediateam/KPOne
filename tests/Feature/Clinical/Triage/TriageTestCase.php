<?php

namespace Tests\Feature\Clinical\Triage;

use App\Domain\Clinical\Triage\TriageGuidelineService;
use App\Domain\Clinical\Triage\TriageGuidelineVersion;
use App\Domain\Clinical\Triage\TriageService;
use App\Domain\Clinical\Triage\VisitTriageAssessment;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Clinical\ClinicalTestCase;

/** TRI-1a fixtures. Synthetic data only; the signing doctor's name is fictional. */
abstract class TriageTestCase extends ClinicalTestCase
{
    protected const DOCTOR_NAME = 'Dr Synthetic Triage';

    /** @return array<string, mixed> */
    protected function guidelineInput(array $overrides = []): array
    {
        return [
            'child_age_limit_years' => 12,
            'approving_doctor_name' => self::DOCTOR_NAME,
            'doctor_signed_on' => now('Asia/Kuala_Lumpur')->subDay()->toDateString(),
            'red_flags' => ['Synthetic red flag one', 'Synthetic red flag two'],
            'yellow_signs' => ['Synthetic yellow sign one'],
            'thresholds' => [
                ['reading' => 'temperature_celsius', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '39.50', 'zone' => 'red'],
                ['reading' => 'temperature_celsius', 'age_band' => 'child', 'direction' => 'very_high', 'limit_value' => '38.50', 'zone' => 'red'],
                ['reading' => 'spo2_percent', 'age_band' => 'adult', 'direction' => 'very_low', 'limit_value' => '92', 'zone' => 'red'],
                ['reading' => 'pulse_bpm', 'age_band' => 'adult', 'direction' => 'very_high', 'limit_value' => '120', 'zone' => 'yellow'],
            ],
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    protected function enterGuideline(User $by, array $overrides = []): TriageGuidelineVersion
    {
        return app(TriageGuidelineService::class)->enter($by, $this->guidelineInput($overrides));
    }

    protected function approveGuideline(User $by, TriageGuidelineVersion $version): TriageGuidelineVersion
    {
        $version->refresh();

        return app(TriageGuidelineService::class)->approve($by, $version, [
            'lock_version' => $version->lock_version, 'matches_signed_copy' => true, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    protected function approvedGuideline(array $overrides = []): TriageGuidelineVersion
    {
        return $this->approveGuideline($this->actor('director'), $this->enterGuideline($this->actor('hr_manager'), $overrides));
    }

    /** @return array{User, Visit, QueueEntry} */
    protected function waitingFixture(?User $ca = null, array $patient = []): array
    {
        $ca ??= $this->actor('ca');
        $doctor = $this->doctor();
        $visit = $patient === []
            ? $this->consultationVisit($ca, $doctor)
            : $this->register($ca, $this->patient($ca, $patient), ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor->id, 'visit_reason' => 'Synthetic Queue reason']);
        $queue = $this->send($ca, $visit);

        return [$ca, $visit->refresh(), $queue->refresh()];
    }

    /** @return array<string, mixed> */
    protected function triageInput(Visit $visit, array $overrides = []): array
    {
        $visit->refresh();
        $queue = QueueEntry::query()->where('visit_id', $visit->id)->firstOrFail();
        $latest = VisitTriageAssessment::query()->where('visit_id', $visit->id)->orderByDesc('sequence')->value('id');
        $guideline = TriageGuidelineVersion::query()->where('organisation_id', $visit->organisation_id)->where('status', 'approved')->value('id');

        return [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
            'expected_latest_triage_id' => $latest,
            'expected_guideline_version_id' => $guideline,
            'systolic_bp' => 120, 'diastolic_bp' => 80, 'pulse_bpm' => 80, 'temperature_celsius' => '36.80',
            'spo2_percent' => '98', 'weight_kg' => '60', 'height_cm' => '160',
            'red_flag_codes' => [], 'yellow_sign_codes' => [],
            'chosen_zone' => $guideline ? 'green' : null,
            'override_reason_code' => null,
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }

    protected function triage(User $ca, Visit $visit, array $overrides = []): VisitTriageAssessment
    {
        $this->selectBranch($ca, $visit->branch);

        return app(TriageService::class)->record($ca, $visit, $this->triageInput($visit, $overrides));
    }

    protected function refused(callable $action, string $text): void
    {
        try {
            $action();
            $this->fail("Expected refusal: $text");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($text, implode(' ', array_merge(...array_values($e->errors()))));
        }
    }
}
