<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Services\DispensaryReturnService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Services\PatientAdministrationService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitRegistrationService;
use App\Models\User;
use Database\Seeders\KPOneReferenceSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReturnsLocationFixture;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * DSP-7 and DSP-16 contention (PostgreSQL, disposable database): every return writer locks the case row first, so two checkers
 * of one return, and a refusal against a new record that needs the freed quantity, are serialised. Synthetic data.
 */
final class PostgresDispensaryReturnTest extends TestCase
{
    use OtcDispensaryFixtures;

    protected Branch $branch;

    public function test_concurrent_checks_and_a_refusal_against_a_new_record_are_serialised(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_dsp7_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.returns_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'returns_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('returns_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame('UTF8', $identity->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'returns_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $this->branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();

            $f = $this->fixture();
            $case = $this->open($f);
            app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '3.000'));
            $this->confirmAllergy($f, $case->refresh());
            $case = $this->complete($f, $case)->refresh();
            $item = $case->handoffs()->sole()->items()->sole()->public_id;
            $service = app(DispensaryReturnService::class);
            $recordPayload = fn (int $count, string $quantity): array => ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => $count, 'quantity' => $quantity, 'reason' => 'not_used', 'note' => null, 'condition' => 'sealed_in_date'];
            $checkPayload = fn (string $outcome, ?string $reason = null): array => ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'outcome' => $outcome, 'refuse_reason' => $reason];
            $first = $service->record($f['ca'], $case, $item, $recordPayload(0, '1'));
            $supervisor = $this->actor('ca_supervisor');
            $other = $this->actor('ca_supervisor');

            // Race 1: two CA Supervisors check the same return; the second waits on the lock and is refused.
            app(BranchAccessService::class)->select($supervisor, $this->branch);
            DB::beginTransaction();
            $service->check($supervisor, $case, $first->public_id, $checkPayload('dispose'));
            $worker = $this->race($database, $config, 'check', ['branch_id' => $this->branch->id, 'actor_id' => $other->id, 'case_id' => $case->id, 'return_public_id' => $first->public_id, 'payload' => $checkPayload('refuse', 'recorded_twice')]);
            $this->assertStringContainsString('REFUSED state', $worker->getOutput());
            $this->assertSame(['dispose'], DB::table('dispensary_return_checks')->pluck('outcome')->all());

            // Race 2: a second return of the remaining 2 is refused while a CA records 3 more (count 2). The record
            // waits on the case lock, then sees the refusal: only 1 still counts, so 2 can be recorded.
            $second = $service->record($f['ca'], $case, $item, $recordPayload(1, '2'));
            app(BranchAccessService::class)->select($supervisor, $this->branch);
            DB::beginTransaction();
            $service->check($supervisor, $case, $second->public_id, $checkPayload('refuse', 'wrong_quantity'));
            $worker = $this->race($database, $config, 'record', ['branch_id' => $this->branch->id, 'actor_id' => $f['ca']->id, 'case_id' => $case->id, 'item_public_id' => $item, 'payload' => $recordPayload(2, '2')]);
            $this->assertStringContainsString('SUCCEEDED', $worker->getOutput());
            $this->assertSame(['1.000', '2.000', '2.000'], DB::table('dispensary_returns')->orderBy('id')->pluck('quantity')->map(fn ($q) => (string) $q)->all());
            $this->assertSame(['dispose', 'refuse'], DB::table('dispensary_return_checks')->orderBy('id')->pluck('outcome')->all());
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
            DB::purge('returns_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    /**
     * DSP-16: two CA Supervisors split two returns of one line across the same batches at the same moment. The loser
     * waits on the case lock, then works out what each batch can still take back from the committed winner: a share
     * over that is refused under its own box, and a split that fits succeeds. No batch ever gets back more than the
     * line dispensed from it.
     */
    public function test_concurrent_split_quarantines_never_overfill_a_batch(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_dsp7_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.returns_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'returns_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('returns_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'returns_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $this->branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();

            // A line of 6 dispensed as 3 from an earlier batch and 3 from a later one.
            $f = $this->fixture();
            $supervisor = $this->actor('ca_supervisor');
            $other = $this->actor('ca_supervisor');
            $this->selectBranch($supervisor);
            $later = new InventoryBatch;
            $later->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['batch']->organisation_id, 'inventory_sku_id' => $f['sku']->id, 'batch_number' => 'SYN-LATER-'.Str::upper(Str::random(4)), 'expiry_date' => now()->setTimezone($this->branch->timezone)->addYears(3)->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
            app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $this->branch->id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $later->public_id, 'quantity' => '10.000']);
            $location = ReturnsLocationFixture::create($supervisor, $this->branch);
            $this->selectBranch($f['ca']);
            $case = $this->open($f);
            app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '6.000', ['allocations' => [
                ['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '3.000'],
                ['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $later->public_id, 'quantity' => '3.000', 'fefo_exception_reason' => 'earlier_batch_insufficient'],
            ]]));
            $this->confirmAllergy($f, $case->refresh());
            $case = $this->complete($f, $case)->refresh();
            $item = $case->handoffs()->sole()->items()->sole()->public_id;
            $service = app(DispensaryReturnService::class);
            $record = fn (int $count, string $quantity) => $service->record($f['ca'], $case, $item, ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'expected_return_count' => $count, 'quantity' => $quantity, 'reason' => 'not_used', 'note' => null, 'condition' => 'sealed_in_date']);
            $split = fn (string $early, string $late): array => ['expected_branch_id' => $this->branch->id, 'retry_key' => (string) Str::uuid(), 'outcome' => 'quarantine', 'refuse_reason' => null, 'refuse_note' => null, 'location_public_id' => $location->public_id, 'batch_public_id' => null, 'batches' => [['batch_public_id' => $f['batch']->public_id, 'quantity' => $early], ['batch_public_id' => $later->public_id, 'quantity' => $late]]];
            $first = $record(0, '3');
            $second = $record(1, '2');
            $third = $record(2, '1');

            // Race 1: the winner puts 2 back into the earlier batch; the loser asks for 1.5 of the 1 left and is refused.
            $this->selectBranch($supervisor);
            DB::beginTransaction();
            $service->check($supervisor, $case, $first->public_id, $split('2', '1'));
            $worker = $this->race($database, $config, 'check', ['branch_id' => $this->branch->id, 'actor_id' => $other->id, 'case_id' => $case->id, 'return_public_id' => $second->public_id, 'payload' => $split('1.5', '0.5')]);
            $this->assertStringContainsString('REFUSED batches.0.quantity', $worker->getOutput());
            $this->assertSame(1, DB::table('dispensary_return_checks')->count());

            // Race 2: the winner splits 0.5 + 0.5; the loser's 0.5 + 1.5 fits exactly what is left, and succeeds.
            $this->selectBranch($supervisor);
            DB::beginTransaction();
            $service->check($supervisor, $case, $third->public_id, $split('0.5', '0.5'));
            $worker = $this->race($database, $config, 'check', ['branch_id' => $this->branch->id, 'actor_id' => $other->id, 'case_id' => $case->id, 'return_public_id' => $second->public_id, 'payload' => $split('0.5', '1.5')]);
            $this->assertStringContainsString('SUCCEEDED', $worker->getOutput());

            $perBatch = DB::table('dispensary_return_check_batches')->groupBy('inventory_batch_id')->orderBy('inventory_batch_id')->selectRaw('inventory_batch_id, SUM(quantity) AS total')->pluck('total', 'inventory_batch_id')->map(fn ($q): string => (string) $q)->all();
            $this->assertSame([$f['batch']->id => '3.000', $later->id => '3.000'], $perBatch);
            $this->assertSame(3, DB::table('dispensary_return_checks')->where('outcome', 'quarantine')->whereNull('inventory_batch_id')->count());
            $this->assertSame('6.000', (string) DB::table('inventory_stock_balances')->where('inventory_location_id', $location->id)->sum('quantity'));
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
            DB::purge('returns_race');
            DB::statement('DROP DATABASE "'.$database.'" WITH (FORCE)');
        }
    }

    protected function actor(string $role = 'ca', ?Branch $branch = null): User
    {
        $branch ??= $this->branch;
        $user = User::factory()->create(['organisation_id' => $branch->organisation_id, 'email' => fake()->unique()->userName().'@kpone.test']);
        $user->assignRole($role);
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => Department::query()->where('organisation_id', $branch->organisation_id)->value('id')])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['is_primary' => true, 'assignment_type' => 'permanent', 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);

        return $user;
    }

    protected function selectBranch(User $actor, ?Branch $branch = null): void
    {
        $this->actingAs($actor);
        session([BranchAccessService::SESSION_KEY => ($branch ?? $this->branch)->id]);
    }

    protected function patient(): Patient
    {
        return app(PatientAdministrationService::class)->create($this->actor('director'), [
            'full_name' => 'Synthetic Return Patient '.Str::random(8), 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'mobile_phone' => '+60123456789',
            'identifiers' => [['identifier_type' => 'passport', 'issuing_country_code' => 'MY', 'value' => 'SYN-'.Str::upper(Str::random(12))]], 'duplicate_override' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function register(User $actor, Patient $patient, array $overrides = []): Visit
    {
        $this->selectBranch($actor);

        return app(VisitRegistrationService::class)->register($actor, [
            'idempotency_key' => (string) Str::uuid(), 'expected_branch_id' => $this->branch->id, 'patient_number' => $patient->patient_number,
            'visit_type' => 'otc', 'assigned_doctor_user_id' => null, 'visit_reason' => null, 'priority' => 'normal', 'coverage_type' => 'self_pay',
            'panel_id' => null, 'coverage_member_reference' => null, 'confirm_repeat' => false, ...$overrides,
        ]);
    }

    /**
     * Starts the loser while this connection holds the winning transaction, proves it waits on a lock, then commits.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    private function race(string $database, array $config, string $mode, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresDispensaryReturnWorker.php'), $database, $mode, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
