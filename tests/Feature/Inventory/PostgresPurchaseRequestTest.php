<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Organisation\Inventory\Services\PurchaseRequestService;
use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\VerifiedReceivingFixture;
use Tests\TestCase;

/** STK-18: two people deciding one purchase request at the same moment leave exactly one decision and at most one order. */
final class PostgresPurchaseRequestTest extends TestCase
{
    public function test_concurrent_decisions_on_one_request_have_one_winner(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk18_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.request_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'request_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('request_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame([$database, 'UTF8'], [$identity->database, $identity->encoding]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'request_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();
            $fx = new VerifiedReceivingFixture($branch);
            $f = $fx->build();
            $service = app(PurchaseRequestService::class);
            $at = function (User $actor) use ($branch): User {
                app(BranchAccessService::class)->select($actor, $branch);

                return $actor;
            };
            $ca = $at($fx->actor('ca'));
            $send = fn (): string => $service->send($ca, ['expected_branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'destination_location_public_id' => $f['location']->public_id,
                'lines' => [['sku_public_id' => $f['sku']->public_id, 'quantity' => '10']]]);
            $convertPayload = fn (string $id): array => ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'supplier_public_id' => $f['supplier']->public_id,
                'lines' => [['line_id' => (int) DB::table('inventory_purchase_request_lines as l')->join('inventory_purchase_requests as r', 'r.id', '=', 'l.purchase_request_id')->where('r.public_id', $id)->value('l.id'), 'quantity' => '10']]];
            $supervisor = $at($fx->actor('ca_supervisor'));
            $finance = $fx->actor('finance_officer');
            $orders = DB::table('inventory_purchase_orders')->count();

            // Race 1: two reviewers convert the same request; one order, one conversion.
            $id = $send();
            DB::beginTransaction();
            $service->convert($supervisor, $id, $convertPayload($id));
            $worker = $this->race($database, $config, 'convert', ['branch_id' => $branch->id, 'actor_id' => $finance->id, 'request' => $id, 'payload' => $convertPayload($id)]);
            $this->assertMatchesRegularExpression('/REFUSED (lock_version|status)/', $worker->getOutput());
            $this->assertSame($orders + 1, DB::table('inventory_purchase_orders')->count());
            $this->assertSame([$supervisor->id], DB::table('inventory_purchase_request_actions as a')->join('inventory_purchase_requests as r', 'r.id', '=', 'a.purchase_request_id')
                ->where('r.public_id', $id)->where('a.action', 'converted')->pluck('a.actor_user_id')->map(fn ($v) => (int) $v)->all());

            // Race 2: the requester withdraws while a reviewer converts; no order is made.
            $id = $send();
            DB::beginTransaction();
            $service->withdraw($ca, $id, ['expected_branch_id' => $branch->id, 'lock_version' => 1]);
            $worker = $this->race($database, $config, 'convert', ['branch_id' => $branch->id, 'actor_id' => $finance->id, 'request' => $id, 'payload' => $convertPayload($id)]);
            $this->assertMatchesRegularExpression('/REFUSED (lock_version|status)/', $worker->getOutput());
            $this->assertSame(['withdrawn', $orders + 1], [DB::table('inventory_purchase_requests')->where('public_id', $id)->value('status'), DB::table('inventory_purchase_orders')->count()]);

            // Race 3: one reviewer converts while another rejects; the conversion stands alone.
            $id = $send();
            DB::beginTransaction();
            $service->convert($supervisor, $id, $convertPayload($id));
            $worker = $this->race($database, $config, 'reject', ['branch_id' => $branch->id, 'actor_id' => $finance->id, 'request' => $id, 'payload' => ['expected_branch_id' => $branch->id, 'lock_version' => 1, 'reason' => 'budget']]);
            $this->assertMatchesRegularExpression('/REFUSED (lock_version|status)/', $worker->getOutput());
            $this->assertSame(['converted', null], array_values((array) DB::table('inventory_purchase_requests')->where('public_id', $id)->first(['status', 'reject_reason'])));
            $this->assertSame($orders + 2, DB::table('inventory_purchase_orders')->count());
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
            DB::purge('request_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits. */
    private function race(string $database, array $config, string $mode, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresPurchaseRequestWorker.php'), $database, $mode, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
