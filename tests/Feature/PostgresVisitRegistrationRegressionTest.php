<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Domain\Visit\Services\VisitDoctorEligibilityService;
use App\Domain\Visit\Services\VisitNumberGenerator;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
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
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class PostgresVisitRegistrationRegressionTest extends TestCase
{
    private const OBSERVER_CONNECTION = 'pgsql_visit_regression_observer';

    private ?int $organisationId = null;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Visit registration regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Visit concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['visits', 'visit_number_counters', 'panels', 'patients', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Visit regressions.');
            }
        }

        Config::set(
            'database.connections.'.self::OBSERVER_CONNECTION,
            config('database.connections.'.config('database.default')),
        );
        DB::purge(self::OBSERVER_CONNECTION);
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
            DB::table('visits')->where('organisation_id', $this->organisationId)->delete();
            DB::table('visit_number_counters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_identifiers')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patients')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_number_counters')->where('organisation_id', $this->organisationId)->delete();
            DB::table('staff_branch_assignments')->whereIn('branch_id', DB::table('branches')->where('organisation_id', $this->organisationId)->pluck('id'))->delete();
            $userIds = DB::table('users')->where('organisation_id', $this->organisationId)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->where('organisation_id', $this->organisationId)->delete();
            DB::table('branches')->where('organisation_id', $this->organisationId)->delete();
            DB::table('organisations')->where('id', $this->organisationId)->delete();
        }
        DB::purge(self::OBSERVER_CONNECTION);
        parent::tearDown();
    }

    public function test_concurrent_same_idempotency_key_creates_one_logical_visit(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $key = (string) Str::uuid();
        $workers = [
            $this->worker(['register', (string) $actors[0]->id, 'QUICK_IDENTIFIER', (string) $branches[0]->id, $key, 'no', 'none']),
            $this->worker(['register', (string) $actors[1]->id, 'QUICK_IDENTIFIER', (string) $branches[0]->id, $key, 'no', 'none']),
        ];
        $this->runTogetherWhileParentBlocks($workers, function () use ($organisation): void {
            $now = now();
            DB::table('patient_number_counters')->insert([
                'organisation_id' => $organisation->id,
                'next_value' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $numbers = DB::table('visits')->where('organisation_id', $organisation->id)->pluck('visit_number');
        $this->assertCount(1, $numbers);
        $this->assertSame(2, substr_count(implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers)), 'VISIT KPV-'));
        $this->assertSame(1, DB::table('patients')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(1, DB::table('patient_identifiers')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(2, DB::table('patient_number_counters')->where('organisation_id', $organisation->id)->value('next_value'));
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $organisation->id)->where('event', 'visit.created')->count());
        $this->assertSame(2, DB::table('visit_number_counters')->where('organisation_id', $organisation->id)->value('next_value'));
    }

    public function test_concurrent_counter_initialization_and_existing_allocation_are_distinct(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $patients = [$this->patient($organisation, 'KP-90000001'), $this->patient($organisation, 'KP-90000002')];
        $this->runTogetherWhileParentBlocks([
            $this->registerWorker($actors[0], $patients[0], $branches[0], (string) Str::uuid()),
            $this->registerWorker($actors[1], $patients[1], $branches[0], (string) Str::uuid()),
        ], fn () => DB::statement('LOCK TABLE visit_number_counters IN ACCESS EXCLUSIVE MODE'));
        $this->assertSame(['KPV-00000001', 'KPV-00000002'], DB::table('visits')->where('organisation_id', $organisation->id)->pluck('visit_number')->sort()->values()->all());

        $third = $this->patient($organisation, 'KP-90000003');
        $fourth = $this->patient($organisation, 'KP-90000004');
        $this->runTogetherWhileParentBlocks([
            $this->registerWorker($actors[0], $third, $branches[0], (string) Str::uuid()),
            $this->registerWorker($actors[1], $fourth, $branches[0], (string) Str::uuid()),
        ], fn () => DB::table('visit_number_counters')
            ->where('organisation_id', $organisation->id)
            ->lockForUpdate()
            ->first());
        $this->assertSame(5, DB::table('visit_number_counters')->where('organisation_id', $organisation->id)->value('next_value'));
    }

    public function test_concurrent_different_keys_serialize_repeat_warning_and_different_branches_remain_valid(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $patient = $this->patient($organisation, 'KP-90000001');
        $sameBranch = [
            $this->registerWorker($actors[0], $patient, $branches[0], (string) Str::uuid()),
            $this->registerWorker($actors[1], $patient, $branches[0], (string) Str::uuid()),
        ];
        $this->runTogetherWhileParentBlocks($sameBranch, fn () => Patient::query()
            ->whereKey($patient->id)
            ->lockForUpdate()
            ->firstOrFail());
        $output = implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $sameBranch));
        $this->assertSame(1, substr_count($output, 'VISIT KPV-'));
        $this->assertSame(1, substr_count($output, 'REPEAT'));

        $otherPatient = $this->patient($organisation, 'KP-90000002');
        $differentBranches = [
            $this->registerWorker($actors[0], $otherPatient, $branches[0], (string) Str::uuid()),
            $this->registerWorker($actors[1], $otherPatient, $branches[1], (string) Str::uuid()),
        ];
        $this->runTogetherWhileParentBlocks($differentBranches, fn () => Patient::query()
            ->whereKey($otherPatient->id)
            ->lockForUpdate()
            ->firstOrFail());
        $this->assertSame(2, DB::table('visits')->where('patient_id', $otherPatient->id)->count());
    }

    public function test_update_cancel_contention_accepts_one_and_rejects_stale_operation(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $patient = $this->patient($organisation, 'KP-90000001');
        $created = $this->registerWorker($actors[0], $patient, $branches[0], (string) Str::uuid());
        $this->runTogether([$created]);
        $visitNumber = (string) DB::table('visits')->where('organisation_id', $organisation->id)->value('visit_number');
        $workers = [
            $this->worker(['update', (string) $actors[0]->id, $visitNumber, (string) $branches[0]->id, '1']),
            $this->worker(['cancel', (string) $actors[1]->id, $visitNumber, (string) $branches[0]->id, '1']),
        ];
        $this->runTogetherWhileParentBlocks($workers, fn () => Patient::query()
            ->whereKey($patient->id)
            ->lockForUpdate()
            ->firstOrFail());
        $output = implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
        $this->assertSame(1, substr_count($output, 'CHANGED'));
        $this->assertSame(1, substr_count($output, 'STALE'));
        $this->assertSame(2, DB::table('visits')->where('visit_number', $visitNumber)->value('lock_version'));
    }

    public function test_doctor_deactivation_race_serializes_to_a_valid_rejection(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $patient = $this->patient($organisation, 'KP-90000001');
        $doctor = new User;
        $doctor->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'Synthetic Concurrent Doctor',
            'email' => 'doctor.'.Str::lower(Str::random(8)).'@kpone.test',
            'is_active' => true,
        ])->save();
        $doctor->assignRole(Role::findOrCreate('resident_doctor', 'web'));
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $doctor->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branches[0], [
            'assignment_type' => 'temporary', 'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString(),
        ]);

        $deactivate = $this->worker(['deactivate', (string) $doctor->id]);
        $deactivate['process']->start();
        $this->waitReady([$deactivate]);
        $deactivate['input']->write("GO\n");
        $this->waitForOutput($deactivate['process'], 'LOCKED');
        $registration = $this->worker([
            'register', (string) $actors[0]->id, $patient->patient_number, (string) $branches[0]->id,
            (string) Str::uuid(), 'no', (string) $doctor->id,
        ]);
        $registration['process']->start();
        $this->waitReady([$registration]);
        $registration['input']->write("GO\n");
        $this->waitForDatabaseBlock($registration['process']);
        $deactivate['input']->write("COMMIT\n");
        $deactivate['input']->close();
        $registration['input']->close();
        $deactivate['process']->wait();
        $registration['process']->wait();

        $this->assertStringContainsString('DEACTIVATED', $deactivate['process']->getOutput());
        $this->assertStringContainsString('INVALID', $registration['process']->getOutput());
        $this->assertSame(0, DB::table('visits')->where('organisation_id', $organisation->id)->count());
    }

    public function test_doctor_role_change_race_serializes_to_a_valid_rejection(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $patient = $this->patient($organisation, 'KP-90000001');
        [$doctor] = $this->doctor($organisation, $branches[0]);
        $administrator = $this->securityAdministrator($organisation);
        $mutation = $this->worker(['change-role', (string) $administrator->id, (string) $doctor->id]);
        $registration = $this->worker([
            'register', (string) $actors[0]->id, $patient->patient_number, (string) $branches[0]->id,
            (string) Str::uuid(), 'no', (string) $doctor->id,
        ]);

        $this->runDoctorMutationRace($mutation, $registration);

        $this->assertStringContainsString('ROLE_CHANGED', $mutation['process']->getOutput());
        $this->assertStringContainsString('INVALID', $registration['process']->getOutput());
        $this->assertSame(0, DB::table('visits')->where('organisation_id', $organisation->id)->count());
    }

    public function test_doctor_assignment_change_race_serializes_to_a_valid_rejection(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $patient = $this->patient($organisation, 'KP-90000001');
        [$doctor, $profile, $assignment] = $this->doctor($organisation, $branches[0]);
        $administrator = $this->securityAdministrator($organisation);
        $mutation = $this->worker([
            'change-assignment', (string) $administrator->id, (string) $profile->id, (string) $assignment->id,
        ]);
        $registration = $this->worker([
            'register', (string) $actors[0]->id, $patient->patient_number, (string) $branches[0]->id,
            (string) Str::uuid(), 'no', (string) $doctor->id,
        ]);

        $this->runDoctorMutationRace($mutation, $registration);

        $this->assertStringContainsString('ASSIGNMENT_CHANGED', $mutation['process']->getOutput());
        $this->assertStringContainsString('INVALID', $registration['process']->getOutput());
        $this->assertSame(0, DB::table('visits')->where('organisation_id', $organisation->id)->count());
    }

    public function test_branch_local_day_uses_malaysia_midnight_on_postgresql(): void
    {
        try {
            Date::setTestNow('2026-08-27 15:59:00 UTC');
            [$organisation, $branches, $actors] = $this->fixture();
            $patient = $this->patient($organisation, 'KP-90000001');
            app('session')->start();
            session([BranchAccessService::SESSION_KEY => $branches[0]->id]);
            $beforeMidnight = app(VisitRegistrationService::class)->register($actors[0], [
                'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $branches[0]->id,
                'patient_number' => $patient->patient_number, 'visit_type' => 'otc', 'priority' => 'normal',
                'coverage_type' => 'self_pay',
            ]);

            Date::setTestNow('2026-08-27 16:01:00 UTC');
            $afterMidnight = app(VisitRegistrationService::class)->register($actors[0], [
                'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $branches[0]->id,
                'patient_number' => $patient->patient_number, 'visit_type' => 'otc', 'priority' => 'normal',
                'coverage_type' => 'self_pay',
            ]);
            $directory = app(VisitDirectoryService::class);

            $this->assertSame('2026-08-27', $beforeMidnight->registered_at->setTimezone('Asia/Kuala_Lumpur')->toDateString());
            $this->assertSame('2026-08-28', $afterMidnight->registered_at->setTimezone('Asia/Kuala_Lumpur')->toDateString());
            $this->assertSame(1, $directory->search($actors[0], ['date_from' => '2026-08-27', 'date_to' => '2026-08-27'])['total']);
            $this->assertSame(1, $directory->search($actors[0], ['date_from' => '2026-08-28', 'date_to' => '2026-08-28'])['total']);
        } finally {
            Date::setTestNow();
        }
    }

    public function test_late_quick_patient_visit_failure_rolls_back_both_counters_and_audits(): void
    {
        [$organisation, $branches, $actors] = $this->fixture();
        $fixtureAuditCount = DB::table('audit_logs')->where('organisation_id', $organisation->id)->count();
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $branches[0]->id]);
        $failingAudit = new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                if ($event === 'visit.created') {
                    throw new RuntimeException('Injected Visit audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new VisitRegistrationService(
            app(BranchAccessService::class),
            app(PatientAdministrationService::class),
            app(VisitDoctorEligibilityService::class),
            app(VisitNumberGenerator::class),
            $failingAudit,
        );
        try {
            $service->register($actors[0], [
                'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $branches[0]->id,
                'quick_patient' => ['full_name' => 'Synthetic Rollback', 'sex' => 'unknown', 'duplicate_override' => true],
                'visit_type' => 'otc', 'priority' => 'normal', 'coverage_type' => 'self_pay',
            ]);
            $this->fail('Expected injected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected Visit audit failure.', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('patients')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(0, DB::table('visits')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(0, DB::table('patient_number_counters')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(0, DB::table('visit_number_counters')->where('organisation_id', $organisation->id)->count());
        $this->assertSame($fixtureAuditCount, DB::table('audit_logs')->where('organisation_id', $organisation->id)->count());
    }

    /** @return array{Organisation, list<Branch>, list<User>} */
    private function fixture(): array
    {
        $suffix = Str::lower(Str::random(10));
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'VISIT_PG_'.Str::upper($suffix), 'name' => 'Synthetic Visit PG Test', 'is_active' => true])->save();
        $this->organisationId = $organisation->id;
        $branches = collect(['ONE', 'TWO'])->map(function (string $code) use ($organisation): Branch {
            $branch = new Branch;
            $branch->forceFill(['organisation_id' => $organisation->id, 'code' => $code, 'name' => 'Synthetic '.$code, 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();

            return $branch;
        })->all();
        $permissions = ['patients.create.organisation', 'visits.view.branch', 'visits.create.branch', 'visits.update.branch', 'visits.cancel.branch', 'branch_context.switch.organisation'];
        $actors = collect([1, 2])->map(function (int $number) use ($organisation, $suffix, $permissions): User {
            $actor = new User;
            $actor->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Actor '.$number, 'email' => "visit.pg.{$number}.{$suffix}@kpone.test", 'is_active' => true])->save();
            foreach ($permissions as $permission) {
                $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }

            return $actor;
        })->all();

        return [$organisation, $branches, $actors];
    }

    private function patient(Organisation $organisation, string $number): Patient
    {
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $organisation->id, 'patient_number' => $number, 'full_name' => 'Synthetic PG Patient '.$number, 'search_name' => strtolower($number), 'sex' => 'unknown', 'lock_version' => 1])->save();

        return $patient;
    }

    /** @return array{User, StaffProfile, StaffBranchAssignment} */
    private function doctor(Organisation $organisation, Branch $branch): array
    {
        $doctor = new User;
        $doctor->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'Synthetic Concurrent Doctor',
            'email' => 'doctor.'.Str::lower(Str::random(8)).'@kpone.test',
            'is_active' => true,
        ])->save();
        $doctor->assignRole(Role::findOrCreate('resident_doctor', 'web'));
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $doctor->id, 'department_id' => null])->save();
        $assignment = StaffBranchAssignmentBootstrapper::create($profile, $branch, [
            'assignment_type' => 'temporary',
            'is_primary' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addDay()->toDateString(),
        ]);

        return [$doctor, $profile, $assignment];
    }

    private function securityAdministrator(Organisation $organisation): User
    {
        $administrator = new User;
        $administrator->forceFill([
            'organisation_id' => $organisation->id,
            'name' => 'Synthetic Security Administrator',
            'email' => 'security.admin.'.Str::lower(Str::random(8)).'@kpone.test',
            'is_active' => true,
        ])->save();
        $administrator->assignRole(Role::findOrCreate('director', 'web'));
        $administrator->givePermissionTo(Permission::findOrCreate('access.manage.organisation', 'web'));
        foreach (Role::findOrCreate('ca', 'web')->permissions as $permission) {
            $administrator->givePermissionTo($permission);
        }

        return $administrator;
    }

    private function registerWorker(User $actor, Patient $patient, Branch $branch, string $key): array
    {
        return $this->worker(['register', (string) $actor->id, $patient->patient_number, (string) $branch->id, $key, 'no', 'none']);
    }

    private function worker(array $arguments): array
    {
        $input = new InputStream;
        $name = 'kpone-visit-pg-'.Str::lower(Str::random(10));
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresVisitRegistrationWorker.php'), ...$arguments, $name], base_path());
        $process->setInput($input);
        $process->setTimeout(30);
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
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

    private function runDoctorMutationRace(array $mutation, array $registration): void
    {
        $mutation['process']->start();
        $this->waitReady([$mutation]);
        $mutation['input']->write("GO\n");
        $this->waitForOutput($mutation['process'], 'LOCKED');

        $registration['process']->start();
        $this->waitReady([$registration]);
        $registration['input']->write("GO\n");
        $registration['input']->close();
        $this->waitForDatabaseBlock($registration['process']);

        $mutation['input']->write("COMMIT\n");
        $mutation['input']->close();
        $mutation['process']->wait();
        $registration['process']->wait();
        $this->assertSame(0, $mutation['process']->getExitCode(), $mutation['process']->getErrorOutput());
        $this->assertSame(0, $registration['process']->getExitCode(), $registration['process']->getErrorOutput());
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
        throw new RuntimeException('Visit worker did not report ready.');
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
        throw new RuntimeException('Visit worker protocol timeout.');
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
            throw new RuntimeException('Visit worker did not report a valid PostgreSQL backend PID.');
        }

        $deadline = microtime(true) + 10;
        $lastObserved = null;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException(
                    'Visit worker exited before PostgreSQL blocking was observed. exit='.(string) $process->getExitCode(),
                );
            }

            $result = $this->observerConnection()->selectOne(
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
                            )
                            select 1 from blockers where pid = ?::integer
                        ) end as expected_blocker
                    from pg_stat_activity as activity
                    where pid = ?
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
        throw new RuntimeException(
            'Expected PostgreSQL in-flight blocking was not observed. observed='
            .json_encode($lastObserved, JSON_THROW_ON_ERROR),
        );
    }

    private function observerConnection(): Connection
    {
        return DB::connection(self::OBSERVER_CONNECTION);
    }
}
