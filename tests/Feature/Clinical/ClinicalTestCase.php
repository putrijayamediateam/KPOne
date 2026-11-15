<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Queue\QueueTestCase;

abstract class ClinicalTestCase extends QueueTestCase
{
    /** @return array{User, User, Visit, QueueEntry} */
    protected function servingFixture(?User $doctor = null, ?User $ca = null): array
    {
        $doctor ??= $this->doctor();
        $ca ??= $this->actor('ca');
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $this->selectBranch($doctor, $visit->branch);
        $queue = app(QueueEntryService::class)->call($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
        ]);

        return [$doctor, $ca, $visit->refresh(), $queue->refresh()];
    }

    protected function startEncounter(User $doctor, Visit $visit, QueueEntry $queue): ClinicalEncounter
    {
        $this->selectBranch($doctor, $visit->branch);

        return app(ClinicalEncounterService::class)->start($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
        ]);
    }

    protected function holdEncounter(
        User $doctor,
        Visit $visit,
        QueueEntry $queue,
        ClinicalEncounter $encounter,
    ): void {
        $this->selectBranch($doctor, $visit->branch);
        app(ConsultationHoldService::class)->hold($doctor, $visit->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function aggregate(ClinicalEncounter $encounter, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $encounter->branch_id,
            'lock_version' => $encounter->lock_version,
            'clinical_note' => 'Synthetic clinical note',
            'vitals' => [
                'systolic_bp' => 120,
                'diastolic_bp' => 80,
                'pulse_bpm' => 72,
                'temperature_celsius' => 36.8,
                'spo2_percent' => 98,
                'weight_kg' => 60,
                'height_cm' => 160,
            ],
            'diagnoses' => [[
                'diagnosis_text' => 'Synthetic diagnosis alpha',
                'diagnosis_code' => null,
                'code_system' => null,
                'is_primary' => true,
            ]],
            ...$overrides,
        ];
    }
}
