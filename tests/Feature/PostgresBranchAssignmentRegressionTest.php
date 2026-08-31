<?php

namespace Tests\Feature;

use App\Domain\Access\StaffAuthorityService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresBranchAssignmentRegressionTest extends TestCase
{
    private const OBSERVER_CONNECTION = 'pgsql_regression_observer';

    private ?int $fixtureOrganisationId = null;

    private bool $postgresTestDatabaseConfirmed = false;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $workerInputs = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL regression coverage runs only with DB_CONNECTION=pgsql.');
        }

        $databaseName = (string) DB::connection()->getDatabaseName();

        if (! app()->environment('testing')
            || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $databaseName) !== 1) {
            throw new RuntimeException('PostgreSQL regression coverage requires an isolated test database.');
        }

        foreach (['organisations', 'branches', 'departments', 'users', 'staff_profiles', 'staff_branch_assignments', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before running regression coverage.');
            }
        }

        Config::set(
            'database.connections.'.self::OBSERVER_CONNECTION,
            config('database.connections.'.config('database.default')),
        );
        DB::purge(self::OBSERVER_CONNECTION);

        $this->postgresTestDatabaseConfirmed = true;
    }

    protected function tearDown(): void
    {
        foreach ($this->workerInputs as $input) {
            if (! $input->isClosed()) {
                $input->close();
            }
        }

        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        if ($this->postgresTestDatabaseConfirmed && $this->fixtureOrganisationId !== null) {
            DB::table('audit_logs')->where('organisation_id', $this->fixtureOrganisationId)->delete();
            $fixtureUserIds = DB::table('users')
                ->where('organisation_id', $this->fixtureOrganisationId)
                ->pluck('id');
            DB::table('model_has_permissions')
                ->where('model_type', User::class)
                ->whereIn('model_id', $fixtureUserIds)
                ->delete();
            DB::table('users')->where('organisation_id', $this->fixtureOrganisationId)->delete();
            DB::table('departments')->where('organisation_id', $this->fixtureOrganisationId)->delete();
            DB::table('branches')->where('organisation_id', $this->fixtureOrganisationId)->delete();
            DB::table('organisations')->where('id', $this->fixtureOrganisationId)->delete();
        }

        DB::purge(self::OBSERVER_CONNECTION);

        parent::tearDown();
    }

    public function test_postgresql_rolls_back_assignment_and_audit_state_after_mid_transaction_failure(): void
    {
        $fixture = $this->createFixture();
        [$previousPrimary, $target] = $fixture['assignments'];
        $auditCountsBefore = $this->primaryAuditCounts();

        $failingAuditRecorder = new class extends AuditRecorder
        {
            /** @param array<string, mixed> $metadata */
            public function record(
                string $event,
                ?Model $subject = null,
                array $metadata = [],
                ?User $actor = null,
                ?Branch $branch = null,
                ?int $organisationId = null,
            ): ?AuditLog {
                if ($event === 'staff.branch_assignment.primary.promoted') {
                    throw new RuntimeException('Injected audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };

        try {
            (new BranchAssignmentService($failingAuditRecorder, app(StaffAuthorityService::class)))->changePrimary(
                $fixture['profile'],
                $target,
                $fixture['actor'],
            );
            $this->fail('The injected audit failure did not abort the primary change.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected audit failure.', $exception->getMessage());
        }

        $this->assertTrue(StaffBranchAssignment::query()->findOrFail($previousPrimary->id)->is_primary);
        $this->assertFalse(StaffBranchAssignment::query()->findOrFail($target->id)->is_primary);
        $this->assertSame(1, $fixture['profile']->branchAssignments()->where('is_primary', true)->count());
        $this->assertSame($auditCountsBefore, $this->primaryAuditCounts());
    }

    public function test_postgresql_concurrent_primary_changes_serialize_with_exactly_one_primary_assignment(): void
    {
        $fixture = $this->createFixture(3);
        [, $firstTarget, $secondTarget] = $fixture['assignments'];
        $auditCountsBefore = $this->primaryAuditCounts();
        $this->assertSame(0, DB::connection()->transactionLevel(), 'Concurrency fixtures must be committed.');
        $this->assertTrue($this->observerConnection()
            ->table('staff_profiles')
            ->where('id', $fixture['profile']->id)
            ->exists(), 'The independent observer connection cannot see the committed fixture.');
        $this->assertSame(3, $this->observerConnection()
            ->table('staff_branch_assignments')
            ->where('staff_profile_id', $fixture['profile']->id)
            ->count());

        $workerNames = [
            'kpone-pg-test-'.Str::replace('-', '', (string) Str::uuid()),
            'kpone-pg-test-'.Str::replace('-', '', (string) Str::uuid()),
        ];
        $firstWorker = $this->newPrimaryChangeWorker(
            $fixture['actor'],
            $fixture['profile'],
            $firstTarget,
            $workerNames[0],
        );
        $secondWorker = $this->newPrimaryChangeWorker(
            $fixture['actor'],
            $fixture['profile'],
            $secondTarget,
            $workerNames[1],
        );
        $this->workers = [$firstWorker['process'], $secondWorker['process']];
        $this->workerInputs = [$firstWorker['input'], $secondWorker['input']];

        foreach ($this->workers as $worker) {
            $worker->start();
        }

        $workerBackendPids = $this->waitUntilWorkersAreReady();
        $this->assertNotSame($workerBackendPids[0], $workerBackendPids[1]);
        $this->assertWorkerBackends($workerBackendPids, $workerNames);

        $connection = DB::connection();
        $parentBackendPid = (int) $connection->scalar('select pg_backend_pid()');
        $this->assertNotContains($parentBackendPid, $workerBackendPids);
        $connection->beginTransaction();

        try {
            StaffProfile::query()->whereKey($fixture['profile']->id)->lockForUpdate()->firstOrFail();

            foreach ($this->workerInputs as $input) {
                $input->write("GO\n");
            }

            foreach ($this->workerInputs as $input) {
                $input->close();
            }

            $waitStates = $this->waitUntilWorkersAreBlocked($workerBackendPids, $workerNames);
            $this->assertCount(2, $waitStates);
            foreach ($waitStates as $waitState) {
                $this->assertSame('Lock', $waitState['wait_event_type']);
                $this->assertSame(1, $waitState['has_ungranted_lock']);
            }

            $connection->commit();

            foreach ($this->workers as $worker) {
                $worker->wait();
            }
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }

        foreach ($this->workers as $index => $worker) {
            $this->assertSame(0, $worker->getExitCode(), $this->workerDiagnostic($worker));
            $this->assertMatchesRegularExpression(
                '/^DONE '.preg_quote((string) $workerBackendPids[$index], '/').'\r?$/m',
                $worker->getOutput(),
            );
        }

        $this->assertSame(1, $fixture['profile']->branchAssignments()->where('is_primary', true)->count());
        $this->assertSame(1, StaffBranchAssignment::query()
            ->whereKey([$firstTarget->id, $secondTarget->id])
            ->where('is_primary', true)
            ->count());

        $auditCountsAfter = $this->primaryAuditCounts();
        $this->assertSame($auditCountsBefore['demoted'] + 2, $auditCountsAfter['demoted']);
        $this->assertSame($auditCountsBefore['promoted'] + 2, $auditCountsAfter['promoted']);
    }

    /**
     * @return array{
     *     actor: User,
     *     user: User,
     *     profile: StaffProfile,
     *     assignments: list<StaffBranchAssignment>
     * }
     */
    private function createFixture(int $branchCount = 2): array
    {
        $suffix = Str::lower(Str::random(12));
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'PG_TEST_'.Str::upper($suffix),
            'name' => 'PostgreSQL Test Organisation',
            'is_active' => true,
        ])->save();
        $this->fixtureOrganisationId = $organisation->id;

        $department = new Department;
        $department->forceFill([
            'organisation_id' => $organisation->id,
            'code' => 'TEST_DEPARTMENT',
            'name' => 'Test Department',
            'is_active' => true,
        ])->save();

        $actor = new User;
        $actor->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'PostgreSQL Test Actor',
            'email' => "postgresql.actor.{$suffix}@kpone.test",
            'is_active' => true,
        ])->save();
        $actor->givePermissionTo(Permission::findOrCreate('access.manage.organisation', 'web'));

        $user = new User;
        $user->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'PostgreSQL Test Staff',
            'email' => "postgresql.{$suffix}@kpone.test",
            'is_active' => true,
        ])->save();

        $profile = new StaffProfile;
        $profile->forceFill([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'job_title' => 'Test Position',
        ])->save();

        $assignments = [];
        $service = app(BranchAssignmentService::class);

        for ($index = 0; $index < $branchCount; $index++) {
            $branch = new Branch;
            $branch->forceFill([
                'organisation_id' => $organisation->id,
                'code' => 'TEST_BRANCH_'.($index + 1),
                'name' => 'Test Branch '.($index + 1),
                'timezone' => 'Asia/Kuala_Lumpur',
                'is_active' => true,
            ])->save();

            $assignments[] = $service->create($profile, $branch, [
                'assignment_type' => 'test',
                'is_primary' => $index === 0,
                'valid_from' => now()->subDay()->toDateString(),
            ], $actor);
        }

        return compact('actor', 'user', 'profile', 'assignments');
    }

    /** @return array{process: Process, input: InputStream} */
    private function newPrimaryChangeWorker(
        User $actor,
        StaffProfile $profile,
        StaffBranchAssignment $assignment,
        string $applicationName,
    ): array {
        $input = new InputStream;
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/PostgresPrimaryChangeWorker.php'),
            (string) $actor->id,
            (string) $profile->id,
            (string) $assignment->id,
            $applicationName,
        ], base_path());
        $process->setInput($input);
        $process->setTimeout(30);

        return compact('process', 'input');
    }

    /** @return array{int, int} */
    private function waitUntilWorkersAreReady(): array
    {
        $deadline = microtime(true) + 10;

        do {
            $backendPids = [];

            foreach ($this->workers as $index => $worker) {
                $this->failIfWorkerTerminated($worker, 'before reporting READY');

                if (preg_match('/^READY ([1-9][0-9]*)\r?$/m', $worker->getOutput(), $matches) === 1) {
                    $backendPids[$index] = (int) $matches[1];
                }
            }

            if (count($backendPids) === count($this->workers)) {
                return [$backendPids[0], $backendPids[1]];
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('PostgreSQL workers did not report READY. '.$this->workerDiagnostics());
    }

    /**
     * @param  array{int, int}  $workerBackendPids
     * @param  array{string, string}  $workerNames
     */
    private function assertWorkerBackends(array $workerBackendPids, array $workerNames): void
    {
        $backends = $this->observerConnection()->select(
            <<<'SQL'
                select pid, application_name
                from pg_stat_activity
                where datname = current_database()
                  and ((pid = ? and application_name = ?)
                    or (pid = ? and application_name = ?))
                SQL,
            [
                $workerBackendPids[0],
                $workerNames[0],
                $workerBackendPids[1],
                $workerNames[1],
            ],
        );

        $this->assertCount(2, $backends, 'Workers are not connected to the expected PostgreSQL test database.');
    }

    /**
     * @param  array{int, int}  $workerBackendPids
     * @param  array{string, string}  $workerNames
     * @return list<array{pid: int, wait_event_type: string, wait_event: string, has_ungranted_lock: int}>
     */
    private function waitUntilWorkersAreBlocked(array $workerBackendPids, array $workerNames): array
    {
        $deadline = microtime(true) + 15;
        $lastObserved = [];

        do {
            foreach ($this->workers as $worker) {
                $this->failIfWorkerTerminated($worker, 'before row-lock contention was observed');
            }

            $sessions = $this->observerConnection()->select(
                <<<'SQL'
                    select
                        activity.pid,
                        activity.wait_event_type,
                        activity.wait_event,
                        case when exists (
                            select 1
                            from pg_locks
                            where pg_locks.pid = activity.pid
                              and not pg_locks.granted
                        ) then 1 else 0 end as has_ungranted_lock
                    from pg_stat_activity as activity
                    where activity.datname = current_database()
                      and ((activity.pid = ? and activity.application_name = ?)
                        or (activity.pid = ? and activity.application_name = ?))
                    SQL,
                [
                    $workerBackendPids[0],
                    $workerNames[0],
                    $workerBackendPids[1],
                    $workerNames[1],
                ],
            );
            $lastObserved = array_values(array_map(static function (object $session): array {
                $values = get_object_vars($session);

                return [
                    'pid' => (int) ($values['pid'] ?? 0),
                    'wait_event_type' => (string) ($values['wait_event_type'] ?? ''),
                    'wait_event' => (string) ($values['wait_event'] ?? ''),
                    'has_ungranted_lock' => (int) ($values['has_ungranted_lock'] ?? 0),
                ];
            }, $sessions));

            if (count($lastObserved) === count($workerBackendPids)
                && collect($lastObserved)->every(fn (array $session): bool => $session['wait_event_type'] === 'Lock'
                    && $session['has_ungranted_lock'] === 1)) {
                return $lastObserved;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            'PostgreSQL workers did not reach concurrent row-lock contention. '
            .$this->workerDiagnostics().' observed='.json_encode($lastObserved, JSON_THROW_ON_ERROR),
        );
    }

    private function failIfWorkerTerminated(Process $worker, string $phase): void
    {
        if ($worker->isTerminated()) {
            throw new RuntimeException("A PostgreSQL worker exited {$phase}. ".$this->workerDiagnostic($worker));
        }
    }

    private function workerDiagnostics(): string
    {
        return collect($this->workers)
            ->map(fn (Process $worker): string => $this->workerDiagnostic($worker))
            ->implode('; ');
    }

    private function workerDiagnostic(Process $worker): string
    {
        preg_match_all('/^(?:READY|DONE) [1-9][0-9]*\r?$/m', $worker->getOutput(), $protocolLines);
        $errorOutput = trim($worker->getErrorOutput());
        $safeError = $errorOutput === ''
            ? 'none'
            : (preg_match('/\A[A-Za-z_\\\\][A-Za-z0-9_\\\\]*\z/', $errorOutput) === 1
                ? $errorOutput
                : '[REDACTED]');

        return sprintf(
            'exit=%s protocol=%s error=%s',
            $worker->getExitCode() === null ? 'running' : (string) $worker->getExitCode(),
            $protocolLines[0] === [] ? 'none' : implode(',', $protocolLines[0]),
            $safeError,
        );
    }

    private function observerConnection(): Connection
    {
        return DB::connection(self::OBSERVER_CONNECTION);
    }

    /** @return array{demoted: int, promoted: int} */
    private function primaryAuditCounts(): array
    {
        return [
            'demoted' => AuditLog::query()
                ->where('organisation_id', $this->fixtureOrganisationId)
                ->where('event', 'staff.branch_assignment.primary.demoted')
                ->count(),
            'promoted' => AuditLog::query()
                ->where('organisation_id', $this->fixtureOrganisationId)
                ->where('event', 'staff.branch_assignment.primary.promoted')
                ->count(),
        ];
    }
}
