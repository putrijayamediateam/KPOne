<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Patient\Services\PatientIdentityService;
use App\Domain\Patient\Services\PatientNumberGenerator;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\TestCase;

class PostgresPatientMasterRegressionTest extends TestCase
{
    private ?int $organisationId = null;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $workerInputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Patient Master regression coverage requires DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Patient concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['patients', 'patient_identifiers', 'patient_number_counters', 'audit_logs'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before running patient regressions.');
            }
        }
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

        if ($this->organisationId !== null) {
            DB::table('audit_logs')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_identifiers')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patients')->where('organisation_id', $this->organisationId)->delete();
            DB::table('patient_number_counters')->where('organisation_id', $this->organisationId)->delete();
            $userIds = DB::table('users')->where('organisation_id', $this->organisationId)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('users')->where('organisation_id', $this->organisationId)->delete();
            DB::table('organisations')->where('id', $this->organisationId)->delete();
        }
        parent::tearDown();
    }

    public function test_concurrent_full_service_duplicate_nric_has_one_canonical_result_and_no_partial_state(): void
    {
        [$organisation, $actor] = $this->fixture();
        $auditCountBefore = DB::table('audit_logs')->where('organisation_id', $organisation->id)->count();
        $workers = [
            $this->serviceWorker($actor, 'alpha'.Str::lower(Str::random(4)), '900101011234'),
            $this->serviceWorker($actor, 'beta'.Str::lower(Str::random(4)), '900101011234'),
        ];
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
        $output = implode("\n", array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
        $this->assertSame(1, substr_count($output, 'CREATED KP-'));
        $this->assertSame(1, substr_count($output, 'DUPLICATE'));
        $this->assertSame(1, DB::table('patients')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(1, DB::table('patient_identifiers')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(2, DB::table('patient_number_counters')->where('organisation_id', $organisation->id)->value('next_value'));
        $this->assertSame($auditCountBefore + 2, DB::table('audit_logs')->where('organisation_id', $organisation->id)->count());
    }

    public function test_genuine_unique_index_contention_returns_sqlstate_23505(): void
    {
        [$organisation, $actor] = $this->fixture();
        $service = app(PatientAdministrationService::class);
        $first = $service->create($actor, $this->attributes('Raw A'));
        $second = $service->create($actor, $this->attributes('Raw B'));
        $hold = $this->rawWorker($organisation->id, $first->id, 'CONTENDED-PASSPORT', true);
        $wait = $this->rawWorker($organisation->id, $second->id, 'CONTENDED-PASSPORT', false);
        $hold['process']->start();
        $this->waitReady([$hold]);
        $hold['input']->write("GO\n");
        $this->waitForOutput($hold['process'], 'INSERTED');
        $wait['process']->start();
        $this->waitReady([$wait]);
        $wait['input']->write("GO\n");
        $wait['input']->close();
        $this->waitForOutput($wait['process'], 'ATTEMPTING');
        $waitPid = $this->protocolPid($wait['process']->getOutput());
        $this->waitForLock($waitPid);
        $hold['input']->write("COMMIT\n");
        $hold['input']->close();
        $hold['process']->wait();
        $wait['process']->wait();
        $this->assertStringContainsString('COMMITTED', $hold['process']->getOutput());
        $this->assertStringContainsString('SQLSTATE 23505', $wait['process']->getOutput());
    }

    public function test_concurrent_different_patient_creation_allocates_distinct_numbers_from_an_existing_counter(): void
    {
        [$organisation, $actor] = $this->fixture();
        DB::table('patient_number_counters')->insert([
            'organisation_id' => $organisation->id,
            'next_value' => 41,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workers = [
            $this->serviceWorker($actor, 'epsilon'.Str::lower(Str::random(4)), '940404044444'),
            $this->serviceWorker($actor, 'zeta'.Str::lower(Str::random(4)), '950505055555'),
        ];
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
            $this->assertSame(0, $worker['process']->getExitCode());
        }
        $numbers = DB::table('patients')->where('organisation_id', $organisation->id)->pluck('patient_number')->sort()->values()->all();
        $this->assertSame(['KP-00000041', 'KP-00000042'], $numbers);
        $this->assertSame(43, DB::table('patient_number_counters')->where('organisation_id', $organisation->id)->value('next_value'));
    }

    public function test_concurrent_first_counter_initialization_creates_one_counter_and_distinct_numbers(): void
    {
        [$organisation, $actor] = $this->fixture();
        $workers = [
            $this->serviceWorker($actor, 'gamma'.Str::lower(Str::random(4)), '910101011111'),
            $this->serviceWorker($actor, 'delta'.Str::lower(Str::random(4)), '920202022222'),
        ];
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
            $this->assertSame(0, $worker['process']->getExitCode());
        }
        $numbers = DB::table('patients')->where('organisation_id', $organisation->id)->pluck('patient_number')->sort()->values()->all();
        $this->assertSame(['KP-00000001', 'KP-00000002'], $numbers);
        $this->assertSame(3, DB::table('patient_number_counters')->where('organisation_id', $organisation->id)->value('next_value'));
        $this->assertSame(1, DB::table('patient_number_counters')->where('organisation_id', $organisation->id)->count());
    }

    public function test_late_audit_failure_rolls_back_patient_identifier_audit_and_counter(): void
    {
        [$organisation, $actor] = $this->fixture();
        $auditCountBefore = DB::table('audit_logs')->where('organisation_id', $organisation->id)->count();
        $failingAudit = new class extends AuditRecorder
        {
            public function record(string $event, ?Model $subject = null, array $metadata = [], ?User $actor = null, ?Branch $branch = null, ?int $organisationId = null): ?AuditLog
            {
                if ($event === 'patient.created') {
                    throw new RuntimeException('Injected patient audit failure.');
                }

                return parent::record($event, $subject, $metadata, $actor, $branch, $organisationId);
            }
        };
        $service = new PatientAdministrationService(app(PatientIdentityService::class), app(PatientNumberGenerator::class), $failingAudit);
        try {
            $service->create($actor, $this->attributes('Rollback', '930303033333'));
            $this->fail('Expected injected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected patient audit failure.', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('patients')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(0, DB::table('patient_identifiers')->where('organisation_id', $organisation->id)->count());
        $this->assertSame($auditCountBefore, DB::table('audit_logs')->where('organisation_id', $organisation->id)->count());
        $this->assertSame(0, DB::table('patient_number_counters')->where('organisation_id', $organisation->id)->count());
    }

    /** @return array{Organisation, User} */
    private function fixture(): array
    {
        $suffix = Str::lower(Str::random(10));
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'PATIENT_PG_'.Str::upper($suffix), 'name' => 'Synthetic Patient PG Test', 'is_active' => true])->save();
        $this->organisationId = $organisation->id;
        $actor = new User;
        $actor->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic PG Actor', 'email' => 'patient.pg.'.$suffix.'@kpone.test', 'is_active' => true])->save();
        $actor->givePermissionTo(Permission::findOrCreate('patients.create.organisation', 'web'));

        return [$organisation, $actor];
    }

    private function attributes(string $name, ?string $nric = null): array
    {
        return ['full_name' => 'Synthetic '.$name, 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'mobile_phone' => '+60123456789', 'duplicate_override' => true,
            'identifiers' => $nric ? [['identifier_type' => 'nric', 'value' => $nric]] : [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-'.Str::upper(Str::random(12))]]];
    }

    private function serviceWorker(User $actor, string $suffix, string $nric): array
    {
        return $this->worker(['service', (string) $actor->id, $suffix, $nric]);
    }

    private function rawWorker(int $organisationId, int $patientId, string $value, bool $hold): array
    {
        return $this->worker(['raw', (string) $organisationId, (string) $patientId, $value, $hold ? 'hold' : 'release']);
    }

    private function worker(array $arguments): array
    {
        $input = new InputStream;
        $name = 'kpone-patient-pg-'.Str::lower(Str::random(12));
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPatientCreationWorker.php'), ...$arguments, $name], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->workerInputs[] = $input;

        return ['process' => $process, 'input' => $input];
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
        throw new RuntimeException(sprintf('Patient workers did not report ready within %ds (elapsed %.2fs). %s', $timeoutSeconds, microtime(true) - $started, $this->diagnostics($workers)));
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
        throw new RuntimeException(sprintf('Patient worker protocol timeout for %s within %ds (elapsed %.2fs). %s', $needle, $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
    }

    private function protocolPid(string $output): int
    {
        preg_match('/READY ([1-9][0-9]*)/', $output, $matches);

        return (int) ($matches[1] ?? 0);
    }

    private function waitForLock(int $pid): void
    {
        if ($pid <= 0) {
            throw new RuntimeException('Patient worker did not report a valid PostgreSQL backend PID.');
        }

        $timeoutSeconds = ContentionTimeouts::protocolTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            $blocked = DB::selectOne('select cardinality(pg_blocking_pids(?)) as blocker_count', [$pid]);
            if ((int) ($blocked->blocker_count ?? 0) > 0) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('PostgreSQL unique-index contention was not observed within %ds (elapsed %.2fs) for backend pid %d.', $timeoutSeconds, microtime(true) - $started, $pid));
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
}
