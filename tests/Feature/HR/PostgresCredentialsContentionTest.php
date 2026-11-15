<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Credentials\CredentialPermissions;
use App\Domain\HR\Credentials\Models\CredentialRecord;
use App\Domain\HR\Credentials\Services\CredentialService;
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
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * HR-5a under real PostgreSQL contention (Suresh, S3). Two managers accepting one record, and two sends for one
 * credential, serialise on the staff member's account and worker rows: exactly one wins and the other is refused with a
 * plain reason. A send holds the type row FOR SHARE, so an archive waits for it and a later send sees the type archived.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresCredentialsContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_credentials_observer';

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
            $this->markTestSkipped('PostgreSQL credential regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Credential concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_workers')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before credential regressions.');
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

    public function test_two_managers_accepting_one_record_record_one_acceptance(): void
    {
        $f = $this->fixture();
        $record = app(CredentialService::class)->sendOwn($f['staff'], null, ['type_id' => $f['type']->public_id, 'issued_on' => '2026-01-10',
            'expires_on' => '2028-01-10', 'idempotency_key' => (string) Str::uuid()]);
        $args = ['op' => 'decide', 'record' => $record->public_id, 'decision' => 'accept', 'expected_lock_version' => 1];
        $out = $this->race(fn () => DB::table('users')->where('id', $f['staff']->id)->lockForUpdate()->first(),
            [$this->worker($f['manager1'], $args), $this->worker($f['manager2'], $args)]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $this->assertSame(['accepted'], CredentialRecord::query()->where('worker_id', $f['worker']->id)->pluck('status')->all());
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $f['organisation']->id)->where('event', 'hr.credentials.record_accepted')->count());
    }

    public function test_two_sends_for_one_credential_at_once_leave_one_waiting_record(): void
    {
        $f = $this->fixture();
        $args = ['op' => 'send_own', 'type_id' => $f['type']->public_id, 'issued_on' => '2026-01-10', 'expires_on' => '2028-01-10'];
        $out = $this->race(fn () => DB::table('users')->where('id', $f['staff']->id)->lockForUpdate()->first(),
            [$this->worker($f['staff'], $args), $this->worker($f['staff'], [...$args, 'issued_on' => '2026-01-11'])]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE credentials: A record for this credential is already waiting for check.'), $out);
        $this->assertSame(1, CredentialRecord::query()->where('worker_id', $f['worker']->id)->count());
    }

    public function test_archive_racing_a_send_never_leaves_a_record_on_an_archived_type_after_the_archive(): void
    {
        $f = $this->fixture();
        $send = $this->worker($f['staff'], ['op' => 'send_own', 'type_id' => $f['type']->public_id, 'issued_on' => '2026-01-10', 'expires_on' => '2028-01-10']);
        $archive = $this->worker($f['manager1'], ['op' => 'archive', 'type' => $f['type']->public_id, 'expected_lock_version' => $f['type']->lock_version]);
        // Holding the type row makes the send (FOR SHARE) and the archive (FOR UPDATE) both wait on this session.
        $out = $this->race(fn () => DB::table('hr_credential_types')->where('id', $f['type']->id)->lockForUpdate()->first(), [$send, $archive]);

        $this->assertStringContainsString('SUCCESS archive', $out);
        $this->assertFalse((bool) DB::table('hr_credential_types')->where('id', $f['type']->id)->value('is_active'));
        $records = CredentialRecord::query()->where('worker_id', $f['worker']->id)->get();
        if (str_contains($out, 'SUCCESS send_own')) {
            // The send committed first, while the type was still active.
            $this->assertCount(1, $records);
        } else {
            $this->assertStringContainsString('STALE type_id: This credential type is not in use. Reload the page.', $out);
            $this->assertCount(0, $records);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_CRED_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Credentials Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'C'.Str::upper(Str::random(5)), 'name' => 'Synthetic Credentials Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $managers = [];
        foreach ([1, 2] as $i) {
            $managers["manager{$i}"] = $this->user($organisation, $branch, [RecordPermissions::VIEW_ORGANISATION, RecordPermissions::MANAGE, CredentialPermissions::MANAGE, CredentialPermissions::VIEW_OWN]);
        }
        $staff = $this->user($organisation, $branch, [RecordPermissions::VIEW_OWN, CredentialPermissions::VIEW_OWN]);
        $worker = app(StaffFileService::class)->openFile($managers['manager1'], ['user_id' => $staff->id, 'idempotency_key' => (string) Str::uuid()]);
        $type = app(CredentialService::class)->saveType($managers['manager1'], ['code' => 'BLS', 'name' => 'Basic Life Support (BLS)', 'expires' => true,
            'health_item' => false, 'warn_from_days' => 60, 'roles' => [], 'idempotency_key' => (string) Str::uuid()]);

        return ['organisation' => $organisation, 'branch' => $branch, ...$managers, 'staff' => $staff, 'worker' => $worker, 'type' => $type->refresh()];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Records User '.Str::random(4), 'email' => 'records.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresCredentialsWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-credentials-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Takes the row lock $hold asks for, starts both workers, proves each is waiting behind this session, then releases.
     *
     * @param  list<WorkerProcess>  $workers
     */
    private function race(callable $hold, array $workers): string
    {
        DB::beginTransaction();
        try {
            $hold();
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
                    throw new RuntimeException('Credential worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Credential worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required credential contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected credential blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic credential evidence.
            foreach (['hr_credential_records'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            $versions = DB::table('hr_credential_type_versions')->where('organisation_id', $id)->pluck('id');
            foreach (['hr_credential_type_roles', 'hr_credential_type_versions', 'hr_credential_types'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                $table === 'hr_credential_type_roles' ? DB::table($table)->whereIn('type_version_id', $versions)->delete() : DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            foreach (['hr_record_changes', 'hr_record_retries', 'hr_record_corrections', 'hr_bank_details', 'hr_positions', 'hr_personal_details', 'hr_employment_periods', 'hr_workers'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
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
