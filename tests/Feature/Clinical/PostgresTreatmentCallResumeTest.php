<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Services\ConsultationHoldService;
use App\Domain\Queue\Display\QueueDisplayAdministrationService;
use App\Domain\Queue\Display\RoomCallService;
use App\Domain\Queue\Models\QueueCall;
use App\Domain\Visit\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;

class PostgresTreatmentCallResumeTest extends ClinicalTestCase
{
    /** Committed fixtures must be visible to the subprocess (existing readiness-race convention). */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_call_and_resume_serialize_in_both_lock_orders(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName());
        foreach (['resume', 'call'] as $first) {
            [$doctor, , $visit, $queue] = $this->servingFixture();
            $encounter = $this->startEncounter($doctor, $visit, $queue);
            $this->holdEncounter($doctor, $visit, $queue, $encounter);
            $ma = $this->actor('medical_assistant');
            $room = app(QueueDisplayAdministrationService::class)->createRoom($this->actor('director'), $this->branch, ['kind' => 'treatment', 'name' => 'Synthetic '.$first, 'sort_order' => 1]);
            $attributes = ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->refresh()->lock_version, 'queue_lock_version' => $queue->refresh()->lock_version, 'encounter_lock_version' => $encounter->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()];
            $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresTreatmentCallWorker.php'), base64_encode(json_encode([
                'operation' => $first === 'resume' ? 'call' : 'resume', 'actor' => $first === 'resume' ? $ma->id : $doctor->id,
                'visit' => $visit->id, 'room' => $room->id, 'attributes' => $attributes,
            ], JSON_THROW_ON_ERROR))], base_path(), timeout: ContentionTimeouts::processTimeoutSeconds());
            DB::beginTransaction();
            try {
                Visit::whereKey($visit->id)->lockForUpdate()->firstOrFail();
                $worker->start();
                $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
                $blocked = false;
                do {
                    if (preg_match('/READY (\d+)/', $worker->getOutput(), $matches)) {
                        $blocked = DB::scalar("select wait_event_type = 'Lock' from pg_stat_activity where pid = ?", [(int) $matches[1]]) === true;
                    }
                    if ($blocked || ! $worker->isRunning()) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                $this->assertTrue($blocked, 'Worker must reach the real Visit lock: '.$worker->getErrorOutput());
                $this->selectBranch($first === 'resume' ? $doctor : $ma, $this->branch);
                if ($first === 'resume') {
                    app(ConsultationHoldService::class)->resume($doctor, $visit, $attributes);
                } else {
                    app(RoomCallService::class)->callToTreatment($ma, $visit, $room->id, $attributes);
                }
                DB::commit();
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $this->assertStringContainsString($first === 'resume' ? 'STALE lock_version' : 'SUCCEEDED', $worker->getOutput());
                $this->assertFalse($encounter->activeHold()->exists());
                $this->assertSame($first === 'resume' ? 0 : 1, QueueCall::where('queue_entry_id', $queue->id)->where('service', 'treatment')->count());
                $this->assertSame($first === 'resume' ? 0 : 1, AuditLog::where('event', 'queue.treatment_called')->where('subject_id', $queue->id)->count());
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
