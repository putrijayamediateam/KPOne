<?php

namespace Tests\Feature\Queue;

use App\Domain\Queue\Readiness\CallHoldService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;

class PostgresVisitEditRaceTest extends QueueTestCase
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

    /** @return array<string, array{0: string}> */
    public static function rivals(): array
    {
        return ['call in' => ['call'], 'call next' => ['callnext'], 'not-ready flag' => ['set']];
    }

    #[DataProvider('rivals')]
    public function test_doctor_change_versus_a_rival_has_one_consistent_outcome_and_no_deadlock(string $rival): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName());
        Config::set('database.connections.edit_observer', config('database.connections.'.config('database.default')));
        $observer = DB::connection('edit_observer');
        $ca = $this->actor('ca');
        $doctor = $this->doctor();
        $other = $this->doctor();
        $visit = $this->consultationVisit($ca, $doctor);
        $queue = $this->send($ca, $visit);
        $reason = $this->visitReasonIds($visit)[0];
        $rivalActor = $rival === 'set' ? $ca : $doctor;
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $doctor->id)->lockForUpdate()->first();
            $parent = (int) DB::scalar('select pg_backend_pid()');
            foreach (['edit' => $ca, $rival => $rivalActor] as $action => $actor) {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresVisitEditWorker.php'), $action, (string) $actor->id, (string) $visit->id, (string) $this->branch->id, (string) $visit->lock_version, (string) $queue->lock_version, (string) $other->id, (string) $reason, 'visitedit-'.Str::random(12)], base_path());
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
            $editOk = str_contains($workers[0][0]->getOutput(), 'EDIT_OK');
            $rivalOk = str_contains($workers[1][0]->getOutput(), strtoupper($rival).'_OK');
            $visit->refresh();
            $queue->refresh();
            $active = DB::table('queue_call_holds')->where('queue_entry_id', $queue->id)->whereNull('closed_at')->exists();
            $calls = DB::table('queue_calls')->where('queue_entry_id', $queue->id)->count();
            $this->assertSame($editOk ? $other->id : $doctor->id, $visit->assigned_doctor_user_id);
            if ($rival === 'set') {
                // Both may win, in either order; the flag and the new doctor must both stand.
                $this->assertTrue($editOk || $rivalOk);
                $this->assertSame($rivalOk, $active);
                $this->assertSame('waiting', $queue->status);
                $this->assertSame(0, $calls);
            } else {
                // A call and a doctor change exclude each other: exactly one wins.
                $this->assertNotSame($editOk, $rivalOk);
                $this->assertSame($rivalOk ? 'serving' : 'waiting', $queue->status);
                $this->assertSame($rivalOk ? 1 : 0, $calls);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as [$process, $input]) {
                if (! $input->isClosed()) {
                    $input->close();
                }
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            DB::disconnect('edit_observer');
        }
    }
}
