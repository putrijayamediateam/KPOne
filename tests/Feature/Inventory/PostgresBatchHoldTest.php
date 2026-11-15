<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\InventoryBatchHoldService;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\BatchHoldFixture;
use Tests\TestCase;

class PostgresBatchHoldTest extends TestCase
{
    public function test_inflight_hold_serializes_save_and_complete_with_no_stock_change_or_lost_audit(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk2_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.batch_race' => [...$config, 'database' => $database, 'name' => 'batch_race', 'url' => null]]);
        $worker = null;
        try {
            DB::setDefaultConnection('batch_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, Schema::getConnection()->getDatabaseName());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'batch_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            foreach (['save', 'complete'] as $action) {
                $fixture = new BatchHoldFixture;
                $f = $fixture->build($action === 'complete');
                $placer = $fixture->actor('finance_officer');
                session([BranchAccessService::SESSION_KEY => $f['visit']->branch_id]);
                $stock = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
                $movements = DB::table('stock_movements')->orderBy('id')->get()->toJson();
                $allocations = DB::table('dispensary_item_batch_allocations')->orderBy('id')->get()->toJson();
                DB::beginTransaction();
                $id = app(InventoryBatchHoldService::class)->act($placer, $f['batch']->public_id, 'place', ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'reason' => 'recall']);
                $data = base64_encode(json_encode(['branch_id' => $f['visit']->branch_id, 'actor_id' => $f['ca']->id, 'case_id' => $f['case']->id, 'payload' => $f['payload']], JSON_THROW_ON_ERROR));
                $input = new InputStream;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresBatchHoldWorker.php'), $database, $action, $data], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
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
                    $worker->getOutput();
                    $waiting = DB::selectOne('select wait_event_type from pg_stat_activity where pid=?', [(int) $pid[1]])?->wait_event_type === 'Lock';
                    if ($waiting) {
                        break;
                    }
                    usleep(20000);
                } while ($worker->isRunning() && microtime(true) < $deadline);
                $this->assertTrue($waiting, $worker->getOutput().$worker->getErrorOutput());
                DB::commit();
                $input->close();
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $this->assertStringContainsString('REFUSED_HELD', $worker->getOutput());
                $this->assertSame($stock, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
                $this->assertSame($movements, DB::table('stock_movements')->orderBy('id')->get()->toJson());
                $this->assertSame($allocations, DB::table('dispensary_item_batch_allocations')->orderBy('id')->get()->toJson());
                $this->assertSame($id, DB::table('inventory_batch_hold_states')->where('inventory_batch_id', $f['batch']->id)->sole()->active_action_id);
                $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.batch_hold.place')->where('subject_id', $f['batch']->id)->count());
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker?->isRunning()) {
                $worker->stop(1);
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            DB::purge('batch_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }
}
