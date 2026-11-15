<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Incidents\IncidentPermissions;
use App\Domain\HR\Incidents\Services\IncidentService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
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

/**
 * HR-10d under real PostgreSQL contention. An action racing the close never lands on a closed report; two managers
 * acknowledging one report record one acknowledgement. Each race holds the incident row in this test's session until
 * both workers are proven to be waiting.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresIncidentsContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_incidents_observer';

    /** @var list<int> */
    private array $organisationIds = [];

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL incident regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Incident concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_incidents')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before incident regressions.');
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
        foreach ($this->organisationIds as $id) {
            $this->deleteOrganisation($id);
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_an_action_racing_the_close_never_lands_on_a_closed_report(): void
    {
        $f = $this->fixture();
        $incidentId = (int) DB::table('hr_incidents')->where('public_id', $f['incident'])->value('id');

        $out = $this->race('hr_incidents', $incidentId, [
            $this->worker($f['hr'], ['action' => 'close', 'incident' => $f['incident'], 'outcome' => 'Synthetic outcome', 'expected_lock_version' => 2]),
            $this->worker($f['hr2'], ['action' => 'add', 'incident' => $f['incident'], 'description' => 'Synthetic action', 'owner_id' => $f['staff']->id,
                'due_on' => now('Asia/Kuala_Lumpur')->addDay()->toDateString()]),
        ]);

        $actions = DB::table('hr_incident_actions')->where('incident_id', $incidentId)->count();
        $status = (string) DB::table('hr_incidents')->where('id', $incidentId)->value('status');
        // Either the close won and the action was refused, or the action went in first and the close was refused because it is open.
        if ($status === 'closed') {
            $this->assertSame(0, $actions, $out);
            $this->assertStringContainsString('STALE incident: This report is closed. Nothing more can be added.', $out);
        } else {
            $this->assertSame(1, $actions, $out);
            $this->assertStringContainsString('STALE incident: Mark every action done before closing the report.', $out);
        }
    }

    public function test_two_managers_acknowledging_one_report_record_one_acknowledgement(): void
    {
        $f = $this->fixture(false);
        $incidentId = (int) DB::table('hr_incidents')->where('public_id', $f['incident'])->value('id');

        $out = $this->race('hr_incidents', $incidentId, [
            $this->worker($f['hr'], ['action' => 'acknowledge', 'incident' => $f['incident'], 'expected_lock_version' => 1]),
            $this->worker($f['hr2'], ['action' => 'acknowledge', 'incident' => $f['incident'], 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS acknowledge'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $this->assertSame(2, (int) DB::table('hr_incidents')->where('id', $incidentId)->value('lock_version'));
    }

    /** @return array<string, mixed> */
    private function fixture(bool $acknowledged = true): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_INC_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Incident Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'I'.Str::upper(Str::random(5)), 'name' => 'Synthetic Incident Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $hr = $this->user($organisation, $branch, [IncidentPermissions::MANAGE, IncidentPermissions::REPORT]);
        $hr2 = $this->user($organisation, $branch, [IncidentPermissions::MANAGE, IncidentPermissions::REPORT]);
        $staff = $this->user($organisation, $branch, [IncidentPermissions::REPORT], 'ca');
        $service = app(IncidentService::class);
        $incident = $service->report($staff, ['branch_id' => $branch->code, 'occurred_on' => now('Asia/Kuala_Lumpur')->toDateString(),
            'occurred_time' => '00:00', 'category' => 'workplace_safety', 'description' => 'Synthetic report for a contention test.', 'idempotency_key' => (string) Str::uuid()]);
        if ($acknowledged) {
            $service->acknowledge($hr, $incident, ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        }

        return ['organisation' => $organisation, 'branch' => $branch, 'hr' => $hr, 'hr2' => $hr2, 'staff' => $staff, 'incident' => $incident];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions, ?string $role = null): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Incident User '.Str::random(4), 'email' => 'incidents.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole(Role::findOrCreate($role ?? 'hr_manager', 'web'));
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['assignment_type' => 'permanent', 'is_primary' => true, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return WorkerProcess
     */
    private function worker(User $actor, array $args): array
    {
        $args['idempotency_key'] ??= (string) Str::uuid();
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresIncidentsWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-incidents-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the incident row, starts both workers, proves each is waiting behind this session,
     * then releases.
     *
     * @param  list<WorkerProcess>  $workers
     */
    private function race(string $table, int $rowId, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::table($table)->where('id', $rowId)->lockForUpdate()->first();
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

    /** @param list<WorkerProcess> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated() && ! str_contains($worker['process']->getOutput(), 'READY ')) {
                    throw new RuntimeException('Incident worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Incident worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required incident contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected incident blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }

    private function deleteOrganisation(int $id): void
    {
        DB::transaction(function () use ($id): void {
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic incident evidence.
            foreach (['hr_incident_notes', 'hr_incident_actions', 'hr_incident_photos', 'hr_incidents', 'hr_record_changes', 'hr_record_retries'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            DB::statement('ALTER TABLE hr_workers DISABLE TRIGGER USER');
            DB::table('hr_workers')->where('organisation_id', $id)->delete();
            DB::statement('ALTER TABLE hr_workers ENABLE TRIGGER USER');
            DB::table('audit_logs')->where('organisation_id', $id)->delete();
            $branchIds = DB::table('branches')->where('organisation_id', $id)->pluck('id');
            DB::table('staff_branch_assignments')->whereIn('branch_id', $branchIds)->delete();
            $userIds = DB::table('users')->where('organisation_id', $id)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->where('organisation_id', $id)->delete();
            DB::table('branches')->where('organisation_id', $id)->delete();
            DB::table('organisations')->where('id', $id)->delete();
        });
    }
}
