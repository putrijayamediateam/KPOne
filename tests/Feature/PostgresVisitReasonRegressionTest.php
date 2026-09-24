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

class PostgresVisitReasonRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_visit_reason_observer';

    private ?int $organisationId = null;

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Visit Reason regression requires DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Visit Reason concurrency requires an isolated PostgreSQL test database.');
        }
        foreach (['visit_reason_catalogue_items', 'visit_reason_assignments'] as $table) {
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
            DB::table('visit_reason_catalogue_items')->where('organisation_id', $this->organisationId)->delete();
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

    public function test_equivalent_reason_creation_serializes_to_one_catalogue_row_with_blocker_proof(): void
    {
        [$organisation, $branch, $actors] = $this->fixture();
        $first = $this->worker($actors[0], $branch, 'Pregnancy Scan');
        $second = $this->worker($actors[1], $branch, ' pregnancy   scan ');

        $first['process']->start();
        $this->waitFor($first['process'], 'READY ');
        $first['input']->write("GO\n");
        $this->waitFor($first['process'], 'CREATED ');

        $second['process']->start();
        $this->waitFor($second['process'], 'READY ');
        $second['input']->write("GO\n");
        $this->waitForBlock($second['process'], $this->pid($first['process']));

        $first['input']->write("COMMIT\n");
        $first['input']->close();
        $first['process']->wait();
        $this->waitFor($second['process'], 'CREATED ');
        $second['input']->write("COMMIT\n");
        $second['input']->close();
        $second['process']->wait();

        $this->assertSame(0, $first['process']->getExitCode(), $first['process']->getErrorOutput());
        $this->assertSame(0, $second['process']->getExitCode(), $second['process']->getErrorOutput());
        $this->assertSame(1, DB::table('visit_reason_catalogue_items')->where('organisation_id', $organisation->id)->count());
        $this->assertSame('pregnancy scan', DB::table('visit_reason_catalogue_items')->where('organisation_id', $organisation->id)->value('normalized_name'));
        preg_match('/CREATED ([0-9a-f-]+)/i', $first['process']->getOutput(), $one);
        preg_match('/CREATED ([0-9a-f-]+)/i', $second['process']->getOutput(), $two);
        $this->assertSame($one[1] ?? null, $two[1] ?? null);
    }

    public function test_permission_loss_committed_before_authority_lock_rejects_creation(): void
    {
        [$organisation, $branch, $actors] = $this->fixture();
        $permission = Permission::findByName('visits.create.branch', 'web');

        $this->assertAuthorityLossRejected($actors[0], $branch, 'Permission loss reason', function () use ($actors, $permission): void {
            DB::table('model_has_permissions')
                ->where('model_type', $actors[0]->getMorphClass())
                ->where('model_id', $actors[0]->id)
                ->where('permission_id', $permission->id)
                ->delete();
        });
        $this->assertSame(0, DB::table('visit_reason_catalogue_items')->where('organisation_id', $organisation->id)->count());
    }

    public function test_branch_assignment_loss_committed_before_authority_lock_rejects_creation(): void
    {
        [$organisation, $branch, $actors] = $this->fixture();

        $this->assertAuthorityLossRejected($actors[0], $branch, 'Assignment loss reason', function () use ($actors): void {
            $profileId = DB::table('staff_profiles')->where('user_id', $actors[0]->id)->value('id');
            DB::table('staff_branch_assignments')->where('staff_profile_id', $profileId)->delete();
        });
        $this->assertSame(0, DB::table('visit_reason_catalogue_items')->where('organisation_id', $organisation->id)->count());
    }

    /** @return array{Organisation,Branch,list<User>} */
    private function fixture(): array
    {
        $suffix = Str::upper(Str::random(8));
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'R1B_'.$suffix, 'name' => 'Synthetic R1-B PG', 'is_active' => true])->save();
        $this->organisationId = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'ONE', 'name' => 'Synthetic One', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $permission = Permission::findOrCreate('visits.create.branch', 'web');
        $actors = collect([1, 2])->map(function (int $number) use ($organisation, $branch, $permission, $suffix): User {
            $user = new User;
            $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Actor '.$number, 'email' => "r1b.{$number}.{$suffix}@kpone.test", 'is_active' => true])->save();
            $user->givePermissionTo($permission);
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

    /** @return array{process:Process,input:InputStream} */
    private function worker(User $actor, Branch $branch, string $name): array
    {
        $input = new InputStream;
        $application = 'kpone-r1b-pg-'.Str::lower(Str::random(8));
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresVisitReasonWorker.php'), (string) $actor->id, (string) $branch->id, $name, $application], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    private function assertAuthorityLossRejected(User $actor, Branch $branch, string $name, callable $mutation): void
    {
        $worker = $this->worker($actor, $branch, $name);
        DB::beginTransaction();
        try {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $mutation();
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            $worker['process']->start();
            $this->waitFor($worker['process'], 'READY ');
            $worker['input']->write("GO\n");
            $this->waitForBlock($worker['process'], $parentPid);
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }

        $this->waitFor($worker['process'], 'DENIED');
        $worker['input']->close();
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
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
                throw new RuntimeException('Visit Reason worker exited early: '.$this->processDiagnostics($process));
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException(sprintf('Visit Reason worker protocol timeout for %s within %ds (elapsed %.2fs). %s', $needle, $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
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
        throw new RuntimeException(sprintf('Required Visit Reason unique-key contention was not observed within %ds (elapsed %.2fs). %s', $timeoutSeconds, microtime(true) - $started, $this->processDiagnostics($process)));
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
