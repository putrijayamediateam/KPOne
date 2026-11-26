<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Models\HandCost;
use App\Domain\Organisation\Inventory\Services\HandCostService;
use App\Domain\Organisation\Models\Branch;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\GovernedStockFixture;
use Tests\Support\HandCostFixture;
use Tests\TestCase;

/** Two real sessions must wait on the receipt before any hand-cost decision can race a correction. */
final class PostgresHandCostRaceTest extends TestCase
{
    public function test_two_checkers_record_one_decision_and_one_audit(): void
    {
        $this->race('checkers');
    }

    public function test_correction_and_entry_serialize_at_the_receipt(): void
    {
        $this->race('enter');
    }

    public function test_correction_and_acceptance_serialize_at_the_receipt(): void
    {
        $this->race('accept');
    }

    private function race(string $mode): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_hand_cost_race_'.strtolower(Str::random(10)).'_test';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.hand_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'hand_race'],
            'database.connections.hand_blocker' => [...$config, 'database' => $database, 'url' => null, 'name' => 'hand_blocker']]);
        $workers = [];
        $inputs = [];
        try {
            DB::setDefaultConnection('hand_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, Schema::getConnection()->getDatabaseName());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'hand_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::where('code', 'CHERAS')->sole();
            $fixture = new HandCostFixture($branch);
            $f = $fixture->receipt();
            app(BranchAccessService::class)->select($f['finance'], $branch);
            $entry = ['expected_branch_id' => $branch->id, 'movement_public_id' => $f['movement']->public_id,
                'lock_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'cost_total_sen' => 1200, 'reason_code' => 'supplier_invoice_found'];
            $cost = $mode === 'enter' ? null : app(HandCostService::class)->enter($f['finance'], $entry);
            $decision = ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'status' => 'accepted'];
            if ($mode === 'checkers') {
                $checker = (new GovernedStockFixture($branch))->actor('store_admin');
                $operations = [['accept', $f['director'], ['public_id' => $cost->public_id, 'input' => $decision]],
                    ['accept', $checker, ['public_id' => $cost->public_id, 'input' => [...$decision, 'idempotency_key' => (string) Str::uuid()]]]];
            } else {
                $correction = $fixture->correction($f);
                $operations = [['correct', $f['receiver'], $correction],
                    [$mode, $mode === 'enter' ? $f['finance'] : $f['director'], $mode === 'enter' ? ['input' => $entry] : ['public_id' => $cost->public_id, 'input' => $decision]]];
            }
            $blocker = DB::connection('hand_blocker');
            $blocker->beginTransaction();
            $blockerPid = (int) $blocker->selectOne('select pg_backend_pid() as pid')->pid;
            $blocker->select('select id from inventory_goods_receipts where id=? for update', [$f['receipt']->id]);
            $pids = [];
            foreach ($operations as [$operation, $actor, $payload]) {
                $input = new InputStream;
                $inputs[] = $input;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/HandCostRaceWorker.php'), $database, $operation, (string) $actor->id, (string) $branch->id, json_encode($payload, JSON_THROW_ON_ERROR)], base_path(), [
                    'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'],
                    'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'],
                    'DB_PASSWORD' => $config['password'] ?? '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer', 'DB_URL' => '',
                    'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array',
                ]);
                $worker->setInput($input)->setTimeout(ContentionTimeouts::processTimeoutSeconds())->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
                while (! str_contains($worker->getOutput(), 'READY ') && $worker->isRunning() && microtime(true) < $deadline) {
                    usleep(20000);
                }
                $this->assertMatchesRegularExpression('/READY (\d+)/', $worker->getOutput(), $worker->getErrorOutput());
                preg_match('/READY (\d+)/', $worker->getOutput(), $matches);
                $pids[] = (int) $matches[1];
            }
            foreach ($inputs as $input) {
                $input->write("GO\n");
            }
            $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
            do {
                foreach ($workers as $worker) {
                    $worker->getOutput(); // Pump queued GO bytes into each worker's stdin.
                }
                // Include a worker queued behind the other worker on this same held receipt.
                $waiting = (int) DB::selectOne("select count(*) as n from pg_stat_activity where pid in (?,?) and wait_event_type='Lock'", $pids)->n;
                $direct = (int) DB::selectOne('select count(*) as n from pg_stat_activity where pid in (?,?) and ? = any(pg_blocking_pids(pid))', [...$pids, $blockerPid])->n;
                if ($waiting === 2 && $direct >= 1) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both commands must be blocked before the receipt lock is released.');
            $this->assertGreaterThanOrEqual(1, $direct, 'The synthetic blocker must be the cause of contention.');
            $blocker->commit();
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertMatchesRegularExpression('/RECORDED|REFUSED/', $worker->getOutput());
                $outcomes[] = str_contains($worker->getOutput(), 'RECORDED');
            }
            if ($mode === 'checkers') {
                $this->assertEqualsCanonicalizing([true, false], $outcomes);
                $this->assertSame('accepted', HandCost::sole()->status);
                $this->assertSame(2, HandCost::sole()->lock_version);
                $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.hand_cost.accepted')->count());
            } else {
                $this->assertTrue($outcomes[0], 'Receiving correction always completes; hand costs do not move stock.');
                $this->assertSame(1, DB::table('inventory_receipt_corrections')->where('goods_receipt_id', $f['receipt']->id)->count());
                if ($mode === 'enter') {
                    $this->assertSame($outcomes[1] ? 1 : 0, HandCost::query()->count());
                    if ($outcomes[1]) {
                        $this->assertSame('waiting', HandCost::sole()->status);
                    }
                } else {
                    $this->assertSame($outcomes[1] ? 'accepted' : 'waiting', $cost->fresh()->status);
                    $this->assertSame($outcomes[1] ? 1 : 0, DB::table('audit_logs')->where('event', 'inventory.hand_cost.accepted')->count());
                }
                if (! $outcomes[1]) {
                    $this->assertStringContainsString('reversed', $workers[1]->getOutput());
                }
            }
        } finally {
            if (DB::connection('hand_blocker')->transactionLevel()) {
                DB::connection('hand_blocker')->rollBack();
            }
            foreach ($inputs as $input) {
                if (! $input->isClosed()) {
                    $input->close();
                }
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            DB::purge('hand_blocker');
            DB::purge('hand_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }
}
