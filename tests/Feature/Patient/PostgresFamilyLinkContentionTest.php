<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientFamilyLinkService;
use App\Domain\Patient\Services\PatientMergeService;
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
class PostgresFamilyLinkContentionTest extends VisitTestCase
{
    private const OBSERVER = 'pgsql_family_link_observer';

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

    public function test_two_first_links_serialize_and_only_one_succeeds(): void
    {
        $a = $this->patient();
        $b = $this->patient();
        $out = $this->race($a, [$this->worker('save', $this->actor(), $this->payload($a, $b)), $this->worker('save', $this->actor('ca_supervisor'), $this->payload($a, $b))]);
        $this->assertSame(1, substr_count($out, 'SUCCESS 1'), $out);
        $this->assertStringContainsString('STALE family_link', $out);
        $this->assertSame(1, DB::table('patient_family_links')->count());
        $this->assertSame(1, DB::table('patient_family_link_commands')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'patient.family_link.changed')->count());
    }

    public function test_reciprocal_first_links_use_same_ordered_pair_lock(): void
    {
        $a = $this->patient();
        $b = $this->patient();
        $out = $this->race($a, [$this->worker('save', $this->actor(), $this->payload($a, $b)), $this->worker('save', $this->actor('ca_supervisor'), $this->payload($b, $a, ['relationship_code' => 'child_of']))]);
        $this->assertSame(1, substr_count($out, 'SUCCESS 1'), $out);
        $this->assertStringContainsString('STALE family_link', $out);
        $this->assertSame(1, DB::table('patient_family_links')->count());
        $this->assertSame('parent_of', DB::table('patient_family_links')->value('relationship_code'));
    }

    public function test_exact_key_race_records_one_change(): void
    {
        $a = $this->patient();
        $b = $this->patient();
        $actor = $this->actor();
        $input = $this->payload($a, $b);
        $out = $this->race($a, [$this->worker('save', $actor, $input), $this->worker('save', $actor, $input)]);
        $this->assertSame(2, substr_count($out, 'SUCCESS 1'), $out);
        $this->assertStringNotContainsString('STALE', $out);
        $this->assertSame(1, DB::table('patient_family_links')->value('lock_version'));
        $this->assertSame(1, DB::table('patient_family_link_commands')->count());
    }

    public function test_correction_and_unlink_race_one_version_wins(): void
    {
        $a = $this->patient();
        $b = $this->patient();
        $actor = $this->actor();
        $this->selectBranch($actor);
        app(PatientFamilyLinkService::class)->execute($actor, $a, [...$this->payload($a, $b), 'expected_branch_id' => $this->branch->id]);
        $out = $this->race($a, [$this->worker('save', $actor, $this->payload($a, $b, ['operation' => 'correct', 'relationship_code' => 'sibling', 'lock_version' => 1])), $this->worker('save', $this->actor('ca_supervisor'), $this->payload($a, $b, ['operation' => 'unlink', 'lock_version' => 1]))]);
        $this->assertSame(1, substr_count($out, 'SUCCESS 2'), $out);
        $this->assertStringContainsString('STALE family_link', $out);
        $this->assertSame(2, DB::table('patient_family_links')->value('lock_version'));
        $this->assertSame(2, DB::table('patient_family_link_commands')->count());
    }

    public function test_merge_and_link_share_patient_locks_without_transfer(): void
    {
        $a = $this->patient();
        $b = $this->patient();
        $kept = $this->patient();
        $actor = $this->actor();
        $approver = $this->actor('ca_supervisor');
        $this->selectBranch($actor);
        $request = app(PatientMergeService::class)->propose($actor, $kept, ['kind' => 'merge', 'other_patient_number' => $b->patient_number, 'reason_code' => 'same_person_registered_twice', 'expected_branch_id' => $this->branch->id, 'kept_lock_version' => $kept->lock_version, 'other_lock_version' => $b->lock_version, 'idempotency_key' => (string) Str::uuid()]);
        $out = $this->race($b, [$this->worker('merge', $approver, ['request_id' => $request->id, 'lock_version' => $request->lock_version]), $this->worker('save', $actor, $this->payload($a, $b))]);
        $this->assertStringContainsString('MERGED', $out);
        $this->assertSame($kept->id, $b->fresh()->merged_into_patient_id);
        $this->assertSame(0, DB::table('patient_family_links')->where('patient_high_id', $kept->id)->count());
        $this->selectBranch($actor);
        $rows = app(PatientFamilyLinkService::class)->directory($actor, $a)['links'];
        $this->assertTrue($rows === [] || $rows[0]['suppressed']);
        $this->assertTrue(str_contains($out, 'SUCCESS 1') || str_contains($out, 'STALE family_link'), $out);
    }

    /** @return array<string,mixed> */
    private function payload(Patient $a, Patient $b, array $overrides = []): array
    {
        return ['patient_id' => $a->id, 'other_patient_number' => $b->patient_number, 'operation' => 'link', 'relationship_code' => 'parent_of', 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), ...$overrides];
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresFamilyLinkWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-family-pg-'.Str::lower(Str::random(8))], base_path());
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
                    throw new RuntimeException('Family link worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Family link worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required family link contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected family link blocker chain was not observed. '.$this->processDiagnostics($process));
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
