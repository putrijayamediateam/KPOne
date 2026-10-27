<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Readiness\CallHoldService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;

class CallReadinessConcurrencyTest extends QueueTestCase
{
    /** Committed synthetic fixtures must be visible to independent PostgreSQL workers. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        // The following normal fixture test starts a fresh schema; immutable evidence is never deleted.
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_concurrent_set_or_clear_versus_call_has_one_consistent_winner_and_no_lost_audit(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName());
        Config::set('database.connections.readiness_observer', config('database.connections.'.config('database.default')));
        $observer = DB::connection('readiness_observer');
        foreach (['set', 'clear'] as $mode) {
            $ca = $this->actor('ca');
            $doctor = $this->doctor();
            $visit = $this->consultationVisit($ca, $doctor);
            $queue = $this->send($ca, $visit);
            $flag = $mode === 'clear' ? app(CallHoldService::class)->change($ca, $visit, ['expected_branch_id' => $this->branch->id, 'queue_lock_version' => $queue->lock_version, 'reason_code' => 'awaiting_panel_confirmation', 'idempotency_key' => (string) Str::uuid()], false) : null;
            $workers = [];
            try {
                DB::beginTransaction();
                DB::table('users')->where('id', $doctor->id)->lockForUpdate()->first();
                $parent = (int) DB::scalar('select pg_backend_pid()');
                foreach ([$mode => $ca, 'call' => $doctor] as $action => $actor) {
                    $input = new InputStream;
                    $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresReadinessWorker.php'), $action, (string) $actor->id, (string) $visit->id, (string) $this->branch->id, (string) $visit->lock_version, (string) $queue->lock_version, (string) ($flag['id'] ?? 0), (string) ($flag['lockVersion'] ?? 0), 'readiness-'.Str::random(12)], base_path());
                    $process->setInput($input);
                    $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
                    $process->start();
                    $workers[] = [$process, $input];
                }
                foreach ($workers as [$process, $input]) {
                    $readyDeadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
                    while (! str_contains($process->getOutput(), 'READY ') && microtime(true) < $readyDeadline) {
                        usleep(10_000);
                    }
                    $this->assertMatchesRegularExpression('/READY (\d+)/', $process->getOutput());
                    $input->write("GO\n");
                    $input->close();
                }
                foreach ($workers as [$process]) {
                    preg_match('/READY (\d+)/', $process->getOutput(), $pid);
                    $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
                    do {
                        $process->getOutput();
                        $blocked = (bool) $observer->scalar('WITH RECURSIVE blockers(pid) AS (SELECT unnest(pg_blocking_pids(?::integer)) UNION SELECT unnest(pg_blocking_pids(blockers.pid)) FROM blockers) SELECT EXISTS(SELECT 1 FROM blockers WHERE pid=?::integer)', [(int) $pid[1], $parent]);
                        if ($blocked) {
                            break;
                        }
                        usleep(10_000);
                    } while (microtime(true) < $deadline);
                    $this->assertTrue($blocked, $process->getOutput().$process->getErrorOutput());
                }
                DB::commit();
                foreach ($workers as [$process]) {
                    $process->wait();
                    $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                }
                $state = $queue->refresh()->status;
                $active = DB::table('queue_call_holds')->where('queue_entry_id', $queue->id)->whereNull('closed_at')->exists();
                $this->assertFalse($state === 'serving' && $active);
                if ($mode === 'set') {
                    $setWon = str_contains($workers[0][0]->getOutput(), 'SET_OK');
                    $this->assertSame($setWon, $active);
                    $this->assertSame($setWon ? 'waiting' : 'serving', $state);
                    $this->assertSame($setWon ? 1 : 0, DB::table('audit_logs')->where('event', 'queue.call_hold.set')->where('metadata->queue_entry_id', $queue->id)->count());
                } else {
                    $this->assertStringContainsString('CLEAR_OK', $workers[0][0]->getOutput());
                    $this->assertFalse($active);
                    $this->assertSame(1, DB::table('audit_logs')->where('event', 'queue.call_hold.cleared')->where('metadata->queue_entry_id', $queue->id)->count());
                }
                $this->assertSame($state === 'serving' ? 1 : 0, DB::table('queue_calls')->where('queue_entry_id', $queue->id)->count());
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                foreach ($workers as [$process, $input]) {
                    if (! $input->isClosed()) {
                        $input->close();
                    } if ($process->isRunning()) {
                        $process->stop(1);
                    }
                }
            }
        }
        DB::disconnect('readiness_observer');
    }
}
