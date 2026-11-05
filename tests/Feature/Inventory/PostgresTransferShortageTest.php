<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\StockRequestService;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\TransferShortageFixture;
use Tests\TestCase;

final class PostgresTransferShortageTest extends TestCase
{
    public function test_concurrent_receipts_and_resolutions_have_one_winner_and_conserve_stock(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk6_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.transfer_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'transfer_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('transfer_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame('UTF8', $identity->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'transfer_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();
            $service = app(StockRequestService::class);
            $fx = new TransferShortageFixture($branch);

            // Race 1: two receivers with different keys; one receipt wins, the other is refused.
            $f = $fx->dispatched('10.000');
            $second = $fx->actor('ca_supervisor');
            app(BranchAccessService::class)->select($f['requester'], $branch);
            DB::beginTransaction();
            $service->receive($f['requester'], $f['request'], $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '6.000', 'short' => '4.000']));
            $worker = $this->race($database, $config, 'receive', ['branch_id' => $branch->id, 'actor_id' => $second->id, 'request_id' => $f['request']->id, 'payload' => $fx->receipt($f['request'], $f['line']->public_id, ['accepted' => '10.000'])]);
            $this->assertStringContainsString('REFUSED receive_idempotency_key', $worker->getOutput());
            $this->assertSame(1, DB::table('inventory_transfer_receipt_lines')->where('stock_request_id', $f['request']->id)->count());
            $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'transfer_receipt')->where('reference_public_id', $f['request']->public_id)->count());
            $this->assertSame('6.000', DB::table('inventory_stock_balances')->where('inventory_location_id', $f['destination']->id)->where('inventory_batch_id', $f['batch']->id)->value('quantity'));

            // Race 2: two independent approvers resolve the same short difference; exactly one credit posts.
            $difference = DB::table('inventory_transfer_differences')->where('stock_request_id', $f['request']->id)->sole();
            $version = (int) DB::table('inventory_stock_requests')->where('id', $f['request']->id)->value('lock_version');
            $finance = $fx->actor('finance_officer');
            $director = $fx->actor('director');
            $payload = fn (): array => ['expected_branch_id' => $branch->id, 'lock_version' => $version, 'retry_key' => (string) Str::uuid(), 'difference_public_id' => $difference->public_id, 'outcome' => 'arrived_later', 'reference' => 'SYN-RACE'];
            app(BranchAccessService::class)->select($finance, $branch);
            $auditMax = (int) DB::table('audit_logs')->max('id');
            DB::beginTransaction();
            $service->resolveDifference($finance, $f['request']->refresh(), $payload());
            $worker = $this->race($database, $config, 'resolve', ['branch_id' => $branch->id, 'actor_id' => $director->id, 'request_id' => $f['request']->id, 'payload' => $payload()]);
            $this->assertMatchesRegularExpression('/REFUSED (lock_version|difference_public_id)/', $worker->getOutput());
            $this->assertSame(1, DB::table('inventory_transfer_difference_resolutions')->count());
            $this->assertSame(2, DB::table('stock_movements')->where('movement_type', 'transfer_receipt')->where('reference_public_id', $f['request']->public_id)->count());
            $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('inventory_location_id', $f['destination']->id)->where('inventory_batch_id', $f['batch']->id)->value('quantity'));
            $this->assertSame('10.000', DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['batch']->id)->value('quantity'));
            $this->assertSame(1, DB::table('audit_logs')->where('id', '>', $auditMax)->where('event', 'inventory.transfer_difference.resolved')->count());
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
            DB::purge('transfer_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits. */
    private function race(string $database, array $config, string $mode, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresTransferShortageWorker.php'), $database, $mode, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
