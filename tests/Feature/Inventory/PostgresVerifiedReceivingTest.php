<?php

namespace Tests\Feature\Inventory;

use App\Domain\Organisation\Inventory\Services\ProcurementService;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\VerifiedReceivingFixture;
use Tests\TestCase;

class PostgresVerifiedReceivingTest extends TestCase
{
    public function test_concurrent_receipts_and_duplicate_delivery_references_have_one_post_and_no_lost_audit(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk3_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.receiving_race' => [...$config, 'database' => $database, 'name' => 'receiving_race', 'url' => null]]);
        $worker = null;
        try {
            DB::setDefaultConnection('receiving_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, Schema::getConnection()->getDatabaseName());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'receiving_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            foreach (['lock_version', 'delivery_reference'] as $expected) {
                $f = (new VerifiedReceivingFixture)->build();
                $otherReceiver = (new VerifiedReceivingFixture($f['visit']->branch))->actor('ca_supervisor');
                $service = app(ProcurementService::class);
                $order = $f['order'];
                $first = $f['payload'];
                $first['lines'][0]['quantity'] = '6.000';
                $second = [...$first, 'idempotency_key' => (string) Str::uuid()];
                if ($expected === 'delivery_reference') {
                    $other = $service->create($f['finance'], ['expected_branch_id' => $f['visit']->branch_id, 'supplier_public_id' => $f['supplier']->public_id, 'destination_location_public_id' => $f['location']->public_id, 'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '10.000']]]);
                    $other = $service->submit($f['finance'], $other, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $other->lock_version]);
                    $order = $service->approve($f['director'], $other, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $other->lock_version]);
                    $second['lines'][0]['line_public_id'] = $order->lines()->sole()->public_id;
                    $second['lock_version'] = $order->lock_version;
                }
                $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
                $max = DB::table('stock_movements')->max('id');
                DB::beginTransaction();
                $receipt = $service->receive($f['receiver'], $f['order'], $first);
                $encoded = base64_encode(json_encode(['branch_id' => $f['visit']->branch_id, 'actor_id' => $otherReceiver->id, 'order_id' => $order->id, 'payload' => $second], JSON_THROW_ON_ERROR));
                $input = new InputStream;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresVerifiedReceivingWorker.php'), $database, $encoded], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
                $worker->setInput($input)->setTimeout(30)->start();
                $deadline = microtime(true) + 15;
                while (! str_contains($worker->getOutput(), 'READY ') && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(20000);
                }
                $this->assertMatchesRegularExpression('/READY (\d+)/', $worker->getOutput(), $worker->getErrorOutput());
                preg_match('/READY (\d+)/', $worker->getOutput(), $pid);
                $input->write("GO\n");
                $waiting = false;
                $deadline = microtime(true) + 15;
                do {
                    $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) $pid[1]])?->wait_event_type === 'Lock';
                    if ($waiting) {
                        break;
                    }usleep(20000);
                } while ($worker->isRunning() && microtime(true) < $deadline);
                $this->assertTrue($waiting, $worker->getOutput().$worker->getErrorOutput());
                DB::commit();
                $input->close();
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $this->assertStringContainsString('REFUSED '.$expected, $worker->getOutput());
                $this->assertSame(1, DB::table('inventory_goods_receipts')->where('purchase_order_id', $f['order']->id)->count());
                $this->assertSame(1, DB::table('stock_movements')->where('reference_public_id', $receipt->public_id)->count());
                $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.goods_receipt.posted')->where('subject_id', $receipt->id)->count());
                $this->assertSame($before, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
                $this->assertSame('6.000', $f['order']->lines()->sole()->received_quantity);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }if ($worker?->isRunning()) {
                $worker->stop(1);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            DB::purge('receiving_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }
}
