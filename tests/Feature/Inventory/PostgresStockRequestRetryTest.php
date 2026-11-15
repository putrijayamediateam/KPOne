<?php

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * STK-39: the same stock request with one retry key, sent twice at the same moment by one person in two real PostgreSQL
 * sessions (a double press, or a retry while the first is still saving). One request is made and both sessions get it
 * back. Synthetic data only.
 */
class PostgresStockRequestRetryTest extends TestCase
{
    public function test_two_sends_with_one_key_at_the_same_moment_make_one_request(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk_retry_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.stk_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'stk_race'], 'database.connections.stk_blocker' => [...$config, 'database' => $database, 'url' => null, 'name' => 'stk_blocker']]);
        $workers = [];
        $inputs = [];
        try {
            DB::setDefaultConnection('stk_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, Schema::getConnection()->getDatabaseName());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stk_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->firstOrFail();
            $makeActor = function () use ($branch): User {
                $actor = User::factory()->create(['organisation_id' => $branch->organisation_id, 'name' => 'Synthetic stock requester', 'email' => 'stk-'.Str::uuid().'@kpone.test']);
                $actor->assignRole('ca');
                $profile = new StaffProfile;
                $profile->forceFill(['user_id' => $actor->id, 'department_id' => Department::where('organisation_id', $branch->organisation_id)->value('id')])->save();
                StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);

                return $actor;
            };
            $requester = $makeActor();
            $item = DB::table('inventory_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'code' => 'SYN-RTY', 'generic_name' => 'Synthetic stock item', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $skuPublicId = (string) Str::uuid();
            DB::table('inventory_skus')->insert(['public_id' => $skuPublicId, 'organisation_id' => $branch->organisation_id, 'inventory_item_id' => $item, 'sku_code' => 'SYN-RTY-SKU', 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $location = function (string $code, string $type) use ($branch): string {
                $id = (string) Str::uuid();
                DB::table('inventory_locations')->insert(['public_id' => $id, 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'code' => $code, 'name' => 'Synthetic '.$code, 'type' => $type, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

                return $id;
            };
            $source = $location('SYN-RTY-STORE', 'branch_store');
            $destination = $location('SYN-RTY-DISP', 'dispensary');
            $key = (string) Str::uuid();
            $this->assertSame(0, DB::table('inventory_stock_requests')->count());

            // Hold the requester's row, so both sends wait before either looks for an earlier request with the key.
            DB::connection('stk_blocker')->beginTransaction();
            DB::connection('stk_blocker')->select('select id from users where id=? for update', [$requester->id]);
            $pids = [];
            foreach ([1, 2] as $press) {
                $input = new InputStream;
                $inputs[] = $input;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresStockRequestRetryWorker.php'), $database, (string) $requester->id, (string) $branch->id, $source, $destination, $skuPublicId, $key], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
                $worker->setInput($input)->setTimeout(60)->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $readyDeadline = microtime(true) + 30;
                while (! str_contains($worker->getOutput(), 'READY ') && $worker->isRunning() && microtime(true) < $readyDeadline) {
                    usleep(20000);
                }
                $this->assertMatchesRegularExpression('/READY (\d+)/', $worker->getOutput(), $worker->getErrorOutput());
                preg_match('/READY (\d+)/', $worker->getOutput(), $match);
                $pids[] = (int) $match[1];
            }
            foreach ($inputs as $input) {
                $input->write("GO\n");
            }
            $deadline = microtime(true) + 30;
            do {
                foreach ($workers as $worker) {
                    $worker->getOutput();
                }
                $waiting = (int) DB::selectOne("select count(*) as total from pg_stat_activity where pid in (?,?) and wait_event_type='Lock'", $pids)->total;
                if ($waiting === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both sends must wait before either looks for the key.');
            DB::connection('stk_blocker')->commit();

            $ids = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $this->assertMatchesRegularExpression('/CREATED (\d+)/', $worker->getOutput(), $worker->getOutput().$worker->getErrorOutput());
                preg_match('/CREATED (\d+)/', $worker->getOutput(), $match);
                $ids[] = (int) $match[1];
            }
            // Both presses get the same request back: one request, one line, one audit row.
            $this->assertSame($ids[0], $ids[1]);
            $this->assertSame(1, DB::table('inventory_stock_requests')->count());
            $this->assertSame(1, DB::table('inventory_stock_request_lines')->count());
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.stock_request.requested')->count());
        } finally {
            if (DB::connection('stk_blocker')->transactionLevel()) {
                DB::connection('stk_blocker')->rollBack();
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
            DB::purge('stk_blocker');
            DB::purge('stk_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }
}
