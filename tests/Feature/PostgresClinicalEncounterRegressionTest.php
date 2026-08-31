<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class PostgresClinicalEncounterRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_clinical_regression_observer';

    private ?int $organisationId = null;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Clinical regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Clinical concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['clinical_encounters', 'encounter_vital_observations', 'encounter_diagnoses', 'queue_entries', 'visits'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Clinical regressions.');
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
            DB::table('encounter_diagnoses')->where('organisation_id', $this->organisationId)->delete();
            DB::table('encounter_vital_observations')->where('organisation_id', $this->organisationId)->delete();
            DB::table('clinical_encounters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('queue_entries')->where('organisation_id', $this->organisationId)->delete();
            DB::table('queue_number_counters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('visits')->where('organisation_id', $this->organisationId)->delete();
            DB::table('visit_number_counters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_identifiers')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patients')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_number_counters')->where('organisation_id', $this->organisationId)->delete();
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

    public function test_simultaneous_start_creates_one_encounter_and_one_audit(): void
    {
        $fixture = $this->fixture(true);
        $workers = [
            $this->startWorker($fixture['doctor'], $fixture['visit'], $fixture['queue']),
            $this->startWorker($fixture['doctor'], $fixture['visit'], $fixture['queue']),
        ];

        $this->runTogetherWhileParentBlocks(
            $workers,
            fn () => Visit::query()->whereKey($fixture['visit']->id)->lockForUpdate()->firstOrFail(),
        );

        $output = $this->workerOutput($workers);
        $this->assertSame(2, substr_count($output, 'STARTED '));
        $this->assertSame(1, DB::table('clinical_encounters')->where('visit_id', $fixture['visit']->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $fixture['organisation']->id)->where('event', 'encounter.started')->count());
    }

    public function test_start_racing_call_in_serializes_against_queue_state(): void
    {
        $fixture = $this->fixture(false);
        $start = $this->startWorker($fixture['doctor'], $fixture['visit'], $fixture['queue']);
        $call = $this->queueWorker([
            'call', (string) $fixture['operator']->id, $fixture['visit']->visit_number,
            (string) $fixture['branch']->id, (string) $fixture['visit']->lock_version,
            (string) $fixture['queue']->lock_version,
        ]);

        $this->runTogetherWhileParentBlocks(
            [$start, $call],
            fn () => Visit::query()->whereKey($fixture['visit']->id)->lockForUpdate()->firstOrFail(),
        );

        $this->assertSame(QueueEntry::STATUS_SERVING, $fixture['queue']->refresh()->status);
        $this->assertLessThanOrEqual(1, ClinicalEncounter::query()->where('visit_id', $fixture['visit']->id)->count());
        $this->assertStringContainsString('CALLED', $call['process']->getOutput());
    }

    public function test_start_and_update_revalidate_doctor_security_races(): void
    {
        foreach (['deactivate', 'change-role', 'change-assignment'] as $mode) {
            foreach (['start', 'update'] as $operation) {
                $fixture = $this->fixture(true, true);
                $encounter = $operation === 'update' ? $this->start($fixture) : null;
                $arguments = match ($mode) {
                    'deactivate' => [$mode, (string) $fixture['doctor']->id],
                    'change-role' => [$mode, (string) $fixture['administrator']->id, (string) $fixture['doctor']->id],
                    default => [$mode, (string) $fixture['administrator']->id, (string) $fixture['profile']->id, (string) $fixture['assignment']->id],
                };
                $mutation = $this->visitWorker($arguments);
                $mutation['process']->start();
                $this->waitReady([$mutation]);
                $mutation['input']->write("GO\n");
                $this->waitForOutput($mutation['process'], 'LOCKED');
                $clinical = $operation === 'start'
                    ? $this->startWorker($fixture['doctor'], $fixture['visit'], $fixture['queue'])
                    : $this->updateWorker($fixture['doctor'], $fixture['visit'], $encounter, 'SECURITY');
                $clinical['process']->start();
                $this->waitReady([$clinical]);
                $clinical['input']->write("GO\n");
                $clinical['input']->close();
                $this->waitForDatabaseBlock($clinical['process']);
                $mutation['input']->write("COMMIT\n");
                $mutation['input']->close();
                $mutation['process']->wait();
                $clinical['process']->wait();

                $this->assertSame(0, $mutation['process']->getExitCode(), $mutation['process']->getErrorOutput());
                $this->assertMatchesRegularExpression('/REJECTED|STALE/', $clinical['process']->getOutput());
                if ($operation === 'start') {
                    $this->assertDatabaseMissing('clinical_encounters', ['visit_id' => $fixture['visit']->id]);
                } else {
                    $this->assertNull($encounter->refresh()->clinical_note);
                    $this->assertSame(1, $encounter->lock_version);
                }
                $this->tearDownOrganisationOnly();
            }
        }
    }

    public function test_start_revalidates_attempted_visit_reassignment(): void
    {
        $fixture = $this->fixture(true, true);
        $mutation = $this->clinicalWorker([
            'reassign-direct', $fixture['visit']->visit_number, (string) $fixture['otherDoctor']->id,
        ]);
        $mutation['process']->start();
        $this->waitReady([$mutation]);
        $mutation['input']->write("GO\n");
        $this->waitForOutput($mutation['process'], 'LOCKED');
        $start = $this->startWorker($fixture['doctor'], $fixture['visit'], $fixture['queue']);
        $start['process']->start();
        $this->waitReady([$start]);
        $start['input']->write("GO\n");
        $start['input']->close();
        $this->waitForDatabaseBlock($start['process']);
        $mutation['input']->write("COMMIT\n");
        $mutation['input']->close();
        $mutation['process']->wait();
        $start['process']->wait();

        $this->assertStringContainsString('REJECTED', $start['process']->getOutput());
        $this->assertDatabaseMissing('clinical_encounters', ['visit_id' => $fixture['visit']->id]);
    }

    public function test_simultaneous_aggregate_and_diagnosis_replacements_reject_one_stale_writer(): void
    {
        $fixture = $this->fixture(true);
        $encounter = $this->start($fixture);
        $workers = [
            $this->updateWorker($fixture['doctor'], $fixture['visit'], $encounter, 'ALPHA'),
            $this->updateWorker($fixture['doctor'], $fixture['visit'], $encounter, 'BETA'),
        ];

        $this->runTogetherWhileParentBlocks(
            $workers,
            fn () => Visit::query()->whereKey($fixture['visit']->id)->lockForUpdate()->firstOrFail(),
        );

        $output = $this->workerOutput($workers);
        $this->assertSame(1, substr_count($output, 'UPDATED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(2, $encounter->refresh()->lock_version);
        $this->assertSame(1, DB::table('encounter_diagnoses')->where('clinical_encounter_id', $encounter->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'encounter.updated')->where('subject_id', $encounter->id)->count());
    }

    public function test_postgresql_late_audit_failure_rolls_back_children_and_version(): void
    {
        $fixture = $this->fixture(true);
        $encounter = $this->start($fixture);
        $failingAudit = new class extends AuditRecorder
        {
            public function record(
                string $event,
                ?Model $subject = null,
                array $metadata = [],
                ?User $actor = null,
                ?Branch $branch = null,
                ?int $organisationId = null,
            ): ?AuditLog {
                if ($event === 'encounter.updated') {
                    throw new RuntimeException('Injected PostgreSQL clinical audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new ClinicalEncounterService(app(BranchAccessService::class), $failingAudit);
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $fixture['branch']->id]);

        try {
            $service->update($fixture['doctor'], $fixture['visit'], $this->aggregate($encounter));
            $this->fail('Expected PostgreSQL clinical rollback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected PostgreSQL clinical audit failure.', $exception->getMessage());
        }

        $this->assertNull($encounter->refresh()->clinical_note);
        $this->assertSame(1, $encounter->lock_version);
        $this->assertDatabaseCount('encounter_diagnoses', 0);
        $this->assertDatabaseMissing('encounter_vital_observations', [
            'clinical_encounter_id' => $encounter->id,
        ]);
    }

    public function test_cross_branch_and_cross_organisation_workers_cannot_start(): void
    {
        $fixture = $this->fixture(true, true);
        $otherBranch = $fixture['otherBranch'];
        $otherOrganisation = new Organisation;
        $otherOrganisation->forceFill([
            'code' => 'CLINICAL_PG_OUTSIDER_'.Str::upper(Str::random(6)),
            'name' => 'Synthetic Clinical Outsider',
            'is_active' => true,
        ])->save();
        $outsiderBranch = $this->branch($otherOrganisation, 'OUT');
        $outsider = $this->user($otherOrganisation, $outsiderBranch, 'resident_doctor', [
            'encounters.start.own', 'encounters.view.own', 'encounters.update.own',
        ]);
        foreach ([
            [$fixture['doctor'], $otherBranch],
            [$outsider, $outsiderBranch],
        ] as [$actor, $branch]) {
            $worker = $this->clinicalWorker([
                'start', (string) $actor->id, $fixture['visit']->visit_number, (string) $branch->id,
                (string) $fixture['visit']->lock_version, (string) $fixture['queue']->lock_version,
            ]);
            $this->runTogether([$worker]);
            $this->assertStringContainsString('REJECTED', $worker['process']->getOutput());
        }
        $this->assertDatabaseMissing('clinical_encounters', ['visit_id' => $fixture['visit']->id]);

        DB::table('staff_branch_assignments')->where('staff_profile_id', $outsider->staffProfile->id)->delete();
        DB::table('model_has_permissions')->where('model_type', User::class)->where('model_id', $outsider->id)->delete();
        DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $outsider->id)->delete();
        $outsider->staffProfile()->delete();
        $outsider->delete();
        $outsiderBranch->delete();
        $otherOrganisation->delete();
    }

    /** @return array<string, mixed> */
    private function fixture(bool $serving, bool $security = false): array
    {
        $suffix = Str::lower(Str::random(8));
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'CLINICAL_PG_'.Str::upper($suffix),
            'name' => 'Synthetic Clinical PG',
            'is_active' => true,
        ])->save();
        $this->organisationId = $organisation->id;
        $branch = $this->branch($organisation, 'ONE');
        $otherBranch = $this->branch($organisation, 'TWO');
        $operator = $this->user($organisation, $branch, null, [
            'queue.view.branch', 'queue.enter.branch', 'queue.call.branch',
            'visits.view.branch', 'branch_context.switch.organisation',
        ]);
        $doctor = $this->user($organisation, $branch, 'resident_doctor', [
            'branch_context.switch.branch', 'queue.view.own', 'queue.call.own',
            'encounters.view.own', 'encounters.start.own',
            'encounters.update.own', 'encounters.history.view.organisation',
        ]);
        $profile = $doctor->staffProfile;
        $assignment = $profile->branchAssignments()->firstOrFail();
        $otherDoctor = $this->user($organisation, $branch, 'resident_doctor', []);
        $administrator = null;
        if ($security) {
            Role::findOrCreate('ca', 'web');
            $administrator = $this->user($organisation, $branch, 'director', ['access.manage.organisation']);
        }
        $patient = new Patient;
        $patient->forceFill([
            'organisation_id' => $organisation->id,
            'patient_number' => sprintf('KP-%08d', 95_000_000 + $this->sequence),
            'full_name' => 'Synthetic Clinical Patient '.$this->sequence,
            'search_name' => 'synthetic clinical patient '.$this->sequence,
            'sex' => 'unknown',
            'lock_version' => 1,
        ])->save();
        $visit = new Visit;
        $visit->forceFill([
            'organisation_id' => $organisation->id,
            'branch_id' => $branch->id,
            'patient_id' => $patient->id,
            'visit_number' => sprintf('KPV-%08d', 95_000_000 + $this->sequence++),
            'idempotency_key' => (string) Str::uuid(),
            'visit_type' => 'consultation',
            'status' => Visit::STATUS_REGISTERED,
            'priority' => 'normal',
            'visit_reason' => 'Synthetic PostgreSQL Clinical reason',
            'assigned_doctor_user_id' => $doctor->id,
            'coverage_type' => 'self_pay',
            'registered_at' => now()->utc(),
            'registered_by_user_id' => $operator->id,
            'updated_by_user_id' => $operator->id,
            'lock_version' => 1,
        ])->save();
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $queue = app(QueueEntryService::class)->enter($operator, $visit, [
            'expected_branch_id' => $branch->id,
            'visit_lock_version' => $visit->lock_version,
        ]);
        if ($serving) {
            $queue = app(QueueEntryService::class)->call($operator, $visit, [
                'expected_branch_id' => $branch->id,
                'visit_lock_version' => $visit->lock_version,
                'queue_lock_version' => $queue->lock_version,
            ]);
        }

        return compact(
            'organisation', 'branch', 'otherBranch', 'operator', 'doctor', 'profile',
            'assignment', 'otherDoctor', 'administrator', 'patient', 'visit', 'queue',
        );
    }

    private function branch(Organisation $organisation, string $code): Branch
    {
        $branch = new Branch;
        $branch->forceFill([
            'organisation_id' => $organisation->id,
            'code' => $code,
            'name' => 'Synthetic '.$code,
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();

        return $branch;
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, ?string $role, array $permissions): User
    {
        $user = new User;
        $user->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'Synthetic Clinical User',
            'email' => 'clinical.pg.'.Str::lower(Str::random(10)).'@kpone.test',
            'is_active' => true,
        ])->save();
        if ($role !== null) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);

        return $user->refresh();
    }

    /** @param array<string, mixed> $fixture */
    private function start(array $fixture): ClinicalEncounter
    {
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $fixture['branch']->id]);

        return app(ClinicalEncounterService::class)->start($fixture['doctor'], $fixture['visit'], [
            'expected_branch_id' => $fixture['branch']->id,
            'visit_lock_version' => $fixture['visit']->lock_version,
            'queue_lock_version' => $fixture['queue']->lock_version,
        ]);
    }

    /** @return array<string, mixed> */
    private function aggregate(ClinicalEncounter $encounter): array
    {
        return [
            'expected_branch_id' => $encounter->branch_id,
            'lock_version' => $encounter->lock_version,
            'clinical_note' => 'Synthetic PostgreSQL clinical note',
            'vitals' => [
                'systolic_bp' => 120, 'diastolic_bp' => 80, 'pulse_bpm' => 70,
                'temperature_celsius' => 36.8, 'spo2_percent' => 98,
                'weight_kg' => 60, 'height_cm' => 160,
            ],
            'diagnoses' => [[
                'diagnosis_text' => 'Synthetic PostgreSQL diagnosis',
                'diagnosis_code' => null, 'code_system' => null, 'is_primary' => true,
            ]],
        ];
    }

    private function startWorker(User $actor, Visit $visit, QueueEntry $queue): array
    {
        return $this->clinicalWorker([
            'start', (string) $actor->id, $visit->visit_number, (string) $visit->branch_id,
            (string) $visit->lock_version, (string) $queue->lock_version,
        ]);
    }

    private function updateWorker(User $actor, Visit $visit, ClinicalEncounter $encounter, string $token): array
    {
        return $this->clinicalWorker([
            'update', (string) $actor->id, $visit->visit_number, (string) $visit->branch_id,
            (string) $encounter->lock_version, $token,
        ]);
    }

    private function clinicalWorker(array $arguments): array
    {
        return $this->process(base_path('tests/Support/PostgresClinicalEncounterWorker.php'), $arguments);
    }

    private function queueWorker(array $arguments): array
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
        $name = 'kpone-clinical-pg-'.Str::lower(Str::random(10));
        $process = new Process([PHP_BINARY, $script, ...$arguments, $name], base_path());
        $process->setInput($input);
        $process->setTimeout(30);
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

    private function runTogether(array $workers): void
    {
        foreach ($workers as $worker) {
            $worker['process']->start();
        }
        $this->waitReady($workers);
        foreach ($workers as $worker) {
            $worker['input']->write("GO\n");
            $worker['input']->close();
        }
        foreach ($workers as $worker) {
            $worker['process']->wait();
            $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        }
    }

    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Clinical worker did not report ready.');
    }

    private function waitForOutput(Process $process, string $needle): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($process->getOutput(), $needle)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Clinical worker protocol timeout.');
    }

    private function waitForDatabaseBlock(Process $process, ?int $expectedBlockerPid = null): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('Clinical worker did not report a valid PostgreSQL backend PID.');
        }
        $deadline = microtime(true) + 10;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Clinical worker exited before PostgreSQL blocking was observed.');
            }
            $result = $this->observer()->selectOne(
                <<<'SQL'
                    select
                        cardinality(pg_blocking_pids(pid)) as blocker_count,
                        case when ?::integer is null then false else exists (
                            with recursive blockers(pid) as (
                                select unnest(pg_blocking_pids(activity.pid))
                                union
                                select unnest(pg_blocking_pids(blockers.pid)) from blockers
                            ) select 1 from blockers where pid = ?::integer
                        ) end as expected_blocker
                    from pg_stat_activity as activity where pid = ?
                    SQL,
                [$expectedBlockerPid, $expectedBlockerPid, $pid],
            );
            if ($result !== null && (($expectedBlockerPid === null && (int) $result->blocker_count > 0)
                || ($expectedBlockerPid !== null && filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL)))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Expected PostgreSQL Clinical blocking was not observed.');
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
        DB::table('encounter_diagnoses')->where('organisation_id', $id)->delete();
        DB::table('encounter_vital_observations')->where('organisation_id', $id)->delete();
        DB::table('clinical_encounters')->where('organisation_id', $id)->delete();
        DB::table('queue_entries')->where('organisation_id', $id)->delete();
        DB::table('queue_number_counters')->where('organisation_id', $id)->delete();
        DB::table('visits')->where('organisation_id', $id)->delete();
        DB::table('patients')->where('organisation_id', $id)->delete();
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
