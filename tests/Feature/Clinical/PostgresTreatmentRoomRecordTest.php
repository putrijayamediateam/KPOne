<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Clinical\Services\ConsultationLocks;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecord;
use App\Domain\Clinical\TreatmentRoom\Models\TreatmentRoomRecordVoid;
use App\Domain\Clinical\TreatmentRoom\TreatmentRoomService;
use App\Domain\Queue\Treatment\TreatmentStep;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;

class PostgresTreatmentRoomRecordTest extends ClinicalTestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_competing_outcomes_replay_and_correction_void_serialize_on_real_authority_locks(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringEndsWith('_test', DB::getDatabaseName());
        foreach (['duplicate', 'different_actor', 'correct_first', 'void_first', 'complete_first', 'record_first'] as $scenario) {
            [$doctor, , $visit, $queue] = $this->servingFixture();
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            $this->holdEncounter($doctor, $visit, $queue, $encounter);
            $ma = $this->actor('medical_assistant');
            $second = $scenario === 'different_actor' ? $this->actor('medical_assistant') : $ma;
            $this->selectBranch($ma);
            $base = ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version];
            $service = app(TreatmentRoomService::class);
            $started = $service->start($ma, $visit, [...$base, 'procedure_code' => 'urine_sample', 'idempotency_key' => (string) Str::uuid()]);
            $step = TreatmentStep::findOrFail($started['id']);
            $payload = [...$base, 'treatment_step_id' => $step->id, 'step_lock_version' => $step->lock_version, 'procedure_code' => 'urine_sample', 'outcome_code' => 'done', 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()];
            $completing = in_array($scenario, ['complete_first', 'record_first'], true);
            if ($completing) {
                $this->selectBranch($doctor);
                app(ConsultationHoldService::class)->resume($doctor, $visit, [...$base, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
                $base = [...$base, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version];
                $payload = [...$payload, ...$base];
                $this->selectBranch($ma);
            }
            $firstOperation = $workerOperation = 'recordOutcome';
            $firstPayload = $workerPayload = $payload;
            if ($scenario === 'different_actor') {
                $workerPayload['idempotency_key'] = (string) Str::uuid();
            }
            if (in_array($scenario, ['correct_first', 'void_first'], true)) {
                $record = $service->recordOutcome($ma, $visit, $payload);
                $correct = [...$base, 'target_record_id' => $record->id, 'correction_code' => 'wrong_outcome', 'procedure_code' => 'urine_sample', 'outcome_code' => 'not_successful', 'reason_code' => 'patient_declined', 'idempotency_key' => (string) Str::uuid()];
                $void = [...$base, 'target_record_id' => $record->id, 'reason_code' => 'wrong_patient', 'idempotency_key' => (string) Str::uuid()];
                $firstOperation = $scenario === 'correct_first' ? 'correctOutcome' : 'voidWrongPatient';
                $workerOperation = $scenario === 'correct_first' ? 'voidWrongPatient' : 'correctOutcome';
                $firstPayload = $scenario === 'correct_first' ? $correct : $void;
                $workerPayload = $scenario === 'correct_first' ? $void : $correct;
            }
            if ($completing) {
                $complete = [...$base, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'lock_version' => null, 'service_deliveries' => []];
                if ($scenario === 'complete_first') {
                    $firstOperation = 'complete';
                    $firstPayload = $complete;
                } else {
                    $workerOperation = 'complete';
                    $workerPayload = $complete;
                    $second = $doctor;
                }
            }
            $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresTreatmentRoomRecordWorker.php'), base64_encode(json_encode(['operation' => $workerOperation, 'actor' => $second->id, 'visit' => $visit->id, 'attributes' => $workerPayload], JSON_THROW_ON_ERROR))], base_path(), timeout: ContentionTimeouts::processTimeoutSeconds());
            DB::beginTransaction();
            try {
                ConsultationLocks::users($ma->organisation_id, [$ma->id, $second->id, $doctor->id]);
                $parent = (int) DB::scalar('select pg_backend_pid()');
                $worker->start();
                $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
                $blocked = false;
                do {
                    if (preg_match('/READY (\d+)/', $worker->getOutput(), $matches)) {
                        $blocked = (bool) DB::scalar('select ?::integer = any(pg_blocking_pids(?::integer))', [$parent, (int) $matches[1]]);
                    }
                    if ($blocked || ! $worker->isRunning()) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($blocked, 'Worker must block behind the actual authority lock: '.$worker->getErrorOutput());
                if ($firstOperation === 'complete') {
                    $this->selectBranch($doctor);
                    app(CompleteConsultationService::class)->complete($doctor, $visit, $firstPayload);
                } else {
                    $service->{$firstOperation}($ma, $visit, $firstPayload);
                }
                DB::commit();
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $this->assertStringContainsString(in_array($scenario, ['duplicate', 'record_first'], true) ? 'SUCCEEDED' : 'STALE', $worker->getOutput());
                $this->assertSame($scenario === 'complete_first' ? 0 : ($scenario === 'correct_first' ? 2 : 1), TreatmentRoomRecord::where('visit_id', $visit->id)->count());
                $this->assertSame($scenario === 'void_first' ? 1 : 0, TreatmentRoomRecordVoid::where('visit_id', $visit->id)->count());
                $this->assertSame($scenario === 'complete_first' ? 0 : 1, DB::table('audit_logs')->where('event', 'treatment_room.recorded')->where('metadata->visit_id', $visit->id)->count());
                $this->assertSame(! $completing, $encounter->activeHold()->exists());
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
