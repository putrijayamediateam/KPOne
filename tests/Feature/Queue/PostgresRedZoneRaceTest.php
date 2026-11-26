<?php

namespace Tests\Feature\Queue;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Feature\Clinical\Triage\TriageTestCase;
use Tests\Support\ContentionTimeouts;

/** Q-16b: two doctors press "I'll take it" on one red-zone patient at the same moment: one claim, one assignment, no deadlock. */
class PostgresRedZoneRaceTest extends TriageTestCase
{
    /** Committed synthetic fixtures must be visible to independent PostgreSQL workers. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_two_doctors_taking_one_red_patient_get_one_claim_and_one_assignment(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertMatchesRegularExpression('/(?:^|_)test(?:ing)?(?:_|$)/i', DB::getDatabaseName());
        Config::set('database.connections.red_observer', config('database.connections.'.config('database.default')));
        $observer = DB::connection('red_observer');
        $this->approvedGuideline();
        $ca = $this->actor('ca');
        $assigned = $this->doctor();
        $first = $this->doctor();
        $second = $this->doctor();
        $visit = $this->consultationVisit($ca, $assigned);
        $this->send($ca, $visit);
        $this->triage($ca, $visit->refresh(), ['chosen_zone' => 'red']);
        $workers = [];
        try {
            DB::beginTransaction();
            DB::table('users')->where('id', $assigned->id)->lockForUpdate()->first();
            $parent = (int) DB::scalar('select pg_backend_pid()');
            foreach ([$first, $second] as $actor) {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresRedZoneWorker.php'), (string) $actor->id, (string) $visit->id, (string) $this->branch->id, 'redzone-'.Str::random(12)], base_path());
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
            $ok = array_map(fn (array $w): bool => str_contains($w[0]->getOutput(), 'TAKE_OK'), $workers);
            $this->assertSame(1, count(array_filter($ok)), 'Exactly one doctor wins.');
            $winner = $ok[0] ? $first : $second;
            $this->assertSame(1, DB::table('queue_red_zone_claims')->count());
            $this->assertSame($winner->id, (int) DB::table('queue_red_zone_claims')->value('claimed_by_user_id'));
            $this->assertSame($winner->id, (int) DB::table('visits')->where('id', $visit->id)->value('assigned_doctor_user_id'));
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'queue.red_zone_taken')->count());
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
            DB::disconnect('red_observer');
        }
    }
}
