<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Models\Patient;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ContentionTimeouts;

/** Real actor/patient lock contention using the established READY/GO PostgreSQL observer harness.
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresEmergencyContactContentionTest extends VisitTestCase
{
    private const OBSERVER = 'pgsql_emergency_contact_observer';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL patient merge regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Patient merge concurrency tests require an isolated PostgreSQL test database.');
        }
        Config::set('database.connections.'.self::OBSERVER, config('database.connections.'.config('database.default')));
        DB::purge(self::OBSERVER);
    }

    protected function tearDown(): void
    {
        foreach ($this->inputs as $input) {
            if (! $input->isClosed()) {
                $input->close();
            }
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_two_first_creates_for_same_patient_serialize_and_only_one_succeeds(): void
    {
        $patient = $this->patient();
        $out = $this->race($patient, [
            $this->worker('save', $this->actor(), $this->payload($patient)),
            $this->worker('save', $this->actor('ca_supervisor'), $this->payload($patient)),
        ]);
        $this->assertSame(1, substr_count($out, 'SUCCESS 1'), $out);
        $this->assertStringContainsString('STALE emergency_contact_command.lock_version', $out);
        $this->assertSame(1, DB::table('patient_emergency_contacts')->count());
        $this->assertSame(1, DB::table('patient_emergency_contact_commands')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'patient.emergency_contact.updated')->count());
    }

    public function test_two_same_key_replays_serialize_and_record_one_contact_change(): void
    {
        $patient = $this->patient();
        $actor = $this->actor();
        $input = $this->payload($patient);
        $out = $this->race($patient, [$this->worker('save', $actor, $input), $this->worker('save', $actor, $input)]);
        $this->assertSame(2, substr_count($out, 'SUCCESS 1'), $out);
        $this->assertStringNotContainsString('STALE', $out);
        $this->assertSame(1, DB::table('patient_emergency_contacts')->value('lock_version'));
        $this->assertSame(1, DB::table('patient_emergency_contact_commands')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'patient.emergency_contact.updated')->count());
    }

    /** @return array<string,mixed> */
    private function payload(Patient $patient): array
    {
        return ['patient_id' => $patient->id, 'emergency_contact_command' => ['operation' => 'set', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'full_name' => 'Synthetic Race Contact', 'relationship' => 'Friend', 'phone' => '+60123456789']];
    }

    /** Makes the fixture visible to the worker processes; the next test rebuilds the schema. */
    private function commitFixture(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
            RefreshDatabaseState::$migrated = false;
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return Worker
     */
    private function worker(string $mode, User $actor, array $args): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresEmergencyContactWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-emergency-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the shared patient row the service locks, starts both workers, proves each one is waiting behind this
     * session (directly or behind the other worker), then releases the lock so they run strictly one after the other.
     *
     * @param  list<Worker>  $workers
     */
    private function race(Patient $shared, array $workers): string
    {
        $this->commitFixture();
        DB::beginTransaction();
        try {
            DB::select('select id from patients where id = ? for update', [$shared->id]);
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            foreach ($workers as $worker) {
                $worker['process']->start();
            }
            $this->waitReady($workers);
            foreach ($workers as $worker) {
                $worker['input']->write("GO\n");
                $worker['input']->close();
            }
            foreach ($workers as $worker) {
                $this->waitForDatabaseBlock($worker['process'], $parentPid);
            }
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
        foreach ($workers as $worker) {
            $worker['process']->wait();
            $this->assertSame(0, $worker['process']->getExitCode(), $this->processDiagnostics($worker['process']));
        }

        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    /** @param list<Worker> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated() && ! str_contains($worker['process']->getOutput(), 'READY ')) {
                    throw new RuntimeException('Emergency contact worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Emergency contact worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required emergency contact contention was not reached. '.$this->processDiagnostics($process));
            }
            $result = $this->observer()->selectOne(<<<'SQL'
                select exists (
                    with recursive blockers(pid) as (
                        select unnest(pg_blocking_pids(activity.pid))
                        union
                        select unnest(pg_blocking_pids(blockers.pid)) from blockers
                    ) select 1 from blockers where pid = ?
                ) as expected_blocker
                from pg_stat_activity as activity where pid = ?
                SQL, [$expectedBlockerPid, $pid]);
            if ($result && filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Expected emergency contact blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }
}
