<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Family\FamilyVisitGroups;
use App\Domain\Patient\Family\PatientFamilyService;
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

/** FAM-1: real patient-row lock contention for family commands and registration (READY/GO PostgreSQL observer harness).
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPatientFamilyContentionTest extends VisitTestCase
{
    private const OBSERVER = 'pgsql_patient_family_observer';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL family contention tests require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Family contention tests require an isolated PostgreSQL test database.');
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
        $this->assertStringContainsString('STALE family', $out);
        $this->assertSame(1, DB::table('patient_families')->count());
        $this->assertSame(2, DB::table('patient_family_members')->whereNull('left_at')->count());
        $this->assertSame(1, DB::table('patient_family_commands')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'patient.family.changed')->count());
    }

    public function test_reverse_first_links_lock_the_same_patients_and_only_one_family_exists(): void
    {
        $a = $this->patient();
        $b = $this->patient();
        $out = $this->race($a, [$this->worker('save', $this->actor(), $this->payload($a, $b)), $this->worker('save', $this->actor('ca_supervisor'), $this->payload($b, $a))]);
        $this->assertSame(1, substr_count($out, 'SUCCESS 1'), $out);
        $this->assertSame(1, DB::table('patient_families')->count());
        $this->assertSame(2, DB::table('patient_family_members')->count());
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
        $this->assertSame(1, DB::table('patient_family_commands')->count());
        $this->assertSame(1, DB::table('patient_families')->value('lock_version'));
    }

    public function test_a_join_racing_a_removal_that_closes_the_family_never_leaves_a_family_of_one(): void
    {
        [$a, $b, $c] = [$this->patient(), $this->patient(), $this->patient()];
        $actor = $this->actor();
        $this->selectBranch($actor);
        app(PatientFamilyService::class)->execute($actor, $a, [...$this->payload($a, $b), 'expected_branch_id' => $this->branch->id]);
        $out = $this->race($b, [
            $this->worker('save', $actor, $this->payload($c, $b, ['relative_role' => 'child', 'patient_role' => 'grandparent'])),
            $this->worker('save', $this->actor('ca_supervisor'), $this->payload($a, $b, ['operation' => 'remove', 'relative_role' => null, 'patient_role' => null, 'expected_family_version' => 1])),
        ]);
        $this->assertGreaterThanOrEqual(1, substr_count($out, 'SUCCESS'), $out);
        foreach (DB::table('patient_family_members')->whereNull('left_at')->selectRaw('patient_family_id, count(*) as n')->groupBy('patient_family_id')->get() as $row) {
            $this->assertGreaterThanOrEqual(2, (int) $row->n, $out);
        }
        $this->assertSame(0, DB::table('patient_family_members')->whereNull('left_at')->selectRaw('patient_id')->groupBy('patient_id')->havingRaw('count(*) > 1')->count());
    }

    public function test_registration_racing_a_link_always_ends_with_the_visit_grouped(): void
    {
        $a = $this->patient();
        $b = $this->patient();
        $actor = $this->actor();
        $register = ['idempotency_key' => (string) Str::uuid(), 'patient_number' => $a->patient_number, 'visit_type' => 'otc', 'assigned_doctor_user_id' => null, 'priority' => 'normal', 'coverage_type' => 'self_pay', 'panel_id' => null, 'coverage_member_reference' => null, 'confirm_repeat' => false];
        $out = $this->race($a, [$this->worker('register', $this->actor(), $register), $this->worker('save', $actor, $this->payload($a, $b))]);
        $this->assertStringContainsString('REGISTERED', $out);
        $this->assertStringContainsString('SUCCESS 1', $out);
        $family = DB::table('patient_families')->sole();
        $key = app(FamilyVisitGroups::class)->key($this->organisation->id, $family->id, $this->branch->id, now($this->branch->timezone)->toDateString());
        $this->assertSame($key, DB::table('visits')->where('patient_id', $a->id)->value('family_group_id'), $out);
    }

    /** @return array<string,mixed> */
    private function payload(Patient $a, Patient $b, array $overrides = []): array
    {
        return ['patient_id' => $a->id, 'operation' => 'add', 'relative_patient_number' => $b->patient_number, 'relative_role' => 'child', 'patient_role' => 'parent', 'expected_family_version' => 0, 'idempotency_key' => (string) Str::uuid(), ...$overrides];
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPatientFamilyWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-fam1-pg-'.Str::lower(Str::random(8))], base_path());
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
                    throw new RuntimeException('Family worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Family worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required family contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected family blocker chain was not observed. '.$this->processDiagnostics($process));
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
