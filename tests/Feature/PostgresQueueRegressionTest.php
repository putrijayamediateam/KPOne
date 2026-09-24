<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Models\VisitReason;
use App\Domain\Visit\Models\VisitReasonAssignment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class PostgresQueueRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_queue_regression_observer';

    private ?int $organisationId = null;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private int $visitSequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Queue regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Queue concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['queue_entries', 'queue_number_counters', 'visits', 'patients', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Queue regressions.');
            }
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
        if ($this->organisationId !== null) {
            DB::table('audit_logs')->where('organisation_id', $this->organisationId)->delete();
            DB::table('queue_entries')->where('organisation_id', $this->organisationId)->delete();
            DB::table('visit_reason_assignments')->where('organisation_id', $this->organisationId)->delete();
            DB::table('queue_number_counters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('visits')->where('organisation_id', $this->organisationId)->delete();
            DB::table('visit_number_counters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_identifiers')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patients')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_number_counters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('visit_reason_catalogue_items')->where('organisation_id', $this->organisationId)->delete();
            $branchIds = DB::table('branches')->where('organisation_id', $this->organisationId)->pluck('id');
            DB::table('staff_branch_assignments')->whereIn('branch_id', $branchIds)->delete();
            $userIds = DB::table('users')->where('organisation_id', $this->organisationId)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->where('organisation_id', $this->organisationId)->delete();
            DB::table('branches')->where('organisation_id', $this->organisationId)->delete();
            DB::table('organisations')->where('id', $this->organisationId)->delete();
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_concurrent_send_same_visit_creates_one_logical_queue_entry(): void
    {
        [$organisation, $branches, $actors, $doctor] = $this->fixture();
        $visit = $this->visit($organisation, $branches[0], $actors[0], $doctor);
        $workers = [$this->enterWorker($actors[0], $visit), $this->enterWorker($actors[1], $visit)];

        $this->runTogetherWhileParentBlocks($workers, fn () => Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail());

        $output = $this->workerOutput($workers);
        $this->assertSame(2, substr_count($output, 'ENTERED 1'));
        $this->assertSame(1, DB::table('queue_entries')->where('visit_id', $visit->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $organisation->id)->where('event', 'queue.entered')->count());
    }

    public function test_concurrent_first_and_existing_counter_allocations_are_distinct(): void
    {
        [$organisation, $branches, $actors, $doctor] = $this->fixture();
        [, , $secondDoctor] = $this->additionalDoctor($organisation, $branches[0]);
        $firstVisits = [
            $this->visit($organisation, $branches[0], $actors[0], $doctor),
            $this->visit($organisation, $branches[0], $actors[1], $secondDoctor),
        ];
        $this->runTogetherWhileParentBlocks([
            $this->enterWorker($actors[0], $firstVisits[0]),
            $this->enterWorker($actors[1], $firstVisits[1]),
        ], fn () => DB::statement('LOCK TABLE queue_number_counters IN ACCESS EXCLUSIVE MODE'));
        $this->assertSame([1, 2], DB::table('queue_entries')->where('organisation_id', $organisation->id)->pluck('queue_number')->sort()->values()->all());

        $more = [
            $this->visit($organisation, $branches[0], $actors[0], $doctor),
            $this->visit($organisation, $branches[0], $actors[1], $secondDoctor),
        ];
        $this->runTogetherWhileParentBlocks([
            $this->enterWorker($actors[0], $more[0]),
            $this->enterWorker($actors[1], $more[1]),
        ], fn () => DB::table('queue_number_counters')->where('organisation_id', $organisation->id)->lockForUpdate()->first());
        $this->assertSame(
            [1, 2, 3, 4],
            DB::table('queue_entries')
                ->where('organisation_id', $organisation->id)
                ->pluck('queue_number')
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame(5, DB::table('queue_number_counters')->where('organisation_id', $organisation->id)->value('next_value'));
    }

    public function test_same_numeric_numbers_are_valid_across_branches_and_midnight_resets(): void
    {
        try {
            Date::setTestNow('2026-08-27 15:59:00 UTC');
            [$organisation, $branches, $actors, $doctor] = $this->fixture();
            [, , $otherDoctor] = $this->additionalDoctor($organisation, $branches[1]);
            $first = $this->enter($actors[0], $this->visit($organisation, $branches[0], $actors[0], $doctor));
            $other = $this->enter($actors[0], $this->visit($organisation, $branches[1], $actors[0], $otherDoctor));

            Date::setTestNow('2026-08-27 16:01:00 UTC');
            $next = $this->enter($actors[0], $this->visit($organisation, $branches[0], $actors[0], $doctor));

            $this->assertSame([1, 1, 1], [$first->queue_number, $other->queue_number, $next->queue_number]);
            $this->assertNotSame($first->operational_date->toDateString(), $next->operational_date->toDateString());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_concurrent_call_in_accepts_exactly_one_transition(): void
    {
        [$organisation, $branches, $actors, $doctor] = $this->fixture();
        $visit = $this->visit($organisation, $branches[0], $actors[0], $doctor);
        $entry = $this->enter($actors[0], $visit);
        $workers = [$this->callWorker($actors[0], $visit, $entry), $this->callWorker($actors[1], $visit, $entry)];

        $this->runTogetherWhileParentBlocks($workers, fn () => Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail());
        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'CALLED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(QueueEntry::STATUS_SERVING, $entry->refresh()->status);
    }

    public function test_priority_and_doctor_reassignment_races_call_are_serialized(): void
    {
        foreach (['priority', 'reassign'] as $mode) {
            [$organisation, $branches, $actors, $doctor] = $this->fixture();
            [, , $newDoctor] = $this->additionalDoctor($organisation, $branches[0]);
            $visit = $this->visit($organisation, $branches[0], $actors[0], $doctor);
            $entry = $this->enter($actors[0], $visit);
            $mutation = $this->worker([
                $mode, (string) $actors[0]->id, $visit->visit_number, (string) $branches[0]->id,
                (string) $visit->lock_version, (string) $entry->lock_version, (string) $newDoctor->id,
            ]);
            $call = $this->callWorker($actors[1], $visit, $entry);

            $this->runTogetherWhileParentBlocks([$mutation, $call], fn () => Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail());
            $output = $this->workerOutput([$mutation, $call]);
            $this->assertSame(1, substr_count($output, 'STALE'));
            $this->assertSame(1, substr_count($output, 'UPDATED') + substr_count($output, 'CALLED'));
            $this->tearDownOrganisationOnly();
        }
    }

    public function test_doctor_deactivation_role_and_assignment_races_block_call(): void
    {
        foreach (['deactivate', 'change-role', 'change-assignment'] as $mode) {
            [$organisation, $branches, $actors, $doctor, $profile, $assignment, $administrator] = $this->fixture(true);
            $visit = $this->visit($organisation, $branches[0], $actors[0], $doctor);
            $entry = $this->enter($actors[0], $visit);
            $mutationArgs = match ($mode) {
                'deactivate' => [$mode, (string) $doctor->id],
                'change-role' => [$mode, (string) $administrator->id, (string) $doctor->id],
                default => [$mode, (string) $administrator->id, (string) $profile->id, (string) $assignment->id],
            };
            $mutation = $this->visitWorker($mutationArgs);
            $mutation['process']->start();
            $this->waitReady([$mutation]);
            $mutation['input']->write("GO\n");
            $this->waitForOutput($mutation['process'], 'LOCKED');
            $call = $this->callWorker($actors[0], $visit, $entry);
            $call['process']->start();
            $this->waitReady([$call]);
            $call['input']->write("GO\n");
            $call['input']->close();
            $this->waitForDatabaseBlock($call['process']);
            $mutation['input']->write("COMMIT\n");
            $mutation['input']->close();
            $mutation['process']->wait();
            $call['process']->wait();

            $this->assertSame(
                0,
                $mutation['process']->getExitCode(),
                $mode.' mutation failed: '.$mutation['process']->getErrorOutput(),
            );
            $this->assertStringContainsString('STALE', $call['process']->getOutput(), $mode);
            $this->assertSame(QueueEntry::STATUS_WAITING, $entry->refresh()->status);
            $this->tearDownOrganisationOnly();
        }
    }

    public function test_cancellation_races_send_and_call_without_orphan_state(): void
    {
        foreach (['send', 'call'] as $race) {
            [$organisation, $branches, $actors, $doctor] = $this->fixture();
            $visit = $this->visit($organisation, $branches[0], $actors[0], $doctor);
            $entry = $race === 'call' ? $this->enter($actors[0], $visit) : null;
            $other = $race === 'send'
                ? $this->enterWorker($actors[0], $visit)
                : $this->callWorker($actors[0], $visit, $entry);
            $cancel = $this->worker([
                'cancel', (string) $actors[1]->id, $visit->visit_number, (string) $branches[0]->id,
                (string) $visit->lock_version, $entry ? (string) $entry->lock_version : 'none',
            ]);

            $this->runTogetherWhileParentBlocks([$other, $cancel], fn () => Visit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail());
            $output = $this->workerOutput([$other, $cancel]);
            $this->assertSame(1, substr_count($output, 'STALE') + substr_count($output, 'REJECTED'));
            $this->assertFalse(
                Visit::query()->whereKey($visit->id)->where('status', Visit::STATUS_CANCELLED)->exists()
                && QueueEntry::query()->where('visit_id', $visit->id)->whereIn('status', [QueueEntry::STATUS_WAITING, QueueEntry::STATUS_SERVING])->exists(),
            );
            $this->tearDownOrganisationOnly();
        }
    }

    private function fixture(bool $security = false): array
    {
        $suffix = Str::lower(Str::random(8));
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'QUEUE_PG_'.Str::upper($suffix), 'name' => 'Synthetic Queue PG', 'is_active' => true])->save();
        $this->organisationId = $organisation->id;
        $branches = collect(['ONE', 'TWO'])->map(function (string $code) use ($organisation): Branch {
            $branch = new Branch;
            $branch->forceFill(['organisation_id' => $organisation->id, 'code' => $code, 'name' => 'Synthetic '.$code, 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();

            return $branch;
        })->all();
        $permissions = ['visits.view.branch', 'visits.update.branch', 'visits.cancel.branch', 'queue.view.branch', 'queue.enter.branch', 'queue.call.branch', 'branch_context.switch.organisation'];
        $actors = collect([1, 2])->map(function (int $number) use ($organisation, $suffix, $permissions): User {
            $actor = new User;
            $actor->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Queue Actor '.$number, 'email' => "queue.pg.{$number}.{$suffix}@kpone.test", 'is_active' => true])->save();
            foreach ($permissions as $permission) {
                $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }

            return $actor;
        })->all();
        [$profile, $assignment, $doctor] = $this->additionalDoctor($organisation, $branches[0]);
        $administrator = null;
        if ($security) {
            // The regression fixture is self-contained when this test class is
            // run directly, rather than relying on an earlier seeder/test to
            // have created the role used by the concurrent mutation worker.
            Role::findOrCreate('ca', 'web');
            $administrator = new User;
            $administrator->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Queue Security', 'email' => "queue.security.{$suffix}@kpone.test", 'is_active' => true])->save();
            $administrator->assignRole(Role::findOrCreate('director', 'web'));
            $administrator->givePermissionTo(Permission::findOrCreate('access.manage.organisation', 'web'));
        }

        return [$organisation, $branches, $actors, $doctor, $profile, $assignment, $administrator];
    }

    private function additionalDoctor(Organisation $organisation, Branch $branch): array
    {
        $doctor = new User;
        $doctor->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Queue Doctor', 'email' => 'queue.doctor.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        $doctor->assignRole(Role::findOrCreate('resident_doctor', 'web'));
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $doctor->id, 'department_id' => null])->save();
        $assignment = StaffBranchAssignmentBootstrapper::create($profile, $branch, [
            'assignment_type' => 'temporary', 'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString(),
        ]);

        return [$profile, $assignment, $doctor];
    }

    private function visit(Organisation $organisation, Branch $branch, User $actor, User $doctor): Visit
    {
        $patient = new Patient;
        $number = sprintf('KP-%08d', 90_000_000 + $this->visitSequence);
        $patient->forceFill(['organisation_id' => $organisation->id, 'patient_number' => $number, 'full_name' => 'Synthetic Queue Patient '.$this->visitSequence, 'search_name' => 'synthetic queue patient '.$this->visitSequence, 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill([
            'organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id,
            'visit_number' => sprintf('KPV-%08d', 90_000_000 + $this->visitSequence++), 'idempotency_key' => (string) Str::uuid(),
            'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal',
            'visit_reason' => 'Synthetic PostgreSQL Queue reason', 'assigned_doctor_user_id' => $doctor->id,
            'coverage_type' => 'self_pay', 'registered_at' => now()->utc(),
            'registered_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id, 'lock_version' => 1,
        ])->save();
        $reasonName = 'Synthetic PostgreSQL Queue reason '.$visit->id;
        $reason = new VisitReason;
        $reason->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $organisation->id,
            'name' => $reasonName,
            'normalized_name' => Str::lower($reasonName),
            'is_active' => true,
            'created_by_user_id' => $actor->id,
        ])->save();
        $assignment = new VisitReasonAssignment;
        $assignment->forceFill([
            'organisation_id' => $organisation->id,
            'branch_id' => $branch->id,
            'visit_id' => $visit->id,
            'visit_reason_catalogue_item_id' => $reason->id,
            'label_snapshot' => $reasonName,
            'position' => 1,
        ])->save();

        return $visit;
    }

    private function enter(User $actor, Visit $visit): QueueEntry
    {
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $visit->branch_id]);

        return app(QueueEntryService::class)->enter($actor, $visit, [
            'expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version,
        ]);
    }

    private function enterWorker(User $actor, Visit $visit): array
    {
        return $this->worker(['enter', (string) $actor->id, $visit->visit_number, (string) $visit->branch_id]);
    }

    private function callWorker(User $actor, Visit $visit, QueueEntry $entry): array
    {
        return $this->worker(['call', (string) $actor->id, $visit->visit_number, (string) $visit->branch_id, (string) $visit->lock_version, (string) $entry->lock_version]);
    }

    private function worker(array $arguments): array
    {
        return $this->process(base_path('tests/Support/PostgresQueueWorker.php'), $arguments);
    }

    private function visitWorker(array $arguments): array
    {
        return $this->process(base_path('tests/Support/PostgresVisitRegistrationWorker.php'), $arguments);
    }

    private function process(string $script, array $arguments): array
    {
        $input = new InputStream;
        $name = 'kpone-queue-pg-'.Str::lower(Str::random(10));
        $process = new Process([PHP_BINARY, $script, ...$arguments, $name], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    private function runTogetherWhileParentBlocks(array $workers, callable $lock): void
    {
        DB::beginTransaction();
        try {
            $lock();
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
            $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        }
    }

    private function waitReady(array $workers): void
    {
        $timeoutSeconds = ContentionTimeouts::readyTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Queue worker did not report ready within %ds (elapsed %.2fs). %s', $timeoutSeconds, microtime(true) - $started, $this->diagnostics($workers)));
    }

    private function waitForOutput(Process $process, string $needle): void
    {
        $timeoutSeconds = ContentionTimeouts::protocolTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            if (str_contains($process->getOutput(), $needle)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Queue worker protocol timeout for %s within %ds (elapsed %.2fs). %s', $needle, $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
    }

    private function pid(string $output): int
    {
        preg_match('/READY ([1-9][0-9]*)/', $output, $matches);

        return (int) ($matches[1] ?? 0);
    }

    private function waitForDatabaseBlock(Process $process, ?int $expectedBlockerPid = null): void
    {
        $pid = $this->pid($process->getOutput());
        if ($pid <= 0) {
            throw new RuntimeException('Queue worker did not report a valid PostgreSQL backend PID. '.$this->processDiagnostics($process));
        }

        $timeoutSeconds = ContentionTimeouts::protocolTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        $lastObserved = null;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException(
                    'Queue worker exited before PostgreSQL blocking was observed. exit='.(string) $process->getExitCode()
                    .' output='.trim($process->getOutput()).' error='.trim($process->getErrorOutput()),
                );
            }

            $result = $this->observer()->selectOne(
                <<<'SQL'
                    select
                        state,
                        coalesce(wait_event_type, '') as wait_event_type,
                        coalesce(wait_event, '') as wait_event,
                        cardinality(pg_blocking_pids(pid)) as blocker_count,
                        case when exists (
                            select 1 from pg_locks
                            where pg_locks.pid = activity.pid and not pg_locks.granted
                        ) then 1 else 0 end as has_ungranted_lock,
                        case when ?::integer is null then false else exists (
                            with recursive blockers(pid) as (
                                select unnest(pg_blocking_pids(activity.pid))
                                union
                                select unnest(pg_blocking_pids(blockers.pid))
                                from blockers
                            ) select 1 from blockers where pid = ?::integer
                        ) end as expected_blocker
                    from pg_stat_activity as activity where pid = ?
                    SQL,
                [$expectedBlockerPid, $expectedBlockerPid, $pid],
            );
            $lastObserved = $result === null ? null : [
                'state' => (string) $result->state,
                'wait_event_type' => (string) $result->wait_event_type,
                'wait_event' => (string) $result->wait_event,
                'blocker_count' => (int) $result->blocker_count,
                'has_ungranted_lock' => (int) $result->has_ungranted_lock,
                'expected_blocker' => filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL),
            ];
            if (($expectedBlockerPid === null && ($lastObserved['blocker_count'] ?? 0) > 0)
                || ($expectedBlockerPid !== null && ($lastObserved['expected_blocker'] ?? false))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf(
            'Expected PostgreSQL Queue blocking was not observed within %ds (elapsed %.2fs). observed=%s %s',
            $timeoutSeconds,
            microtime(true) - $started,
            json_encode($lastObserved, JSON_THROW_ON_ERROR),
            $this->processDiagnostics($process),
        ));
    }

    /** @param list<array{process: Process, input: InputStream}> $workers */
    private function diagnostics(array $workers): string
    {
        return implode(' | ', array_map(fn ($worker) => $this->processDiagnostics($worker['process']), $workers));
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim($process->getOutput()).' stderr='.trim($process->getErrorOutput());
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    private function workerOutput(array $workers): string
    {
        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    private function tearDownOrganisationOnly(): void
    {
        if ($this->organisationId === null) {
            return;
        }
        $id = $this->organisationId;
        DB::table('audit_logs')->where('organisation_id', $id)->delete();
        DB::table('queue_entries')->where('organisation_id', $id)->delete();
        DB::table('visit_reason_assignments')->where('organisation_id', $id)->delete();
        DB::table('queue_number_counters')->where('organisation_id', $id)->delete();
        DB::table('visits')->where('organisation_id', $id)->delete();
        DB::table('patients')->where('organisation_id', $id)->delete();
        DB::table('visit_reason_catalogue_items')->where('organisation_id', $id)->delete();
        $branchIds = DB::table('branches')->where('organisation_id', $id)->pluck('id');
        DB::table('staff_branch_assignments')->whereIn('branch_id', $branchIds)->delete();
        $userIds = DB::table('users')->where('organisation_id', $id)->pluck('id');
        DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
        DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->where('organisation_id', $id)->delete();
        DB::table('branches')->where('organisation_id', $id)->delete();
        DB::table('organisations')->where('id', $id)->delete();
        $this->organisationId = null;
    }
}
