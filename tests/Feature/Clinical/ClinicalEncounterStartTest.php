<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\EncounterDiagnosis;
use App\Domain\Clinical\Models\EncounterVitalObservation;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Visit\Models\Visit;
use Illuminate\Validation\ValidationException;
use Tests\Support\StaffBranchAssignmentBootstrapper;

class ClinicalEncounterStartTest extends ClinicalTestCase
{
    public function test_assigned_doctor_starts_one_in_progress_encounter_idempotently(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $visitVersion = $visit->lock_version;
        $queueVersion = $queue->lock_version;

        $first = $this->startEncounter($doctor, $visit, $queue);
        $second = $this->startEncounter($doctor, $visit, $queue);

        $this->assertTrue($first->is($second));
        $this->assertSame(ClinicalEncounter::STATUS_IN_PROGRESS, $first->status);
        $this->assertSame($doctor->id, $first->attending_clinician_user_id);
        $this->assertDatabaseCount('clinical_encounters', 1);
        $this->assertDatabaseCount('encounter_vital_observations', 0);
        $this->assertDatabaseCount('encounter_diagnoses', 0);
        $this->assertSame($visitVersion, $visit->refresh()->lock_version);
        $this->assertSame($queueVersion, $queue->refresh()->lock_version);
        $this->assertSame(QueueEntry::STATUS_SERVING, $queue->status);
        $this->assertSame(1, AuditLog::query()->where('event', 'encounter.started')->count());
    }

    public function test_start_route_uses_visit_identity_and_rejects_system_owned_fields(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->selectBranch($doctor);

        $this->post(route('encounters.store', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->lock_version,
            'queue_lock_version' => $queue->lock_version,
            'organisation_id' => 999,
            'attending_clinician_user_id' => 999,
            'status' => 'finalized',
        ])->assertSessionHasErrors([
            'organisation_id', 'attending_clinician_user_id', 'status',
        ]);

        $this->assertDatabaseCount('clinical_encounters', 0);
    }

    public function test_temporary_branch_coverage_can_start_its_assigned_serving_consultation(): void
    {
        $home = Branch::query()
            ->where('organisation_id', $this->organisation->id)
            ->whereKeyNot($this->branch->id)
            ->firstOrFail();
        $doctor = $this->actor('resident_doctor', $home);
        StaffBranchAssignmentBootstrapper::create($doctor->staffProfile, $this->branch, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);
        [, , $visit, $queue] = $this->servingFixture($doctor);

        $encounter = $this->startEncounter($doctor, $visit, $queue);

        $this->assertSame($doctor->id, $encounter->attending_clinician_user_id);
        $this->assertSame($this->branch->id, $encounter->branch_id);
    }

    public function test_queue_marks_open_consultation_only_for_the_authorized_assigned_clinician(): void
    {
        [$doctor, $ca] = $this->servingFixture();

        $this->selectBranch($doctor);
        $doctorSnapshot = app(QueueDirectoryService::class)->snapshot($doctor);
        $this->assertTrue($doctorSnapshot['serving'][0]['canOpenEncounter']);

        $this->selectBranch($ca);
        $caSnapshot = app(QueueDirectoryService::class)->snapshot($ca);
        $this->assertFalse($caSnapshot['serving'][0]['canOpenEncounter']);
    }

    public function test_waiting_removed_otc_and_cancelled_visits_cannot_start(): void
    {
        foreach (['waiting', 'removed', 'otc', 'cancelled'] as $mode) {
            $doctor = $this->doctor();
            $ca = $this->actor('ca');
            $visit = $this->consultationVisit($ca, $doctor);
            $queue = $this->send($ca, $visit);
            if ($mode !== 'waiting') {
                $queue->forceFill([
                    'status' => $mode === 'removed' ? QueueEntry::STATUS_REMOVED : QueueEntry::STATUS_SERVING,
                    'called_at' => $mode === 'removed' ? null : now()->utc(),
                    'called_by_user_id' => $mode === 'removed' ? null : $doctor->id,
                    'removed_at' => $mode === 'removed' ? now()->utc() : null,
                    'lock_version' => $queue->lock_version + 1,
                ])->save();
            }
            if ($mode === 'otc') {
                $visit->forceFill(['visit_type' => 'otc'])->save();
            }
            if ($mode === 'cancelled') {
                $visit->forceFill([
                    'status' => Visit::STATUS_CANCELLED,
                    'cancelled_at' => now()->utc(),
                    'cancelled_by_user_id' => $ca->id,
                    'cancellation_reason' => 'Synthetic cancelled Visit fixture',
                    'updated_by_user_id' => $ca->id,
                    'lock_version' => $visit->lock_version + 1,
                ])->save();
            }
            $this->selectBranch($doctor);

            try {
                app(ClinicalEncounterService::class)->start($doctor, $visit, [
                    'expected_branch_id' => $visit->branch_id,
                    'visit_lock_version' => $visit->lock_version,
                    'queue_lock_version' => $queue->lock_version,
                ]);
                $this->fail("{$mode} unexpectedly started an Encounter.");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
            $this->assertDatabaseCount('clinical_encounters', 0);
        }
    }

    public function test_clinical_models_reject_mass_assignment(): void
    {
        foreach ([new ClinicalEncounter, new EncounterVitalObservation, new EncounterDiagnosis] as $model) {
            $this->assertSame([], $model->getFillable());
            $this->assertSame(['*'], $model->getGuarded());
        }
    }
}
