<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\SalesTarget;
use App\Domain\Visit\Billing\Models\SalesTargetRevision;
use App\Domain\Visit\Billing\Services\SalesTargetService;
use App\Models\User;
use Carbon\CarbonImmutable;
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

/**
 * BIL-30 (design note K6) under real PostgreSQL contention: two sessions set the same empty sales target at the same
 * moment. The service serialises them with an advisory lock per target; this test holds that lock until both workers
 * are proven to be waiting on it, then releases it. Never a unique-key error.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresSalesTargetRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_sales_target_observer';

    /** @var list<int> */
    private array $organisationIds = [];

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL sales target regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Sales target concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('sales_targets') || ! Schema::hasTable('sales_target_revisions')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before sales target regressions.');
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
        foreach ($this->organisationIds as $id) {
            $this->deleteOrganisation($id);
        }
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_two_simultaneous_first_sets_of_one_target_leave_one_row_and_one_refusal(): void
    {
        $f = $this->fixture();
        $out = $this->race($f, [
            $this->worker($f, 'director', ['amount' => '100.00']),
            $this->worker($f, 'finance', ['amount' => '200.00']),
        ]);

        // Both opened "Set target" on an empty target (version 0); the second to run finds version 1 and is refused.
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE amount: This target changed while you were editing. Reload and try again.'), $out);
        $this->assertStringNotContainsString('23505', $out);
        $targets = SalesTarget::query()->where('organisation_id', $f['organisation']->id)->get();
        $this->assertCount(1, $targets);
        $this->assertSame(1, $targets->sole()->lock_version);
        $this->assertContains($targets->sole()->amount_sen, [10000, 20000]);
        $this->assertSame(1, SalesTargetRevision::query()->where('organisation_id', $f['organisation']->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $f['organisation']->id)->where('event', 'sales_target.set')->count());
    }

    /**
     * An own organisation and branch, and a Director and a Finance Officer (explicit grants) at that branch.
     *
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'TARGET_PG_'.Str::upper(Str::random(8)), 'name' => 'Synthetic sales target PG', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'T'.Str::upper(Str::random(5)), 'name' => 'Synthetic Target Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $manage = [SalesTargetService::MANAGE, 'branch_context.switch.branch'];

        return ['organisation' => $organisation, 'branch' => $branch, 'director' => $this->user($organisation, $branch, $manage), 'finance' => $this->user($organisation, $branch, $manage),
            // A month ahead of the real clock: PostgreSQL judges "past month" by its own clock.
            'month' => CarbonImmutable::now($branch->timezone)->startOfMonth()->addMonthsNoOverflow(2)->format('Y-m')];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Sales Target User', 'email' => 'target.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['assignment_type' => 'temporary', 'is_primary' => false, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString()]);

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $args
     * @return Worker
     */
    private function worker(array $f, string $actor, array $args): array
    {
        $args = ['branch_id' => $f['branch']->id, 'month' => $f['month'], 'doctor_user_id' => null, 'lock_version' => 0, 'reason' => null, 'idempotency_key' => (string) Str::uuid(), ...$args];
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresSalesTargetWorker.php'), 'sales-target', (string) $f[$actor]->id, (string) $f['branch']->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-target-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the service's own advisory lock for this target, starts both workers, proves each one is
     * waiting behind this session, then releases the lock so they run strictly one after the other.
     *
     * @param  array<string, mixed>  $f
     * @param  list<Worker>  $workers
     */
    private function race(array $f, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['sales-target:'.$f['organisation']->id.':'.$f['branch']->id.':'.$f['month'].':0']);
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            foreach ($workers as $worker) {
                $worker['process']->start();
            }
            $this->waitReady($workers);
            foreach ($workers as $worker) {
                $worker['input']->write("GO\n");
                $worker['input']->close();
            }
            foreach ($workers as $worker) {
                $this->waitForDatabaseBlock($worker['process'], $parentPid);
            }
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
        foreach ($workers as $worker) {
            $worker['process']->wait();
            $this->assertSame(0, $worker['process']->getExitCode(), $this->processDiagnostics($worker['process']));
        }

        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    /** @param list<Worker> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated() && ! str_contains($worker['process']->getOutput(), 'READY ')) {
                    throw new RuntimeException('Sales target worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Sales target worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required sales target contention was not reached. '.$this->processDiagnostics($process));
            }
            $result = $this->observer()->selectOne(<<<'SQL'
                select exists (
                    with recursive blockers(pid) as (
                        select unnest(pg_blocking_pids(activity.pid))
                        union
                        select unnest(pg_blocking_pids(blockers.pid)) from blockers
                    ) select 1 from blockers where pid = ?
                ) as expected_blocker
                from pg_stat_activity as activity where pid = ?
                SQL, [$expectedBlockerPid, $pid]);
            if ($result && filter_var($result->expected_blocker, FILTER_VALIDATE_BOOL)) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Expected sales target blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }

    private function deleteOrganisation(int $id): void
    {
        DB::transaction(function () use ($id): void {
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic evidence.
            foreach (['sales_target_revisions', 'sales_targets'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            foreach (['audit_logs'] as $table) {
                DB::table($table)->where('organisation_id', $id)->delete();
            }
            $branchIds = DB::table('branches')->where('organisation_id', $id)->pluck('id');
            DB::table('staff_branch_assignments')->whereIn('branch_id', $branchIds)->delete();
            $userIds = DB::table('users')->where('organisation_id', $id)->pluck('id');
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('staff_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->where('organisation_id', $id)->delete();
            DB::table('branches')->where('organisation_id', $id)->delete();
            DB::table('organisations')->where('id', $id)->delete();
        });
    }
}
