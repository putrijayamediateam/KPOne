<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Discipline\DisciplinePermissions;
use App\Domain\HR\Discipline\Services\DisciplineService;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\StaffFileService;
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
 * HR-10c under real PostgreSQL contention. Two managers closing one case record one outcome; a note racing the close
 * never lands after the outcome. Each race holds the case row in this test's session until both workers are proven to be
 * waiting.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresDisciplineContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_discipline_observer';

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
            $this->markTestSkipped('PostgreSQL discipline regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Discipline concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_discipline_cases')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before discipline regressions.');
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

    public function test_two_managers_closing_one_case_record_one_outcome(): void
    {
        $f = $this->fixture();
        $caseId = (int) DB::table('hr_discipline_cases')->where('public_id', $f['case'])->value('id');

        $out = $this->race('hr_discipline_cases', $caseId, [
            $this->worker($f['hr'], ['action' => 'close', 'case' => $f['case'], 'outcome' => 'no_action', 'expected_lock_version' => 1]),
            $this->worker($f['hr2'], ['action' => 'close', 'case' => $f['case'], 'outcome' => 'verbal_warning', 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS close'), $out);
        $this->assertSame(1, substr_count($out, 'STALE case: This case is closed. Nothing more can be added.'), $out);
        $this->assertSame(1, DB::table('hr_discipline_events')->where('case_id', $caseId)->where('kind', 'outcome')->count());
        $this->assertSame('closed', DB::table('hr_discipline_cases')->where('id', $caseId)->value('status'));
    }

    public function test_a_note_against_closing_never_lands_on_a_closed_case(): void
    {
        $f = $this->fixture();
        $caseId = (int) DB::table('hr_discipline_cases')->where('public_id', $f['case'])->value('id');

        $out = $this->race('hr_discipline_cases', $caseId, [
            $this->worker($f['hr'], ['action' => 'close', 'case' => $f['case'], 'outcome' => 'no_action', 'expected_lock_version' => 1]),
            $this->worker($f['hr2'], ['action' => 'note', 'case' => $f['case'], 'note' => 'Synthetic late note']),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS close'), $out);
        $notes = DB::table('hr_discipline_events')->where('case_id', $caseId)->where('kind', 'note')->count();
        // Either the note went in first (and the case then closed after it) or it was refused; never after the outcome.
        if ($notes === 1) {
            $this->assertStringContainsString('SUCCESS note', $out);
            $this->assertLessThan(
                (int) DB::table('hr_discipline_events')->where('case_id', $caseId)->where('kind', 'outcome')->value('id'),
                (int) DB::table('hr_discipline_events')->where('case_id', $caseId)->where('kind', 'note')->value('id'));
        } else {
            $this->assertStringContainsString('STALE case: This case is closed. Nothing more can be added.', $out);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_DSC_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Discipline Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'D'.Str::upper(Str::random(5)), 'name' => 'Synthetic Discipline Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $manage = [DisciplinePermissions::MANAGE, RecordPermissions::MANAGE, RecordPermissions::VIEW_ORGANISATION];
        $hr = $this->user($organisation, $branch, $manage);
        $hr2 = $this->user($organisation, $branch, $manage);
        $staff = $this->user($organisation, $branch, [RecordPermissions::VIEW_OWN], 'ca');
        $worker = app(StaffFileService::class)->openFile($hr, ['user_id' => $staff->id, 'idempotency_key' => (string) Str::uuid()]);
        $case = app(DisciplineService::class)->open($hr, ['worker_id' => $worker->public_id, 'incident_on' => now('Asia/Kuala_Lumpur')->subDay()->toDateString(),
            'allegation' => 'Synthetic allegation for a contention test.', 'idempotency_key' => (string) Str::uuid()]);

        return ['organisation' => $organisation, 'branch' => $branch, 'hr' => $hr, 'hr2' => $hr2, 'staff' => $staff, 'worker' => $worker->public_id, 'case' => $case];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions, ?string $role = null): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Discipline User '.Str::random(4), 'email' => 'discipline.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresDisciplineWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-discipline-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the case row, starts both workers, proves each is waiting behind this session,
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
                    throw new RuntimeException('Discipline worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Discipline worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required discipline contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected discipline blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic discipline evidence.
            foreach (['hr_discipline_events', 'hr_discipline_files', 'hr_discipline_cases', 'hr_record_changes', 'hr_record_retries'] as $table) {
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
