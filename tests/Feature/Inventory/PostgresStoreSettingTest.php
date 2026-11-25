<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\CentralStoreService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\GovernedStockFixture;
use Tests\TestCase;

/**
 * STK-60 race on a disposable PostgreSQL database: two first saves of the store request day at once. The first holds
 * its uncommitted row; the second waits on the unique organisation index and, once the first commits, is refused with
 * "Someone else changed the request day." One setting row and one version remain.
 */
final class PostgresStoreSettingTest extends TestCase
{
    private ?Process $worker = null;

    private string $database = '';

    /** @var array<string,mixed> */
    private array $config = [];

    public function test_two_first_saves_at_once_keep_one_setting_and_refuse_the_other(): void
    {
        $this->withRaceDatabase(function (Branch $branch): void {
            [$first, $second] = [$this->actor($branch), $this->actor($branch)];
            DB::beginTransaction();
            $mine = app(CentralStoreService::class)->updateRequestDay($first, ['request_weekday' => 2, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
            $output = $this->raceWorker($branch, $second, ['request_weekday' => 5, 'lock_version' => 0, 'idempotency_key' => (string) Str::uuid()]);
            $this->assertSame(2, $mine->request_weekday);
            $this->assertStringContainsString('REFUSED Someone else changed the request day. Reload and try again.', $output);
            $this->assertSame([[2, 1]], DB::table('inventory_store_settings')->get(['request_weekday', 'lock_version'])->map(fn ($row) => [(int) $row->request_weekday, (int) $row->lock_version])->all());
            $this->assertSame(1, DB::table('inventory_store_setting_versions')->count());
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.store_settings.updated')->count());
        });
    }

    private function actor(Branch $branch): User
    {
        $actor = (new GovernedStockFixture($branch))->actor('ca_supervisor');
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /** Start the worker while this test holds its transaction, prove it waits on a lock, then commit and let it finish. */
    private function raceWorker(Branch $branch, User $actor, array $payload): string
    {
        $data = ['branch_id' => $branch->id, 'actor_id' => $actor->id, 'payload' => $payload];
        $input = new InputStream;
        $c = $this->config;
        $this->worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresStoreSettingWorker.php'), $this->database, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $this->database, 'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'] ?? '', 'DB_URL' => '']);
        $this->worker->setInput($input)->setTimeout(60)->start();
        $deadline = microtime(true) + 30;
        while (! str_contains($this->worker->getOutput(), 'READY ') && $this->worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertMatchesRegularExpression('/READY (\d+)/', $this->worker->getOutput(), $this->worker->getErrorOutput());
        preg_match('/READY (\d+)/', $this->worker->getOutput(), $pid);
        $input->write("GO\n");
        $waiting = false;
        $deadline = microtime(true) + 30;
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) $pid[1]])?->wait_event_type === 'Lock';
            if ($waiting) {
                break;
            }
            usleep(20000);
        } while ($this->worker->isRunning() && microtime(true) < $deadline);
        $this->assertTrue($waiting, $this->worker->getOutput().$this->worker->getErrorOutput());
        DB::commit();
        $input->close();
        $this->worker->wait();
        $this->assertSame(0, $this->worker->getExitCode(), $this->worker->getErrorOutput().$this->worker->getOutput());

        return $this->worker->getOutput();
    }

    private function withRaceDatabase(callable $test): void
    {
        $original = config('database.default');
        $this->config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $this->config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $this->config['database']);
        $this->database = 'kpone_stk60_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$this->database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.store_setting_race' => [...$this->config, 'database' => $this->database, 'url' => null, 'name' => 'store_setting_race']]);
        try {
            DB::setDefaultConnection('store_setting_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($this->database, DB::selectOne('SELECT current_database() as database')->database);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'store_setting_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $test(Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->worker?->isRunning()) {
                $this->worker->stop(1);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            DB::purge('store_setting_race');
            DB::statement('DROP DATABASE "'.$this->database.'" WITH (FORCE)');
        }
    }
}
