<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\SupplierReturnService;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\SupplierReturnFixture;
use Tests\TestCase;

final class PostgresSupplierReturnTest extends TestCase
{
    public function test_concurrent_requests_and_approvals_have_one_winner(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk7_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.return_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'return_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('return_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame('UTF8', $identity->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'return_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();
            $service = app(SupplierReturnService::class);
            $fx = new SupplierReturnFixture($branch);

            // Race 1: two CA Supervisors ask to return 6 each from a lot of 10; one wins, the other is refused.
            $f = $fx->received('10.000');
            $second = $fx->actor('ca_supervisor');
            app(BranchAccessService::class)->select($f['receiver'], $branch);
            DB::beginTransaction();
            $id = $service->request($f['receiver'], $fx->request($f, '6.000'));
            $worker = $this->race($database, $config, 'request', ['branch_id' => $branch->id, 'actor_id' => $second->id, 'payload' => $fx->request($f, '6.000')]);
            $this->assertStringContainsString('REFUSED quantity', $worker->getOutput());
            $this->assertSame(1, DB::table('inventory_supplier_returns')->count());
            $this->assertSame(['6.000'], DB::table('inventory_supplier_returns')->pluck('quantity')->all());

            // Race 2: a Finance Officer and a Director approve the same return; exactly one decision is kept.
            $finance = $fx->actor('finance_officer');
            $director = $fx->actor('director');
            $payload = fn (): array => ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()];
            app(BranchAccessService::class)->select($finance, $branch);
            $auditMax = (int) DB::table('audit_logs')->max('id');
            DB::beginTransaction();
            $service->act($finance, $id, 'approved', $payload());
            $worker = $this->race($database, $config, 'approve', ['branch_id' => $branch->id, 'actor_id' => $director->id, 'return_id' => $id, 'payload' => $payload()]);
            $this->assertMatchesRegularExpression('/REFUSED (lock_version|state)/', $worker->getOutput());
            $this->assertSame(['requested', 'approved'], DB::table('inventory_supplier_return_actions')->where('return_id', $id)->orderBy('lock_version')->pluck('action')->all());
            $this->assertSame([$finance->id], DB::table('inventory_supplier_return_actions')->where('return_id', $id)->where('action', 'approved')->pluck('actor_user_id')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(1, DB::table('audit_logs')->where('id', '>', $auditMax)->where('event', 'inventory.supplier_return.approved')->count());
            $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'supplier_return')->count());
            $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['returnBatchId'])->value('quantity'));
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
            DB::purge('return_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits. */
    private function race(string $database, array $config, string $mode, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresSupplierReturnWorker.php'), $database, $mode, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
