<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Identity\Services\BranchAssignmentService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresBranchAssignmentRegressionTest extends TestCase
{
    private ?int $fixtureOrganisationId = null;

    private bool $postgresTestDatabaseConfirmed = false;

    /** @var list<Process> */
    private array $workers = [];

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

        $this->postgresTestDatabaseConfirmed = true;
    }

    protected function tearDown(): void
    {
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
            DB::table('users')->where('organisation_id', $this->fixtureOrganisationId)->delete();
            DB::table('departments')->where('organisation_id', $this->fixtureOrganisationId)->delete();
            DB::table('branches')->where('organisation_id', $this->fixtureOrganisationId)->delete();
            DB::table('organisations')->where('id', $this->fixtureOrganisationId)->delete();
        }

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
            (new BranchAssignmentService($failingAuditRecorder))->changePrimary(
                $fixture['profile'],
                $target,
                $fixture['user'],
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
        $workerNames = [
            'kpone-pg-test-'.Str::replace('-', '', (string) Str::uuid()),
            'kpone-pg-test-'.Str::replace('-', '', (string) Str::uuid()),
        ];
        $this->workers = [
            $this->newPrimaryChangeWorker($fixture['user'], $fixture['profile'], $firstTarget, $workerNames[0]),
            $this->newPrimaryChangeWorker($fixture['user'], $fixture['profile'], $secondTarget, $workerNames[1]),
        ];

        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            StaffProfile::query()->whereKey($fixture['profile']->id)->lockForUpdate()->firstOrFail();

            foreach ($this->workers as $worker) {
                $worker->start();
            }

            $this->waitUntilWorkersAreBlocked($workerNames);
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

        foreach ($this->workers as $worker) {
            $this->assertSame(0, $worker->getExitCode(), 'A PostgreSQL primary-change worker failed.');
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
            ], $user);
        }

        return compact('user', 'profile', 'assignments');
    }

    private function newPrimaryChangeWorker(
        User $actor,
        StaffProfile $profile,
        StaffBranchAssignment $assignment,
        string $applicationName,
    ): Process {
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/PostgresPrimaryChangeWorker.php'),
            (string) $actor->id,
            (string) $profile->id,
            (string) $assignment->id,
            $applicationName,
        ], base_path());
        $process->setTimeout(30);

        return $process;
    }

    /** @param array{string, string} $workerNames */
    private function waitUntilWorkersAreBlocked(array $workerNames): void
    {
        $deadline = microtime(true) + 15;

        do {
            foreach ($this->workers as $worker) {
                if ($worker->isTerminated()) {
                    throw new RuntimeException('A PostgreSQL worker exited before reaching row-lock contention.');
                }
            }

            $blockedSessions = DB::select(
                <<<'SQL'
                    select application_name
                    from pg_stat_activity
                    where application_name in (?, ?)
                      and wait_event_type = 'Lock'
                    SQL,
                $workerNames,
            );

            if (count($blockedSessions) === count($workerNames)) {
                return;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('PostgreSQL workers did not reach concurrent row-lock contention in time.');
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
