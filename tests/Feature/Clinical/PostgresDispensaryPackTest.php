<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Inventory\Services\InventoryReadinessService;
use App\Domain\Organisation\Inventory\Services\PackConversionService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\OtcDispensaryFixtures;
use Tests\TestCase;

/**
 * DSP-24 contention (PostgreSQL, disposable database). A save in bottles reads the checked STK-52 conversion with the
 * SKU row locked, and every conversion write locks that row too, so a save and a new check are serialised: a save that
 * waits behind a new check is refused ("the bottle size changed"), and a check that lands after a save makes Complete
 * ask for the line to be saved again. Synthetic data only.
 */
final class PostgresDispensaryPackTest extends ClinicalTestCase
{
    use OtcDispensaryFixtures;

    protected function setUp(): void
    {
        TestCase::setUp();
    }

    public function refreshDatabase(): void {}

    public function test_a_save_in_bottles_and_a_new_bottle_size_are_serialised(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_dsp24_stk_pack_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.pack_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'pack_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('pack_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, DB::selectOne('SELECT current_database() AS database')->database);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pack_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->sole();
            $this->branch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'CHERAS')->sole();

            $f = $this->fixture();
            $director = $this->actor('director');
            $this->selectBranch($director, $f['visit']->branch);
            app(InventoryReadinessService::class)->verify($director, 'unit', $f['sku']->public_id, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => 0, 'retry_key' => (string) Str::uuid(), 'base_unit' => 'unit']);
            $conversions = app(PackConversionService::class);
            $v1 = $this->propose($director, $f, 5);
            $conversions->check($this->actor('ca_supervisor'), $v1, ['idempotency_key' => (string) Str::uuid(), 'checked_base_per_pack' => 5]);
            $this->selectBranch($f['ca'], $f['visit']->branch);
            $case = $this->open($f);
            $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '10.000'));
            $this->confirmAllergy($f, $case);

            // Race 1: a supervisor checks 1 bottle = 10 units while the CA saves 2 bottles of 5. The save waits on
            // the SKU lock, then sees the new version and is refused; nothing is saved in bottles.
            $v2 = $this->propose($director, $f, 10);
            DB::beginTransaction();
            $conversions->check($this->actor('ca_supervisor'), $v2, ['idempotency_key' => (string) Str::uuid(), 'checked_base_per_pack' => 10]);
            $worker = $this->race($database, $config, $f['ca'], $case->refresh(), $item->refresh(), $this->packs($f, $case, $item, 2, $v1, 1));
            $this->assertStringContainsString('REFUSED packs The bottle size for this medicine changed. Reload the page.', $worker->getOutput());
            $this->assertNull($item->refresh()->packs_dispensed);

            // Race 2: the CA saves 1 bottle of 10 first, and a new check of 1 bottle = 5 lands right after. The check
            // waits on the SKU lock; then Complete asks for the line to be saved again.
            $v3 = $this->propose($director, $f, 5);
            $checker = $this->actor('ca_supervisor');
            $this->selectBranch($f['ca'], $f['visit']->branch);
            DB::beginTransaction();
            app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $this->packs($f, $case, $item, 1, $v2, 2));
            $worker = $this->checkRace($database, $config, $checker, $v3, 5);
            $this->assertStringContainsString('CHECKED', $worker->getOutput());
            $this->assertSame(1, $item->refresh()->packs_dispensed);
            $this->selectBranch($f['ca'], $f['visit']->branch);
            try {
                $this->complete($f, $case);
                $this->fail('Complete accepted a line saved against an older bottle size.');
            } catch (ValidationException $e) {
                $this->assertSame(['The bottle size for this medicine changed. Save the line again.'], $e->errors()['allocations']);
            }
            $this->assertSame(DispensaryCase::STATUS_DISPENSING, $case->refresh()->status);
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
            DB::purge('pack_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** @param array<string, mixed> $f */
    private function propose(User $director, array $f, int $perPack): string
    {
        $this->selectBranch($director, $f['visit']->branch);
        $latest = (int) DB::table('inventory_pack_conversions')->where('inventory_sku_id', $f['sku']->id)->max('version');

        return app(PackConversionService::class)->propose($director, $f['sku']->public_id, ['expected_version' => $latest, 'idempotency_key' => (string) Str::uuid(), 'pack_unit' => 'bottle', 'base_per_pack' => $perPack]);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    private function packs(array $f, DispensaryCase $case, DispensaryItem $item, int $packs, string $conversion, int $version): array
    {
        return [...$this->addPayload($f, $case, '10.000'), 'item_lock_version' => $item->refresh()->lock_version, 'packs' => $packs, 'pack_conversion_public_id' => $conversion, 'pack_conversion_version' => $version];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function race(string $database, array $config, User $actor, DispensaryCase $case, DispensaryItem $item, array $payload): Process
    {
        $data = ['branch_id' => $case->branch_id, 'actor_id' => $actor->id, 'case_id' => $case->id, 'item_id' => $item->id, 'payload' => $payload];

        return $this->runWhileHolding([PHP_BINARY, base_path('tests/Support/PostgresDispensaryPackWorker.php'), $database, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], $config, $database);
    }

    /** @param array<string, mixed> $config */
    private function checkRace(string $database, array $config, User $checker, string $conversion, int $count): Process
    {
        return $this->runWhileHolding([PHP_BINARY, base_path('tests/Support/PostgresPackConversionWorker.php'), $database, 'check', (string) $checker->id, $conversion, (string) $count], $config, $database);
    }

    /**
     * Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits.
     *
     * @param  list<string>  $command
     * @param  array<string, mixed>  $config
     */
    private function runWhileHolding(array $command, array $config, string $database): Process
    {
        $input = new InputStream;
        $worker = new Process($command, base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
        $worker->setInput($input)->setTimeout(ContentionTimeouts::processTimeoutSeconds())->start();
        $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
        while (! str_contains($worker->getOutput(), 'READY ') && $worker->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertSame(1, preg_match('/READY (\d+)/', $worker->getOutput(), $pid), $worker->getErrorOutput());
        $input->write("GO\n");
        $waiting = false;
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [(int) ($pid[1] ?? 0)])?->wait_event_type === 'Lock';
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
