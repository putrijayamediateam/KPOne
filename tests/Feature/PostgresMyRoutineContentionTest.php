<?php

namespace Tests\Feature;

use App\Domain\MyDay\Models\MyRoutineItem;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\TestCase;

/**
 * DAY-1b under real PostgreSQL contention: two requests from one person add the 20th item at the same moment. The test holds
 * the person's account row until both workers are proven to be waiting; then exactly one adds, and the list stops at 20.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresMyRoutineContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_my_routine_observer';

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
            $this->markTestSkipped('PostgreSQL routine regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Routine concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('my_routine_items')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before routine regressions.');
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

    public function test_two_concurrent_adds_of_the_twentieth_item_add_exactly_one(): void
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'MYR_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Routine Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Routine User', 'email' => 'routine.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        for ($i = 1; $i <= 19; $i++) {
            MyRoutineItem::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => "Item {$i}", 'position' => $i, 'created_at' => now()]);
        }
        DB::table('my_routine_lists')->insert(['user_id' => $user->id, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        // The first request sees version 1; the second is the same person's next request, expecting version 2. Whichever the
        // database lets in first, only one can add: the other is refused (stale version, or the 20-item limit).
        $out = $this->race($user->id, [
            $this->worker($user, ['title' => 'Item twenty A', 'lock_version' => 1]),
            $this->worker($user, ['title' => 'Item twenty B', 'lock_version' => 2]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS add'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $this->assertSame(20, DB::table('my_routine_items')->where('user_id', $user->id)->whereNull('hidden_at')->count());
        $this->assertSame(2, (int) DB::table('my_routine_lists')->where('user_id', $user->id)->value('lock_version'));
    }

    /**
     * @param  array<string, mixed>  $args
     * @return WorkerProcess
     */
    private function worker(User $actor, array $args): array
    {
        $args['idempotency_key'] ??= (string) Str::uuid();
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresMyRoutineWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-my-routine-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the person's account row, starts both workers, proves each is waiting behind this session, then releases.
     *
     * @param  list<WorkerProcess>  $workers
     */
    private function race(int $userId, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
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
                    throw new RuntimeException('Routine worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Routine worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required routine contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected routine blocker chain was not observed. '.$this->processDiagnostics($process));
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
            $userIds = DB::table('users')->where('organisation_id', $id)->pluck('id');
            DB::table('my_routine_ticks')->whereIn('user_id', $userIds)->delete();
            DB::table('my_routine_requests')->whereIn('user_id', $userIds)->delete();
            DB::table('my_routine_items')->whereIn('user_id', $userIds)->delete();
            DB::table('my_routine_lists')->whereIn('user_id', $userIds)->delete();
            DB::table('audit_logs')->where('organisation_id', $id)->delete();
            DB::table('users')->where('organisation_id', $id)->delete();
            DB::table('branches')->where('organisation_id', $id)->delete();
            DB::table('organisations')->where('id', $id)->delete();
        });
    }
}
