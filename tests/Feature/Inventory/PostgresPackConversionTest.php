<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\PackConversionService;
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
 * STK-52: pack conversion writes sent at the same moment in two real PostgreSQL sessions. Two checkers on one waiting
 * change record one outcome; two proposals at once record one waiting version, with gapless versions. Synthetic data only.
 */
class PostgresPackConversionTest extends TestCase
{
    public function test_two_checkers_on_one_change_record_one_outcome(): void
    {
        $this->race(function (array $people, InventorySku $sku): array {
            $waiting = app(PackConversionService::class)->propose($people['administrator'], $sku->public_id, ['expected_version' => 0, 'idempotency_key' => (string) Str::uuid(), 'pack_unit' => 'bottle', 'base_per_pack' => 60]);

            return [['check', $people['ca_supervisor'], $waiting, 60], ['check', $people['director'], $waiting, 60]];
        }, function (array $outputs): void {
            $this->assertCount(1, array_filter($outputs, fn (string $o): bool => str_contains($o, 'CHECKED')));
            $this->assertCount(1, array_filter($outputs, fn (string $o): bool => str_contains($o, 'REFUSED This change was already decided.')));
            $this->assertSame(1, DB::table('inventory_pack_conversion_outcomes')->count());
        });
    }

    public function test_two_proposals_at_once_record_one_waiting_version(): void
    {
        $this->race(fn (array $people, InventorySku $sku): array => [['propose', $people['director'], $sku->public_id, 60], ['propose', $people['ca_supervisor'], $sku->public_id, 50]],
            function (array $outputs): void {
                $this->assertCount(1, array_filter($outputs, fn (string $o): bool => str_contains($o, 'RECORDED')));
                $this->assertCount(1, array_filter($outputs, fn (string $o): bool => str_contains($o, 'REFUSED')));
                $this->assertSame([1], DB::table('inventory_pack_conversions')->pluck('version')->map(fn ($v) => (int) $v)->all());
            });
    }

    /**
     * @param  callable(array<string, User>, InventorySku): list<array{0: string, 1: User, 2: string, 3: int}>  $arrange
     * @param  callable(list<string>): void  $assert
     */
    private function race(callable $arrange, callable $assert): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_stk_pack_race_'.strtolower(Str::random(10)).'_testing';
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
            $people = [];
            foreach (['administrator', 'ca_supervisor', 'director'] as $role) {
                $actor = User::factory()->create(['organisation_id' => $branch->organisation_id, 'name' => 'Synthetic '.$role, 'email' => 'stk-'.Str::uuid().'@kpone.test']);
                $actor->assignRole($role);
                $profile = new StaffProfile;
                $profile->forceFill(['user_id' => $actor->id, 'department_id' => Department::where('organisation_id', $branch->organisation_id)->value('id')])->save();
                StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);
                $people[$role] = $actor;
            }
            $item = DB::table('inventory_items')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'code' => 'SYN-SYRUP', 'generic_name' => 'Synthetic syrup', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $skuId = DB::table('inventory_skus')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $branch->organisation_id, 'inventory_item_id' => $item, 'sku_code' => 'SYN-SYRUP', 'pack_size' => '60.000', 'purchase_unit' => 'bottle', 'stock_unit' => 'ml', 'dispensing_unit' => 'ml', 'unit_conversion' => '1.000', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $sku = InventorySku::findOrFail($skuId);
            session([BranchAccessService::SESSION_KEY => $branch->id]);
            app(InventoryReadinessService::class)->verify($people['director'], 'unit', $sku->public_id, ['expected_branch_id' => $branch->id, 'base_unit' => 'ml', 'lock_version' => 0, 'retry_key' => (string) Str::uuid()]);
            $plans = $arrange($people, $sku);

            // Hold the SKU row, so both writers wait on it before either reads the conversion state.
            DB::connection('stk_blocker')->beginTransaction();
            DB::connection('stk_blocker')->select('select id from inventory_skus where id=? for update', [$skuId]);
            $pids = [];
            foreach ($plans as [$mode, $actor, $subject, $count]) {
                $input = new InputStream;
                $inputs[] = $input;
                $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresPackConversionWorker.php'), $database, $mode, (string) $actor->id, $subject, (string) $count], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '']);
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
            $this->assertSame(2, $waiting, 'Both writers must wait on the held SKU row.');
            DB::connection('stk_blocker')->commit();

            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $outputs[] = $worker->getOutput();
            }
            $assert($outputs);
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
