<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

class PostgresBusinessDetailsRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_business_details_observer';

    private ?int $organisationId = null;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Business Details regression requires DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Business Details concurrency requires an isolated PostgreSQL test database.');
        }
        foreach (['organisation_business_profiles', 'branch_business_profiles', 'business_detail_commands'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated database first.');
            }
        }
        Config::set('database.connections.'.self::OBSERVER, config('database.connections.'.config('database.default')));
        DB::purge(self::OBSERVER);
    }

    protected function tearDown(): void
    {
        foreach ($this->inputs as $input) {
            if (! $input->isClosed()) {
                $input->close();
            }
        }
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        if ($this->organisationId !== null) {
            DB::table('audit_logs')->where('organisation_id', $this->organisationId)->delete();
            foreach (['business_detail_commands', 'branch_business_profiles', 'organisation_business_profiles', 'business_detail_assets'] as $table) {
                DB::table($table)->where('organisation_id', $this->organisationId)->delete();
            }
            $userIds = DB::table('users')->where('organisation_id', $this->organisationId)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('staff_branch_assignments')->whereIn('staff_profile_id', DB::table('staff_profiles')->whereIn('user_id', $userIds)->pluck('id'))->delete();
            DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::table('branches')->where('organisation_id', $this->organisationId)->delete();
            DB::table('organisations')->where('id', $this->organisationId)->delete();
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_simultaneous_first_saves_serialize_and_the_loser_is_stale_without_partial_records(): void
    {
        $this->race(false);
    }

    public function test_simultaneous_identical_requests_commit_one_command_and_one_audit(): void
    {
        $this->race(true);
    }

    private function race(bool $same): void
    {
        [$organisation, $branch, $actors] = $this->fixture();
        $payload = ['expected_branch_id' => $branch->id, 'organisation_lock_version' => 0, 'branch_lock_version' => 0,
            'idempotency_key' => (string) Str::uuid(), 'legal_name' => 'Synthetic PG Legal', 'clinic_name' => 'Synthetic PG Clinic',
            'address_line_1' => '1 Synthetic Lane', 'postcode' => '50000', 'city' => 'Synthetic City', 'state' => 'Synthetic State', 'country' => 'Malaysia'];
        $first = $this->worker($actors[0], $branch, $payload);
        $second = $this->worker($same ? $actors[0] : $actors[1], $branch, $same ? $payload : [...$payload, 'idempotency_key' => (string) Str::uuid(), 'clinic_name' => 'Synthetic Losing Clinic']);
        $first['process']->start();
        $this->waitFor($first['process'], 'READY ');
        $first['input']->write("GO\n");
        $this->waitFor($first['process'], 'SAVED');
        $second['process']->start();
        $this->waitFor($second['process'], 'READY ');
        $second['input']->write("GO\n");
        $this->waitForBlock($second['process'], $this->pid($first['process']));
        $first['input']->write("COMMIT\n");
        $first['input']->close();
        $first['process']->wait();
        $this->waitFor($second['process'], $same ? 'SAVED' : 'INVALID');
        if ($same) {
            $second['input']->write("COMMIT\n");
        }
        $second['input']->close();
        $second['process']->wait();
        foreach ([$first, $second] as $worker) {
            $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        }
        foreach (['organisation_business_profiles', 'branch_business_profiles', 'business_detail_commands'] as $table) {
            $this->assertSame(1, DB::table($table)->where('organisation_id', $organisation->id)->count());
        }
        $this->assertSame('Synthetic PG Clinic', DB::table('branch_business_profiles')->where('organisation_id', $organisation->id)->value('clinic_name'));
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $organisation->id)->where('event', 'business_details.saved')->count());
    }

    /** @return array{Organisation,Branch,list<User>} */
    private function fixture(): array
    {
        $suffix = Str::upper(Str::random(8));
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'BIZ_'.$suffix, 'name' => 'Synthetic Business Details PG', 'is_active' => true])->save();
        $this->organisationId = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'ONE', 'name' => 'Synthetic One', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $permission = Permission::findOrCreate('catalogue.settings.manage.organisation', 'web');
        $actors = collect([1, 2])->map(function (int $number) use ($organisation, $branch, $permission, $suffix): User {
            $user = new User;
            $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Actor '.$number, 'email' => "biz01.{$number}.{$suffix}@kpone.test", 'is_active' => true])->save();
            $user->givePermissionTo($permission);
            $user->givePermissionTo(Permission::findOrCreate('business_details.manage.organisation', 'web'));
            $user->givePermissionTo(Permission::findOrCreate('branch_context.switch.branch', 'web'));
            $profile = new StaffProfile;
            $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
            StaffBranchAssignmentBootstrapper::create($profile, $branch, [
                'assignment_type' => 'temporary',
                'is_primary' => true,
                'valid_from' => now()->subDay()->toDateString(),
                'valid_until' => now()->addDay()->toDateString(),
            ]);

            return $user;
        })->all();

        return [$organisation, $branch, $actors];
    }

    /** @param array<string, mixed> $payload
     * @return array{process:Process,input:InputStream}
     */
    private function worker(User $actor, Branch $branch, array $payload): array
    {
        $input = new InputStream;
        $application = 'kpone-biz01-pg-'.Str::lower(Str::random(8));
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresBusinessDetailsWorker.php'), (string) $actor->id, (string) $branch->id, json_encode($payload, JSON_THROW_ON_ERROR), $application], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    private function waitFor(Process $process, string $needle): void
    {
        $timeoutSeconds = ContentionTimeouts::protocolTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            if (str_contains($process->getOutput(), $needle)) {
                return;
            }
            if ($process->isTerminated()) {
                throw new RuntimeException('Business Details worker exited early: '.$this->processDiagnostics($process));
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Business Details worker protocol timeout for %s within %ds (elapsed %.2fs). %s', $needle, $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
    }

    private function pid(Process $process): int
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);

        return (int) ($matches[1] ?? 0);
    }

    private function waitForBlock(Process $process, int $expectedBlockerPid): void
    {
        $pid = $this->pid($process);
        if ($pid <= 0 || $expectedBlockerPid <= 0) {
            throw new RuntimeException('Workers did not report valid backend PIDs. '.$this->processDiagnostics($process));
        }
        $timeoutSeconds = ContentionTimeouts::protocolTimeoutSeconds();
        $started = microtime(true);
        $deadline = $started + $timeoutSeconds;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Worker exited before required contention. '.$this->processDiagnostics($process));
            }
            $blocked = $this->observer()->scalar(<<<'SQL'
                with recursive blockers(pid) as (
                    select unnest(pg_blocking_pids(?::integer))
                    union
                    select unnest(pg_blocking_pids(blockers.pid)) from blockers
                )
                select exists(select 1 from blockers where pid = ?::integer)
                SQL, [$pid, $expectedBlockerPid]);
            if (filter_var($blocked, FILTER_VALIDATE_BOOL)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Required Business Details row-lock contention was not observed within %ds (elapsed %.2fs). %s', $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim($process->getOutput()).' stderr='.trim($process->getErrorOutput());
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }
}
