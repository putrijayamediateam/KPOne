<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\VerifiedReceivingFixture;
use Tests\TestCase;

final class PostgresGovernedStockTest extends TestCase
{
    public function test_dispensing_and_writeoff_approval_serialize_with_one_winner_and_no_lost_audit(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk5_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.governed_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'governed_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('governed_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame('governed_race', DB::getDefaultConnection());
            $this->assertSame($database, DB::connection()->getConfig('database'));
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame('UTF8', $identity->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'governed_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();
            foreach (['approve', 'dispense'] as $winner) {
                $fixture = new VerifiedReceivingFixture($branch);
                $f = $fixture->build();
                $dispensary = app(DispensaryService::class);
                app(BranchAccessService::class)->select($f['ca'], $branch);
                $dispensary->addItem($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $branch->id, 'case_lock_version' => $f['case']->lock_version, 'medicine_public_id' => $f['medicine']->public_id, 'quantity_dispensed' => '10.000', 'dosage' => 'Synthetic dose', 'frequency' => 'Synthetic frequency', 'duration' => '1 day', 'route' => 'Oral', 'administration_instruction' => null, 'precaution' => null, 'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '10.000']]]);
                $balance = DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['batch']->id)->sole();
                app(BranchAccessService::class)->select($f['receiver'], $branch);
                $governed = app(GovernedStockService::class);
                $id = $governed->propose($f['receiver'], 'writeoff', ['expected_branch_id' => $branch->id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'balance_id' => $balance->id, 'balance_version' => $balance->lock_version, 'unit' => 'unit', 'quantity' => '10.000', 'reason' => 'damaged', 'disposal_reference' => 'SYN-RACE-LOG']);
                $command = ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid()];
                $before = DB::table('stock_movements')->orderBy('id')->get()->toJson();
                $max = DB::table('stock_movements')->max('id');
                DB::beginTransaction();
                if ($winner === 'approve') {
                    $governed->act($f['finance'], $id, 'approved', $command);
                } else {
                    $dispensary->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $branch->id, 'case_lock_version' => $f['case']->lock_version]);
                }
                $loser = $winner === 'approve' ? 'dispense' : 'approve';
                $data = ['branch_id' => $branch->id, 'actor_id' => $loser === 'dispense' ? $f['ca']->id : $f['finance']->id, 'case_id' => $loser === 'dispense' ? $f['case']->id : $id, 'payload' => $command];
                $input = new InputStream;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresGovernedStockWorker.php'), $database, $loser, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
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
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
                $this->assertStringContainsString('REFUSED ', $worker->getOutput());
                $this->assertSame('0.000', DB::table('inventory_stock_balances')->where('id', $balance->id)->value('quantity'));
                $this->assertSame($before, DB::table('stock_movements')->where('id', '<=', $max)->orderBy('id')->get()->toJson());
                $new = DB::table('stock_movements')->where('id', '>', $max)->get();
                $this->assertCount(1, $new);
                $this->assertSame('10.000', $new[0]->quantity);
                $this->assertSame($winner === 'approve' ? 'stock_writeoff' : 'dispense', $new[0]->movement_type);
                $this->assertSame($winner === 'approve' ? 1 : 0, DB::table('inventory_governed_actions')->where('case_id', $id)->where('action', 'approved')->count());
                $this->assertSame($winner === 'approve' ? 1 : 0, DB::table('audit_logs')->where('event', 'inventory.governed_stock.approved')->whereRaw("metadata->>'case_id' = ?", [(string) $id])->count());
                $this->assertSame($winner === 'dispense' ? 1 : 0, DB::table('audit_logs')->where('event', 'dispensary.completed')->where('subject_id', $f['case']->id)->count());
            }
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
            DB::purge('governed_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }
}
