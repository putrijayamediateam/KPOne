<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
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

class PostgresStockReadinessTest extends TestCase
{
    public function test_migration_preserves_legacy_rows_and_two_connections_cannot_both_sign_one_opening(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.stk_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'stk_race'], 'database.connections.stk_blocker' => [...$config, 'database' => $database, 'url' => null, 'name' => 'stk_blocker']]);
        $workers = [];
        $inputs = [];
        try {
            DB::setDefaultConnection('stk_race');
            $this->assertSame('stk_race', DB::connection()->getName());
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, Schema::getConnection()->getDatabaseName());
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => ! str_contains($path, '2026_10_31_000')));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stk_race', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $branch = Branch::query()->firstOrFail();
            $makeActor = function (string $role) use ($branch): User {
                $actor = User::factory()->create(['organisation_id' => $branch->organisation_id, 'name' => 'Synthetic stock checker', 'email' => 'stk-'.Str::uuid().'@kpone.test']);
                $actor->assignRole($role);
                $profile = new StaffProfile;
                $profile->forceFill(['user_id' => $actor->id, 'department_id' => Department::where('organisation_id', $branch->organisation_id)->value('id')])->save();
                StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);

                return $actor;
            };
            $recorder = $makeActor('ca_supervisor');
            $one = $makeActor('finance_officer');
            $two = $makeActor('director');
            $item = DB::table('inventory_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'code' => 'SYN-RACE', 'generic_name' => 'Synthetic stock item', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $skuId = DB::table('inventory_skus')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'inventory_item_id' => $item, 'sku_code' => 'SYN-RACE-SKU', 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $batch = DB::table('inventory_batches')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'inventory_sku_id' => $skuId, 'batch_number' => 'SYN-RACE-BATCH', 'expiry_date' => now()->addYear()->toDateString(), 'received_at' => now(), 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
            $location = DB::table('inventory_locations')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'code' => 'SYN-RACE-LOC', 'name' => 'Synthetic location', 'type' => 'branch_store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $openingKey = (string) Str::uuid();
            $openingId = DB::table('stock_movements')->insertGetId(['public_id' => $openingKey, 'organisation_id' => $branch->organisation_id, 'inventory_sku_id' => $skuId, 'inventory_batch_id' => $batch, 'destination_location_id' => $location, 'quantity' => '2.125', 'movement_type' => 'opening_balance', 'reference_type' => 'inventory_opening_balance', 'reference_public_id' => (string) Str::uuid(), 'actor_user_id' => $recorder->id, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_stock_balances')->insert(['organisation_id' => $branch->organisation_id, 'inventory_location_id' => $location, 'inventory_sku_id' => $skuId, 'inventory_batch_id' => $batch, 'quantity' => '2.125', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $before = (array) DB::table('stock_movements')->where('id', $openingId)->first();
            $balance = DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson();
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'stk_race', '--force' => true]));
            $after = (array) DB::table('stock_movements')->where('id', $openingId)->first();
            $this->assertNull($after['unit_snapshot']);
            unset($after['unit_snapshot']);
            $this->assertSame($before, $after);
            $this->assertSame($balance, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
            session([BranchAccessService::SESSION_KEY => $branch->id]);
            app(InventoryReadinessService::class)->verify($recorder, 'unit', InventorySku::findOrFail($skuId)->public_id, ['expected_branch_id' => $branch->id, 'base_unit' => 'unit', 'lock_version' => 0, 'retry_key' => (string) Str::uuid()]);
            DB::connection('stk_blocker')->beginTransaction();
            DB::connection('stk_blocker')->select('select id from inventory_skus where id=? for update', [$skuId]);
            $pids = [];
            foreach ([$one, $two] as $actor) {
                $input = new InputStream;
                $inputs[] = $input;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresStockReadinessWorker.php'), $database, (string) $actor->id, (string) $branch->id, $openingKey, (string) Str::uuid()], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
                $worker->setInput($input)->setTimeout(30)->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $readyDeadline = microtime(true) + 15;
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
            $deadline = microtime(true) + 15;
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
            $this->assertSame(2, $waiting, 'Both independent sessions must contend on the SKU lock.');
            DB::connection('stk_blocker')->commit();
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outcomes[] = str_contains($worker->getOutput(), 'VERIFIED') ? 'verified' : 'refused';
            }
            sort($outcomes);
            $this->assertSame(['refused', 'verified'], $outcomes);
            $this->assertSame(1, DB::table('inventory_opening_verifications')->count());
            $this->assertSame($balance, DB::table('inventory_stock_balances')->orderBy('id')->get()->toJson());
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
