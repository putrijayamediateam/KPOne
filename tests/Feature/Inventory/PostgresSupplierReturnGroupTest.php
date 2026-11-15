<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\ProcurementService;
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
use Tests\Support\VerifiedReceivingFixture;
use Tests\TestCase;

/** STK-17: two people taking the same lot at once, through a group and through a group or a single return. */
final class PostgresSupplierReturnGroupTest extends TestCase
{
    public function test_concurrent_group_and_single_requests_never_return_more_than_the_lot(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk17_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.group_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'group_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('group_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame('UTF8', $identity->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'group_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();
            $service = app(SupplierReturnService::class);

            // One delivery received as two lots of 10.
            $f = (new VerifiedReceivingFixture($branch))->build('20.000');
            app(BranchAccessService::class)->select($f['receiver'], $branch);
            $lots = [];
            foreach (['10.000', '10.000'] as $quantity) {
                $lots[] = ['quantity' => $quantity, 'batch_number' => 'SYN-RACE-'.Str::upper(Str::random(6)), 'expiry_date' => '2028-12-31', 'outcome' => 'accepted'];
            }
            $receipt = app(ProcurementService::class)->receive($f['receiver'], $f['order'], [...$f['payload'], 'lines' => [['line_public_id' => $f['order']->lines()->sole()->public_id, 'physical_quantity' => '20.000', 'lots' => $lots]]]);
            $lotIds = DB::table('inventory_receipt_lots as lot')->join('inventory_receipt_line_evidence as line', 'line.id', '=', 'lot.line_evidence_id')
                ->where('line.goods_receipt_id', $receipt->id)->orderBy('lot.id')->pluck('lot.id')->all();
            $group = fn (string $a, string $b): array => ['expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'goods_receipt_id' => $receipt->id,
                'lines' => [['receipt_lot_id' => $lotIds[0], 'unit' => $f['sku']->stock_unit, 'quantity' => $a, 'reason' => 'damaged'], ['receipt_lot_id' => $lotIds[1], 'unit' => $f['sku']->stock_unit, 'quantity' => $b, 'reason' => 'damaged']]];
            $second = (new SupplierReturnFixture($branch))->actor('ca_supervisor');

            // Race 1: two groups each ask for 6 of the first lot of 10; one wins, the other is refused on that line.
            DB::beginTransaction();
            $service->requestGroup($f['receiver'], $group('6.000', '1.000'));
            $worker = $this->race($database, $config, 'group', ['branch_id' => $branch->id, 'actor_id' => $second->id, 'payload' => $group('6.000', '1.000')]);
            $this->assertStringContainsString('REFUSED lines.0.quantity', $worker->getOutput());
            $this->assertSame(1, DB::table('inventory_supplier_return_groups')->count());

            // Race 2: a group and a single return both ask for the last 4 of the first lot; only one is kept.
            DB::beginTransaction();
            $service->requestGroup($f['receiver'], $group('4.000', '1.000'));
            $worker = $this->race($database, $config, 'single', ['branch_id' => $branch->id, 'actor_id' => $second->id, 'payload' => ['expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'receipt_lot_id' => $lotIds[0], 'unit' => $f['sku']->stock_unit, 'quantity' => '4.000', 'reason' => 'damaged']]);
            $this->assertStringContainsString('REFUSED quantity', $worker->getOutput());
            $this->assertSame('10.000', (string) DB::table('inventory_supplier_returns')->where('receipt_lot_id', $lotIds[0])->sum('quantity'));
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
            DB::purge('group_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits. */
    private function race(string $database, array $config, string $mode, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresSupplierReturnGroupWorker.php'), $database, $mode, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
