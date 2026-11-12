<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\InventoryControlService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\VerifiedReceivingFixture;
use Tests\TestCase;

/** STK-30b: two saves of one reorder level at the same moment never both win, and a double submit replays. */
final class PostgresReorderLevelTest extends TestCase
{
    public function test_concurrent_reorder_level_saves_have_one_winner(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk30b_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.reorder_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'reorder_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('reorder_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame([$database, 'UTF8'], [$identity->database, $identity->encoding]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'reorder_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();
            $fx = new VerifiedReceivingFixture($branch);
            $f = $fx->build();
            $service = app(InventoryControlService::class);
            $at = function (User $actor) use ($branch): User {
                app(BranchAccessService::class)->select($actor, $branch);

                return $actor;
            };
            $supervisor = $at($fx->actor('ca_supervisor'));
            $other = $at($fx->actor('ca_supervisor'));
            $payload = fn (string $level, ?int $version, ?string $key = null): array => ['expected_branch_id' => $branch->id, 'location_public_id' => $f['location']->public_id,
                'sku_public_id' => $f['sku']->public_id, 'reorder_level' => $level, 'max_level' => null, 'expected_lock_version' => $version, 'idempotency_key' => $key ?? (string) Str::uuid()];
            $stored = fn () => DB::table('inventory_reorder_levels')->where('inventory_location_id', $f['location']->id)->where('inventory_sku_id', $f['sku']->id)->get(['reorder_level', 'lock_version']);

            // Race 1: two people create the first level for one location and SKU; one row, the other refused.
            DB::beginTransaction();
            $service->setReorderLevel($supervisor, $payload('5', null));
            $worker = $this->race($database, $config, ['branch_id' => $branch->id, 'actor_id' => $other->id, 'payload' => $payload('7', null)]);
            $this->assertStringContainsString('REFUSED expected_lock_version', $worker->getOutput());
            $this->assertSame([['5.000', 1]], $stored()->map(fn ($row) => [(string) $row->reorder_level, (int) $row->lock_version])->all());

            // Race 2: two people edit version 1 at once; one edit, version 2.
            DB::beginTransaction();
            $service->setReorderLevel($supervisor, $payload('8', 1));
            $worker = $this->race($database, $config, ['branch_id' => $branch->id, 'actor_id' => $other->id, 'payload' => $payload('9', 1)]);
            $this->assertStringContainsString('REFUSED expected_lock_version', $worker->getOutput());
            $this->assertSame([['8.000', 2]], $stored()->map(fn ($row) => [(string) $row->reorder_level, (int) $row->lock_version])->all());

            // Race 3: the same save sent twice (one key) by one person; the second waits and replays, one version.
            $key = (string) Str::uuid();
            DB::beginTransaction();
            $service->setReorderLevel($supervisor, $payload('10', 2, $key));
            $worker = $this->race($database, $config, ['branch_id' => $branch->id, 'actor_id' => $supervisor->id, 'payload' => $payload('10', 2, $key)]);
            $this->assertMatchesRegularExpression('/SUCCEEDED \d+ 3/', $worker->getOutput());
            $this->assertSame([['10.000', 3]], $stored()->map(fn ($row) => [(string) $row->reorder_level, (int) $row->lock_version])->all());
            $this->assertSame(1, DB::table('inventory_reorder_level_commands')->where('idempotency_key', $key)->count());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker?->isRunning()) {
                $worker->stop(1);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            DB::purge('reorder_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits. */
    private function race(string $database, array $config, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresReorderLevelWorker.php'), $database, 'save', base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
        $worker->setInput($input)->setTimeout(ContentionTimeouts::processTimeoutSeconds())->start();
        $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
        while (! str_contains($worker->getOutput(), 'READY ') && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertMatchesRegularExpression('/READY (\d+)/', $worker->getOutput(), $worker->getErrorOutput());
        preg_match('/READY (\d+)/', $worker->getOutput(), $pid);
        $input->write("GO\n");
        $waiting = false;
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) $pid[1]])?->wait_event_type === 'Lock';
            if ($waiting) {
                break;
            }
            usleep(20000);
        } while ($worker->isRunning() && microtime(true) < $deadline);
        $this->assertTrue($waiting, $worker->getOutput().$worker->getErrorOutput());
        DB::commit();
        $input->close();
        $worker->wait();
        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());

        return $worker;
    }
}
