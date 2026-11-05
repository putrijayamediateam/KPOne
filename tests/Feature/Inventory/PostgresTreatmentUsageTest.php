<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\TreatmentUsageService;
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

final class PostgresTreatmentUsageTest extends TestCase
{
    public function test_concurrent_recordings_and_reversals_have_one_winner(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk8_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.usage_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'usage_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('usage_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame('UTF8', $identity->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'usage_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();
            $service = app(TreatmentUsageService::class);
            $fx = new SupplierReturnFixture($branch);
            $f = $fx->received('10.000');
            $balance = fn (): string => (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $f['location']->id)->where('inventory_batch_id', $f['returnBatchId'])->value('quantity');
            $entry = fn (): array => ['expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'location_id' => $f['location']->id, 'batch_id' => $f['returnBatchId'], 'unit' => $f['sku']->stock_unit, 'quantity' => '6.000', 'kind' => 'usage', 'reason' => 'procedure'];

            // Race 1: two CAs each record 6 from a balance of 10; one wins, the other is refused on quantity.
            $first = $fx->actor('ca');
            $second = $fx->actor('ca');
            app(BranchAccessService::class)->select($first, $branch);
            DB::beginTransaction();
            $id = $service->record($first, $entry());
            $worker = $this->race($database, $config, 'record', ['branch_id' => $branch->id, 'actor_id' => $second->id, 'payload' => $entry()]);
            $this->assertStringContainsString('REFUSED quantity', $worker->getOutput());
            $this->assertSame(1, DB::table('inventory_treatment_usages')->count());
            $this->assertSame('4.000', $balance());

            // Race 2: a CA Supervisor and a Finance Officer reverse the same entry; exactly one credit posts.
            $supervisor = $fx->actor('ca_supervisor');
            $finance = $fx->actor('finance_officer');
            $undo = fn (): array => ['expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'reason' => 'recorded_in_error'];
            app(BranchAccessService::class)->select($supervisor, $branch);
            $auditMax = (int) DB::table('audit_logs')->max('id');
            DB::beginTransaction();
            $service->reverse($supervisor, $id, $undo());
            $worker = $this->race($database, $config, 'reverse', ['branch_id' => $branch->id, 'actor_id' => $finance->id, 'usage_id' => $id, 'payload' => $undo()]);
            $this->assertStringContainsString('REFUSED state', $worker->getOutput());
            $this->assertSame([$supervisor->id], DB::table('inventory_treatment_usage_reversals')->pluck('actor_user_id')->map(fn ($v) => (int) $v)->all());
            $this->assertSame(1, DB::table('audit_logs')->where('id', '>', $auditMax)->where('event', 'inventory.treatment_usage.reversed')->count());
            $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'adjustment_in')->where('reference_type', 'inventory_treatment_usage')->count());
            $this->assertSame('10.000', $balance());
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
            DB::purge('usage_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits. */
    private function race(string $database, array $config, string $mode, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresTreatmentUsageWorker.php'), $database, $mode, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
