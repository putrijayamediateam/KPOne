<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\DifferenceReportService;
use App\Domain\Organisation\Inventory\Services\GovernedStockService;
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
 * STK-45 races on a disposable PostgreSQL database: (1) two people reporting the same balance at once give one count
 * case and two reports; (2) a report against a count on the same case (case lock first, then the source) finishes
 * without a deadlock and joins the case.
 */
final class PostgresDifferenceReportTest extends TestCase
{
    private ?Process $worker = null;

    private string $database = '';

    /** @var array<string,mixed> */
    private array $config = [];

    public function test_two_reports_on_one_balance_give_one_case_and_two_reports(): void
    {
        $this->withRaceDatabase(function (Branch $branch): void {
            $f = (new GovernedStockFixture($branch))->build('10.000');
            [$first, $second] = [$this->actor($branch, 'ca'), $this->actor($branch, 'ca')];
            DB::beginTransaction();
            $mine = app(DifferenceReportService::class)->report($first, $this->payload($branch, $f));
            $output = $this->raceWorker($branch, $second, $this->payload($branch, $f));
            $this->assertTrue($mine['openedCase']);
            $this->assertStringContainsString('JOINED '.$mine['caseId'], $output);
            $this->assertSame(1, DB::table('inventory_governed_cases')->where('balance_id', $f['balance']->id)->count());
            $this->assertSame(2, DB::table('inventory_difference_reports')->where('case_id', $mine['caseId'])->count());
            $this->assertSame(0, DB::table('stock_movements')->where('reference_type', 'inventory_governed_case')->count());
        });
    }

    public function test_a_report_against_a_count_on_the_same_case_does_not_deadlock(): void
    {
        $this->withRaceDatabase(function (Branch $branch): void {
            $f = (new GovernedStockFixture($branch))->build('10.000');
            $opened = app(DifferenceReportService::class)->report($this->actor($branch, 'ca'), $this->payload($branch, $f));
            $counter = $this->actor($branch, 'ca_supervisor');
            $reporter = $this->actor($branch, 'ca');
            DB::beginTransaction();
            app(GovernedStockService::class)->act($counter, $opened['caseId'], 'counted', ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'retry_key' => (string) Str::uuid(), 'quantity' => '8.000', 'unit' => 'unit']);
            $output = $this->raceWorker($branch, $reporter, $this->payload($branch, $f));
            $this->assertStringContainsString('JOINED '.$opened['caseId'], $output);
            $this->assertSame(2, DB::table('inventory_difference_reports')->where('case_id', $opened['caseId'])->count());
            $this->assertSame('awaiting_recount', DB::table('inventory_governed_actions')->where('case_id', $opened['caseId'])->orderByDesc('lock_version')->value('state'));
        });
    }

    private function actor(Branch $branch, string $role): User
    {
        $actor = (new GovernedStockFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /** @return array<string,mixed> */
    private function payload(Branch $branch, array $f): array
    {
        $balance = DB::table('inventory_stock_balances')->where('id', $f['balance']->id)->sole();

        return ['expected_branch_id' => $branch->id, 'retry_key' => (string) Str::uuid(), 'balance_id' => $balance->id, 'balance_version' => $balance->lock_version, 'observed' => 'fewer'];
    }

    /** Start the worker while this test holds its transaction, prove it waits on a lock, then commit and let it finish. */
    private function raceWorker(Branch $branch, User $actor, array $payload): string
    {
        $data = ['branch_id' => $branch->id, 'actor_id' => $actor->id, 'payload' => $payload];
        $input = new InputStream;
        $c = $this->config;
        $this->worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresDifferenceReportWorker.php'), $this->database, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $this->database, 'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'] ?? '', 'DB_URL' => '']);
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
        $this->database = 'kpone_stk45_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$this->database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.difference_race' => [...$this->config, 'database' => $this->database, 'url' => null, 'name' => 'difference_race']]);
        try {
            DB::setDefaultConnection('difference_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($this->database, DB::selectOne('SELECT current_database() as database')->database);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'difference_race', '--force' => true]));
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
            DB::purge('difference_race');
            DB::statement('DROP DATABASE "'.$this->database.'" WITH (FORCE)');
        }
    }
}
