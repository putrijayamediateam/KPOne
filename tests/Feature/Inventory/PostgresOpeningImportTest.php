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

/**
 * STK-38 (system plan item 1c): an opening-stock import (STK-35) and a single opening for the same location, SKU and
 * batch, sent at the same moment by two CA Supervisors in two real PostgreSQL sessions. Exactly one opening is recorded;
 * the other is refused with the existing message, and a refused import leaves nothing behind. Synthetic data only.
 */
class PostgresOpeningImportTest extends TestCase
{
    public function test_an_import_and_a_single_opening_for_the_same_batch_record_one_opening_only(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk_import_race_'.strtolower(Str::random(10)).'_testing';
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
                $actor = User::factory()->create(['organisation_id' => $branch->organisation_id, 'name' => 'Synthetic stock supervisor', 'email' => 'stk-'.Str::uuid().'@kpone.test']);
                $actor->assignRole('ca_supervisor');
                $profile = new StaffProfile;
                $profile->forceFill(['user_id' => $actor->id, 'department_id' => Department::where('organisation_id', $branch->organisation_id)->value('id')])->save();
                StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);

                return $actor;
            };
            $single = $makeActor();
            $importer = $makeActor();
            $expiry = now($branch->timezone)->addYear()->toDateString();
            $item = DB::table('inventory_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'code' => 'SYN-IMP', 'generic_name' => 'Synthetic stock item', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $skuId = DB::table('inventory_skus')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'inventory_item_id' => $item, 'sku_code' => 'SYN-IMP-SKU', 'pack_size' => '1.000', 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => '1.000', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $batchPublicId = (string) Str::uuid();
            DB::table('inventory_batches')->insert(['public_id' => $batchPublicId, 'organisation_id' => $branch->organisation_id, 'inventory_sku_id' => $skuId, 'batch_number' => 'SYN-IMP-BATCH', 'expiry_date' => $expiry, 'received_at' => now(), 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
            $location = DB::table('inventory_locations')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'branch_id' => $branch->id, 'code' => 'SYN-IMP-LOC', 'name' => 'Synthetic location', 'type' => 'branch_store', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            session([BranchAccessService::SESSION_KEY => $branch->id]);
            // STK-1: the SKU's base unit is verified, so an opening may be recorded at all.
            app(InventoryReadinessService::class)->verify($single, 'unit', InventorySku::findOrFail($skuId)->public_id, ['expected_branch_id' => $branch->id, 'base_unit' => 'unit', 'lock_version' => 0, 'retry_key' => (string) Str::uuid()]);
            $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'opening_balance')->count());

            // Hold the location row, so both writers wait on it before either checks for an earlier opening.
            DB::connection('stk_blocker')->beginTransaction();
            DB::connection('stk_blocker')->select('select id from inventory_locations where id=? for update', [$location]);
            $pids = [];
            $modes = [];
            foreach ([['single', $single], ['import', $importer]] as [$mode, $actor]) {
                $input = new InputStream;
                $inputs[] = $input;
                $modes[] = $mode;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresOpeningImportWorker.php'), $database, $mode, (string) $actor->id, (string) $branch->id, 'SYN-IMP-LOC', 'SYN-IMP-SKU', $batchPublicId, 'SYN-IMP-BATCH', $expiry, (string) Str::uuid()], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
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
            $this->assertSame(2, $waiting, 'The import and the single opening must both wait on the held location row.');
            DB::connection('stk_blocker')->commit();

            $outcomes = [];
            foreach ($workers as $index => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $output = $worker->getOutput();
                $outcomes[$modes[$index]] = str_contains($output, 'RECORDED') ? 'recorded' : 'refused';
                if (str_contains($output, 'REFUSED')) {
                    $this->assertStringContainsString('An opening balance has already been recorded for this location, SKU, and batch.', $output);
                }
            }
            $this->assertEqualsCanonicalizing(['recorded', 'refused'], array_values($outcomes));
            // One opening, one balance row holding only the winner's quantity; a refused import leaves no import row or batch.
            $openings = DB::table('stock_movements')->where('movement_type', 'opening_balance')->get();
            $this->assertCount(1, $openings);
            $winnerQuantity = $outcomes['import'] === 'recorded' ? '7.000' : '4.000';
            $this->assertSame($winnerQuantity, (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $location)->value('quantity'));
            $this->assertSame(1, DB::table('inventory_stock_balances')->count());
            $this->assertSame($outcomes['import'] === 'recorded' ? 1 : 0, DB::table('inventory_opening_imports')->count());
            $this->assertSame(1, DB::table('inventory_batches')->count());
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
