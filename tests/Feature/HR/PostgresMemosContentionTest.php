<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Memos\MemoPermissions;
use App\Domain\HR\Memos\Services\MemoService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
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
 * HR-8 under real PostgreSQL contention. An acknowledgement and a replacement of the same memo, or two replacements, queue on the
 * memo's row, and the outcome is consistent in either order. Each race holds the memo row in this test's session until both
 * workers are proven to be waiting.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresMemosContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_memos_observer';

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
            $this->markTestSkipped('PostgreSQL memo regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Memo concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_memos')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before memo regressions.');
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

    public function test_an_acknowledgement_racing_a_replacement_of_the_same_memo_stays_consistent(): void
    {
        $f = $this->fixture();
        $memos = app(MemoService::class);
        $x = $memos->publish($f['hr'], null, ['title' => 'Synthetic race memo', 'body' => 'Synthetic race body', 'roles' => ['ca'], 'branches' => [], 'idempotency_key' => (string) Str::uuid()]);
        $memos->openBody($f['ca'], $x);
        $xId = (int) DB::table('hr_memos')->where('public_id', $x)->value('id');

        $out = $this->race($xId, [
            $this->worker($f['ca'], ['op' => 'acknowledge', 'memo' => $x]),
            $this->worker($f['hr'], ['op' => 'replace', 'memo' => $x]),
        ]);

        // The replacement always lands. The acknowledgement either landed first or was refused: never after the replacement.
        $this->assertSame(1, substr_count($out, 'SUCCESS replace'), $out);
        $this->assertSame(1, DB::table('hr_memos')->where('replaces_memo_id', $xId)->count());
        $acks = DB::table('hr_memo_acknowledgements')->where('memo_id', $xId)->count();
        if (str_contains($out, 'SUCCESS acknowledge')) {
            $this->assertSame(1, $acks, $out);
        } else {
            $this->assertSame(1, substr_count($out, 'STALE memo: This memo was replaced and no longer takes reads.'), $out);
            $this->assertSame(0, $acks, $out);
            // PostgreSQL itself refuses the late acknowledgement from the person who did open the memo.
            $this->assertRefusedAsReplaced($f['ca'], $xId);
        }
    }

    public function test_two_replacements_of_one_memo_record_one_replacement(): void
    {
        $f = $this->fixture();
        $x = app(MemoService::class)->publish($f['hr'], null, ['title' => 'Synthetic twin memo', 'body' => 'Synthetic twin body', 'roles' => ['ca'], 'branches' => [], 'idempotency_key' => (string) Str::uuid()]);
        $xId = (int) DB::table('hr_memos')->where('public_id', $x)->value('id');
        $out = $this->race($xId, [$this->worker($f['hr'], ['op' => 'replace', 'memo' => $x]), $this->worker($f['hr2'], ['op' => 'replace', 'memo' => $x])]);

        $this->assertSame(1, substr_count($out, 'SUCCESS replace'), $out);
        $this->assertSame(1, substr_count($out, 'STALE replaces: This memo was already replaced.'), $out);
        $this->assertSame(1, DB::table('hr_memos')->where('replaces_memo_id', $xId)->count());
    }

    private function assertRefusedAsReplaced(User $ca, int $memoId): void
    {
        try {
            DB::transaction(fn () => DB::table('hr_memo_acknowledgements')->insert(['organisation_id' => $ca->organisation_id, 'memo_id' => $memoId, 'user_id' => $ca->id, 'created_at' => now()]));
            $this->fail('Expected PostgreSQL to refuse a late acknowledgement.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString('A replaced memo takes no acknowledgement', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_MEMO_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Memo Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'M'.Str::upper(Str::random(5)), 'name' => 'Synthetic Memo Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();

        return ['organisation' => $organisation, 'branch' => $branch,
            'hr' => $this->user($organisation, $branch, [MemoPermissions::PUBLISH, MemoPermissions::READ_OWN]),
            'hr2' => $this->user($organisation, $branch, [MemoPermissions::PUBLISH, MemoPermissions::READ_OWN]),
            'ca' => $this->user($organisation, $branch, [MemoPermissions::READ_OWN], 'ca')];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions, ?string $role = null): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Memo User '.Str::random(4), 'email' => 'memos.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresMemosWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-memos-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the memo row, starts both workers, proves each is waiting behind this session, then releases.
     *
     * @param  list<WorkerProcess>  $workers
     */
    private function race(int $memoId, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::table('hr_memos')->where('id', $memoId)->lockForUpdate()->first();
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
                    throw new RuntimeException('Memo worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Memo worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required memo contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected memo blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic memo evidence.
            $memoIds = DB::table('hr_memos')->where('organisation_id', $id)->pluck('id');
            DB::statement('ALTER TABLE hr_memo_roles DISABLE TRIGGER USER');
            DB::table('hr_memo_roles')->whereIn('memo_id', $memoIds)->delete();
            DB::statement('ALTER TABLE hr_memo_roles ENABLE TRIGGER USER');
            foreach (['hr_memo_acknowledgements', 'hr_memo_opens', 'hr_memo_branches', 'hr_record_retries'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            DB::statement('ALTER TABLE hr_memos DISABLE TRIGGER USER');
            DB::table('hr_memos')->where('organisation_id', $id)->update(['replaces_memo_id' => null]);
            DB::table('hr_memos')->where('organisation_id', $id)->delete();
            DB::statement('ALTER TABLE hr_memos ENABLE TRIGGER USER');
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
