<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\DoctorCheckFixtures;
use Tests\TestCase;

/**
 * DSP-23 contention (PostgreSQL, disposable database). Verify and Send back take the doctor's user lock first and then
 * the case lock, so two presses on one case are serialised and the second sees a stale page; and Verify takes the
 * stock balance lock inside debitForDispense, so two cases on one batch can never take the balance below zero.
 * Synthetic data only.
 */
final class PostgresDispensaryDoctorCheckTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /**
     * The race needs committed rows in its own disposable database, so this test skips VisitTestCase's wrapping
     * transaction and reference-data seeding of the shared test database; it seeds the race database itself.
     */
    protected function setUp(): void
    {
        TestCase::setUp();
    }

    public function refreshDatabase(): void {}

    public function test_two_presses_on_one_case_and_two_cases_on_one_batch_are_serialised(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_dsp23_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.doctor_check_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'doctor_check_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('doctor_check_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $this->assertSame($database, DB::selectOne('SELECT current_database() AS database')->database);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'doctor_check_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $this->organisation = Organisation::query()->where('code', 'KLINIK_PUTRIJAYA')->sole();
            $this->branch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'CHERAS')->sole();
            $this->switchOn($this->branch);
            $service = app(DispensaryService::class);

            // Race 1: the doctor presses Verify twice. The second press waits on the doctor's lock, then sees a stale page.
            $f = $this->prepared();
            $this->sendIt($f);
            $this->selectBranch($f['doctor'], $this->branch);
            DB::beginTransaction();
            $service->verifyDoctorCheck($f['doctor'], $f['case']->refresh(), $this->payload($f));
            $worker = $this->race($database, $config, 'verify', $f['doctor'], $f['case']->id, $this->payload($f, ['case_lock_version' => $f['case']->lock_version]));
            $this->assertStringContainsString('REFUSED case_lock_version', $worker->getOutput());
            $this->assertSame(1, DB::table('dispensary_doctor_checks')->where('dispensary_case_id', $f['case']->id)->count());
            $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'dispense')->count());

            // Race 2: Send back against Verify. The verifier waits, then sees the case is no longer waiting.
            $g = $this->prepared();
            $this->sendIt($g);
            $this->selectBranch($g['doctor'], $this->branch);
            $version = $g['case']->refresh()->lock_version;
            DB::beginTransaction();
            $service->sendBackDoctorCheck($g['doctor'], $g['case']->refresh(), $this->payload($g, ['reason' => 'wrong_batch']));
            $worker = $this->race($database, $config, 'verify', $g['doctor'], $g['case']->id, $this->payload($g, ['case_lock_version' => $version]));
            $this->assertStringContainsString('REFUSED case_lock_version', $worker->getOutput());
            $this->assertSame('dispensing', DB::table('dispensary_cases')->where('id', $g['case']->id)->value('status'));
            $this->assertSame(['sent_back'], DB::table('dispensary_doctor_checks')->where('dispensary_case_id', $g['case']->id)->pluck('outcome')->all());

            // Race 3: two cases prepared from one batch of 3 units, 2 each, verified at the same moment by their two
            // doctors. The second waits on the stock lock, then is refused; the balance never goes below zero.
            $a = $this->prepared(['opening' => '3.000']);
            $b = $this->prepared(['medicine' => $a['medicine'], 'sku' => $a['sku'], 'location' => $a['location'], 'batch' => $a['batch']]);
            $this->sendIt($a);
            $this->sendIt($b);
            $this->selectBranch($a['doctor'], $this->branch);
            DB::beginTransaction();
            $service->verifyDoctorCheck($a['doctor'], $a['case']->refresh(), $this->payload($a));
            $worker = $this->race($database, $config, 'verify', $b['doctor'], $b['case']->id, $this->payload($b));
            $this->assertStringContainsString('REFUSED allocations', $worker->getOutput());
            $this->assertSame('1.000', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $a['location']->id)->where('inventory_batch_id', $a['batch']->id)->value('quantity'));
            $this->assertSame('awaiting_doctor_check', DB::table('dispensary_cases')->where('id', $b['case']->id)->value('status'));
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
            DB::purge('doctor_check_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /** @param array<string, mixed> $f */
    private function sendIt(array $f): void
    {
        $this->selectBranch($f['ca'], $this->branch);
        app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), $this->payload($f));
    }

    /** @param array<string, mixed> $f @param array<string, mixed> $extra @return array<string, mixed> */
    private function payload(array $f, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'retry_key' => (string) Str::uuid(), ...$extra];
    }

    /**
     * Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function race(string $database, array $config, string $mode, User $actor, int $caseId, array $payload): Process
    {
        $input = new InputStream;
        $data = ['branch_id' => $this->branch->id, 'actor_id' => $actor->id, 'case_id' => $caseId, 'payload' => $payload];
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresDispensaryDoctorCheckWorker.php'), $database, $mode, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
