<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Treatment\TreatmentStep;
use App\Domain\Queue\Treatment\TreatmentStepProjection;
use App\Domain\Queue\Treatment\TreatmentStepService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Q-2c (owner decision 2026-11-06): a finished step keeps waiting for the doctor, the next step can start beside
 * it, and the doctor's Resume or opening the consultation clears every waiting step together.
 */
class TreatmentProgressNextStepTest extends ClinicalTestCase
{
    private function service(): TreatmentStepService
    {
        return app(TreatmentStepService::class);
    }

    private function start(User $actor, Visit $visit, string $code): TreatmentStep
    {
        $this->selectBranch($actor);
        $result = $this->service()->start($actor, $visit, ['expected_branch_id' => $this->branch->id, 'step_code' => $code, 'idempotency_key' => (string) Str::uuid()]);

        return TreatmentStep::query()->findOrFail($result['id']);
    }

    private function complete(User $actor, Visit $visit, TreatmentStep $step): void
    {
        $step->refresh();
        $this->selectBranch($actor);
        $this->service()->complete($actor, $visit, ['expected_branch_id' => $this->branch->id, 'treatment_step_id' => $step->id, 'lock_version' => $step->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @return array<string, mixed>|null */
    private function boardRow(User $actor, Visit $visit): ?array
    {
        $this->selectBranch($actor);

        /** @var list<array<string, mixed>> $serving */
        $serving = app(QueueDirectoryService::class)->snapshot($actor)['serving'];
        foreach ($serving as $row) {
            if ($row['visitNumber'] === $visit->visit_number) {
                return $row['treatmentProgress'];
            }
        }

        return null;
    }

    public function test_the_next_step_starts_while_a_finished_one_keeps_waiting_for_the_doctor(): void
    {
        [$doctor, $ca, $visit] = $this->servingFixture();
        $nebuliser = $this->start($ca, $visit, 'nebuliser');
        $this->complete($ca, $visit, $nebuliser);

        // Before Q-2c this was refused with "already has an open treatment step".
        $ecg = $this->start($ca, $visit, 'ecg');
        $this->assertSame('started', $ecg->state);
        $this->assertSame('done', $nebuliser->refresh()->state);
        $this->assertNull($nebuliser->closed_at);

        // The board shows the step in progress, with the finished one still waiting.
        $row = $this->boardRow($ca, $visit);
        $this->assertSame($ecg->id, $row['id']);
        $this->assertSame('started', $row['state']);
        $this->assertSame([['label' => 'Nebuliser', 'doneAt' => $nebuliser->done_at->toIso8601String()]], $row['waiting']);

        // The doctor's alert and count stay: one patient is ready to resume.
        $this->selectBranch($doctor);
        $this->assertSame(1, app(TreatmentStepProjection::class)->updates($doctor, $this->branch->id, null, false)['doneCount']);

        // Still only one step in progress at a time.
        try {
            $this->start($ca, $visit, 'iv_drip');
            $this->fail('A second step in progress must be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('already has an open treatment step', implode(' ', $exception->errors()['treatment'] ?? array_merge(...array_values($exception->errors()))));
        }
    }

    public function test_two_finished_steps_count_as_one_patient_and_the_latest_is_the_main_step(): void
    {
        [$doctor, $ca, $visit] = $this->servingFixture();
        $nebuliser = $this->start($ca, $visit, 'nebuliser');
        $this->complete($ca, $visit, $nebuliser);
        $this->travel(2)->minutes();
        $ecg = $this->start($ca, $visit, 'ecg');
        $this->complete($ca, $visit, $ecg);

        $row = $this->boardRow($ca, $visit);
        $this->assertSame($ecg->id, $row['id']);
        $this->assertSame('done', $row['state']);
        $this->assertSame(['Nebuliser'], array_column($row['waiting'], 'label'));

        $this->selectBranch($doctor);
        $updates = app(TreatmentStepProjection::class)->updates($doctor, $this->branch->id, 0, true);
        $this->assertSame(1, $updates['doneCount']);
        $this->assertCount(2, $updates['numbers'] ?? []);
    }

    public function test_resume_clears_every_waiting_step_and_keeps_a_step_in_progress(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->holdEncounter($doctor, $visit, $queue, $encounter);
        $first = $this->start($ca, $visit, 'nebuliser');
        $this->complete($ca, $visit, $first);
        $second = $this->start($ca, $visit, 'wound_dressing');
        $this->complete($ca, $visit, $second);
        $third = $this->start($ca, $visit, 'ecg');

        $this->selectBranch($doctor);
        app(ConsultationHoldService::class)->resume($doctor, $visit->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'visit_lock_version' => $visit->refresh()->lock_version,
            'queue_lock_version' => $queue->refresh()->lock_version,
            'encounter_lock_version' => $encounter->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->assertSame('acknowledged', $first->refresh()->state);
        $this->assertSame('acknowledged', $second->refresh()->state);
        $this->assertSame('started', $third->refresh()->state);
        $this->assertSame(0, app(TreatmentStepProjection::class)->updates($doctor, $this->branch->id, null, false)['doneCount']);
        $row = $this->boardRow($ca, $visit);
        $this->assertSame($third->id, $row['id']);
        $this->assertArrayNotHasKey('waiting', $row);
    }

    public function test_cancelling_a_waiting_finished_step_leaves_the_step_in_progress(): void
    {
        [, $ca, $visit] = $this->servingFixture();
        $nebuliser = $this->start($ca, $visit, 'nebuliser');
        $this->complete($ca, $visit, $nebuliser);
        $ecg = $this->start($ca, $visit, 'ecg');

        $nebuliser->refresh();
        $this->service()->cancel($ca, $visit, ['expected_branch_id' => $this->branch->id, 'treatment_step_id' => $nebuliser->id, 'lock_version' => $nebuliser->lock_version, 'reason_code' => 'marked_too_early', 'idempotency_key' => (string) Str::uuid()]);

        $this->assertSame('cancelled', $nebuliser->refresh()->state);
        $this->assertSame('started', $ecg->refresh()->state);
        $this->assertArrayNotHasKey('waiting', $this->boardRow($ca, $visit));
    }

    public function test_leaving_serving_closes_every_open_step(): void
    {
        [, $ca, $visit, $queue] = $this->servingFixture();
        $nebuliser = $this->start($ca, $visit, 'nebuliser');
        $this->complete($ca, $visit, $nebuliser);
        $ecg = $this->start($ca, $visit, 'ecg');

        $queue->refresh()->forceFill(['status' => QueueEntry::STATUS_REMOVED, 'removed_at' => now()->utc(), 'removal_reason' => 'cancelled', 'lock_version' => $queue->lock_version + 1])->save();

        $this->assertSame('system_closed', $nebuliser->refresh()->state);
        $this->assertSame('system_closed', $ecg->refresh()->state);
    }

    public function test_postgresql_allows_one_step_in_progress_and_several_waiting(): void
    {
        [, $ca, $visit] = $this->servingFixture();
        $nebuliser = $this->start($ca, $visit, 'nebuliser');
        $this->complete($ca, $visit, $nebuliser);
        $this->start($ca, $visit, 'ecg');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('treatment_step_one_started');
        DB::table('queue_treatment_steps')->insert([
            'organisation_id' => $nebuliser->organisation_id, 'branch_id' => $nebuliser->branch_id, 'queue_entry_id' => $nebuliser->queue_entry_id,
            'step_code' => 'iv_drip', 'state' => 'started', 'started_by_user_id' => $ca->id, 'started_at' => now()->utc(), 'lock_version' => 1,
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);
    }
}
