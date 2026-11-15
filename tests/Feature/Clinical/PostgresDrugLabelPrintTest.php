<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\BranchAccessService;
use App\Domain\Clinical\Dispensary\Labels\DrugLabelService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Identity\Models\StaffProfile;
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
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * DSP-6b contention (PostgreSQL, disposable database): a label print takes its row locks (branch, patient, visit, case) in one
 * order, so two people printing the same case at the same moment are serialised and take print numbers 1 and 2. Synthetic data.
 */
final class PostgresDrugLabelPrintTest extends TestCase
{
    use OtcDispensaryFixtures;

    protected Branch $branch;

    public function test_two_prints_of_the_same_case_at_the_same_moment_take_print_numbers_one_and_two(): void
    {
        $original = config('database.default');
        $config = DB::connection()->getConfig();
        $this->assertSame('pgsql', $config['driver']);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(app()->environment('testing'));
        $this->assertMatchesRegularExpression('/test/i', $config['database']);
        $database = 'kpone_dsp6_race_'.strtolower(Str::random(10)).'_testing';
        DB::statement('CREATE DATABASE "'.$database.'" ENCODING \'UTF8\' TEMPLATE template0');
        config(['database.connections.labels_race' => [...$config, 'database' => $database, 'url' => null, 'name' => 'labels_race']]);
        $worker = null;
        try {
            DB::setDefaultConnection('labels_race');
            Schema::clearResolvedInstance('db.schema');
            app()->forgetInstance('db.schema');
            $identity = DB::selectOne("SELECT current_database() as database, current_setting('server_encoding') as encoding");
            $this->assertSame($database, $identity->database);
            $this->assertSame('UTF8', $identity->encoding);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'labels_race', '--force' => true]));
            $this->seed(KPOneReferenceSeeder::class);
            $this->branch = Branch::query()->where('code', 'CHERAS')->whereHas('organisation', fn ($q) => $q->where('code', 'KLINIK_PUTRIJAYA'))->sole();

            $f = $this->fixture();
            $case = $this->open($f);
            app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '1.000'));
            $this->confirmAllergy($f, $case->refresh());
            $case = $this->complete($f, $case)->refresh();
            $other = $this->actor('ca');
            $service = app(DrugLabelService::class);
            app(BranchAccessService::class)->select($f['ca'], $this->branch);
            $labels = $service->preview($f['ca'], $case);
            $this->assertCount(1, $labels['items']);

            // The first CA's print holds its row locks; a second CA presses Print at the same moment and waits on them.
            DB::beginTransaction();
            $this->assertSame([], $service->recordPrint($f['ca'], $case, null, $labels['token'], $this->branch->id));
            $worker = $this->race($database, $config, ['branch_id' => $this->branch->id, 'actor_id' => $other->id, 'case_id' => $case->id, 'token' => $labels['token']]);
            $this->assertStringContainsString('PRINTED '.json_encode([$labels['items'][0]['previewUrl']], JSON_UNESCAPED_SLASHES), $worker->getOutput());
            $rows = DB::table('dispensary_label_prints')->orderBy('id')->get();
            $this->assertSame([1, 2], $rows->pluck('print_number')->map(fn ($n) => (int) $n)->all());
            $this->assertSame([$f['ca']->id, $other->id], $rows->pluck('printed_by_user_id')->map(fn ($n) => (int) $n)->all());
            $this->assertSame(2, DB::table('audit_logs')->where('event', 'dispensary.labels_printed')->count());
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
            DB::purge('labels_race');
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
            'full_name' => 'Synthetic Label Patient '.Str::random(8), 'date_of_birth' => '1990-01-01', 'sex' => 'unknown', 'mobile_phone' => '+60123456789',
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
    private function race(string $database, array $config, array $data): Process
    {
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Support/PostgresDrugLabelPrintWorker.php'), $database, base64_encode(json_encode($data, JSON_THROW_ON_ERROR))], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'DB_URL' => '', 'DB_SSLMODE' => $config['sslmode'] ?? 'prefer']);
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
