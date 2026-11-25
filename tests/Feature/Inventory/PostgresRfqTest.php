<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\RfqService;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\VerifiedReceivingFixture;
use Tests\TestCase;

/**
 * STK-48 races on a disposable PostgreSQL database: (1) two people choosing different quotes in one round at once
 * leave exactly one chosen; (2) two conversions of one round at once make exactly one purchase order. The second
 * person waits on the round lock and is then refused.
 */
final class PostgresRfqTest extends TestCase
{
    private ?Process $worker = null;

    private string $database = '';

    /** @var array<string,mixed> */
    private array $config = [];

    public function test_two_people_choosing_in_one_round_leave_exactly_one_chosen(): void
    {
        $this->withRaceDatabase(function (Branch $branch): void {
            [$f, $round] = $this->quotedRound($branch);
            $other = $this->actor($branch, 'finance_officer');
            [$first, $second] = DB::table('inventory_rfqs')->orderBy('id')->get()->all();
            DB::beginTransaction();
            app(RfqService::class)->choose($f['finance'], $first->public_id, ['expected_branch_id' => $branch->id, 'lock_version' => (int) $first->lock_version, 'reason' => 'preferred_supplier']);
            $output = $this->raceWorker($branch, $other, ['action' => 'choose', 'rfq' => $second->public_id,
                'payload' => ['expected_branch_id' => $branch->id, 'lock_version' => (int) $second->lock_version, 'reason' => 'preferred_supplier']]);
            $this->assertStringContainsString('REFUSED Another quote in this round is already chosen.', $output);
            $this->assertSame([(int) $first->id], DB::table('inventory_rfqs')->where('rfq_round_id', $round->id)->where('status', 'chosen')->pluck('id')->map(fn ($id) => (int) $id)->all());
        });
    }

    public function test_two_conversions_of_one_round_make_exactly_one_purchase_order(): void
    {
        $this->withRaceDatabase(function (Branch $branch): void {
            [$f, $round] = $this->quotedRound($branch);
            $rfq = DB::table('inventory_rfqs')->orderBy('id')->first();
            app(RfqService::class)->choose($f['finance'], $rfq->public_id, ['expected_branch_id' => $branch->id, 'lock_version' => (int) $rfq->lock_version, 'reason' => 'preferred_supplier']);
            $orders = DB::table('inventory_purchase_orders')->count();
            $other = $this->actor($branch, 'finance_officer');
            DB::beginTransaction();
            $mine = app(RfqService::class)->convert($f['finance'], $round->public_id, ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
            $output = $this->raceWorker($branch, $other, ['action' => 'convert', 'round' => $round->public_id,
                'payload' => ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]]);
            $this->assertStringContainsString('REFUSED', $output);
            $this->assertSame($orders + 1, DB::table('inventory_purchase_orders')->count());
            $this->assertSame(1, DB::table('inventory_rfqs')->where('rfq_round_id', $round->id)->whereNotNull('purchase_order_id')->count());
            $this->assertSame($mine['order'], DB::table('inventory_purchase_orders')->where('id', DB::table('inventory_rfqs')->whereNotNull('purchase_order_id')->value('purchase_order_id'))->value('public_id'));
        });
    }

    /** A committed round with two suppliers, both quoted. */
    private function quotedRound(Branch $branch): array
    {
        $f = (new VerifiedReceivingFixture($branch))->build();
        app(BranchAccessService::class)->select($f['finance'], $branch);
        $second = app(SupplierAdministrationService::class)->create($f['finance'], ['code' => 'SYN-RACE-'.Str::upper(Str::random(6)), 'name' => 'Synthetic race supplier',
            'business_email' => 'race@supplier.test', 'contact_name' => null, 'business_phone' => null]);
        $service = app(RfqService::class);
        $publicId = $service->start($f['finance'], ['expected_branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'destination_location_public_id' => $f['location']->public_id,
            'quote_by_date' => now()->setTimezone($branch->timezone)->addDays(5)->toDateString(), 'supplier_public_ids' => [$f['supplier']->public_id, $second->public_id],
            'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '30']]]);
        $round = DB::table('inventory_rfq_rounds')->where('public_id', $publicId)->sole();
        $line = DB::table('inventory_rfq_round_lines')->where('rfq_round_id', $round->id)->value('id');
        foreach (DB::table('inventory_rfqs')->where('rfq_round_id', $round->id)->orderBy('id')->get() as $index => $rfq) {
            $service->recordQuote($f['finance'], $rfq->public_id, ['expected_branch_id' => $branch->id, 'lock_version' => (int) $rfq->lock_version, 'idempotency_key' => (string) Str::uuid(),
                'lead_time_days' => 5, 'valid_until' => now()->setTimezone($branch->timezone)->addDays(20)->toDateString(),
                'lines' => [['line_id' => $line, 'cannot_supply' => false, 'pack_price' => $index === 0 ? '12.00' : '11.00', 'pack_size' => '30']]]);
        }

        return [$f, $round];
    }

    private function actor(Branch $branch, string $role): User
    {
        $actor = (new VerifiedReceivingFixture($branch))->actor($role);
        app(BranchAccessService::class)->select($actor, $branch);

        return $actor;
    }

    /** Start the worker while this test holds its transaction, prove it waits on a lock, then commit and let it finish. */
    private function raceWorker(Branch $branch, User $actor, array $job): string
    {
        $data = ['branch_id' => $branch->id, 'actor_id' => $actor->id, ...$job];
        $input = new InputStream;
        $c = $this->config;
        $this->worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresRfqWorker.php'), $this->database, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $this->database, 'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'] ?? '', 'DB_URL' => '']);
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
        $this->database = 'kpone_stk48_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$this->database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.rfq_race' => [...$this->config, 'database' => $this->database, 'url' => null, 'name' => 'rfq_race']]);
        try {
            DB::setDefaultConnection('rfq_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($this->database, DB::selectOne('SELECT current_database() as database')->database);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'rfq_race', '--force' => true]));
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
            DB::purge('rfq_race');
            DB::statement('DROP DATABASE "'.$this->database.'" WITH (FORCE)');
        }
    }
}
