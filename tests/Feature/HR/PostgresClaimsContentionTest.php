<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Claims\Services\ClaimService;
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

/**
 * HR-6 under real PostgreSQL contention (HR-PLAN 13.7). Two people sending a claim at once get consecutive SC- numbers
 * with no gap or repeat, because the organisation's counter row is the lock; two payers recording one claim at once
 * record one payment, and the other is refused with a plain reason.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresClaimsContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_claims_observer';

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
            $this->markTestSkipped('PostgreSQL staff claim regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Staff claim concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_workers')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before staff claim regressions.');
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

    public function test_two_claims_sent_at_once_get_consecutive_numbers(): void
    {
        $f = $this->fixture();
        DB::table('hr_claim_counters')->insert(['organisation_id' => $f['organisation']->id, 'last_number' => 0]);
        $args = ['op' => 'send', 'branch_id' => $f['branch']->id, 'category_id' => $f['category'], 'spent_on' => now('Asia/Kuala_Lumpur')->toDateString(),
            'amount' => '12.50', 'purpose' => 'Synthetic parking at a training day'];
        $out = $this->race(fn () => DB::table('hr_claim_counters')->where('organisation_id', $f['organisation']->id)->lockForUpdate()->first(),
            [$this->worker($f['claimant1'], $args), $this->worker($f['claimant2'], $args)]);

        $this->assertSame(2, substr_count($out, 'SUCCESS send'), $out);
        $this->assertSame(['SC-000001', 'SC-000002'], DB::table('hr_staff_claims')->where('organisation_id', $f['organisation']->id)->orderBy('number')->pluck('number')->all());
        $this->assertSame(2, (int) DB::table('hr_claim_counters')->where('organisation_id', $f['organisation']->id)->value('last_number'));
    }

    public function test_two_payers_recording_one_claim_at_once_record_one_payment(): void
    {
        $f = $this->fixture();
        $service = app(ClaimService::class);
        $claim = $service->send($f['claimant1'], $f['branch'], [], ['category_id' => $f['category'], 'spent_on' => now('Asia/Kuala_Lumpur')->toDateString(),
            'amount' => '12.50', 'purpose' => 'Synthetic parking at a training day', 'idempotency_key' => (string) Str::uuid()]);
        $service->decide($f['approver'], $claim, 'approve', ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
        $args = ['op' => 'pay', 'claim' => $claim, 'method' => 'bank_transfer', 'paid_on' => now('Asia/Kuala_Lumpur')->toDateString(), 'reference' => 'SYN-REF-1111',
            'expected_lock_version' => 2];
        $out = $this->race(fn () => DB::table('hr_staff_claims')->where('public_id', $claim)->lockForUpdate()->first(),
            [$this->worker($f['payer1'], $args), $this->worker($f['payer2'], [...$args, 'reference' => 'SYN-REF-2222'])]);

        $this->assertSame(1, substr_count($out, 'SUCCESS pay'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $id = DB::table('hr_staff_claims')->where('public_id', $claim)->value('id');
        $this->assertSame(1, DB::table('hr_staff_claim_payments')->where('claim_id', $id)->count());
        $this->assertSame('paid', DB::table('hr_staff_claims')->where('id', $id)->value('status'));
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_CLM_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Claims Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'C'.Str::upper(Str::random(5)), 'name' => 'Synthetic Claims Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $approver = $this->user($organisation, $branch, [ClaimPermissions::APPROVE, ClaimPermissions::MANAGE, ClaimPermissions::SUBMIT_OWN]);
        $category = app(ClaimService::class)->saveCategory($approver, ['name' => 'Travel and parking', 'needs_receipt' => false, 'is_active' => true,
            'idempotency_key' => (string) Str::uuid()]);

        return ['organisation' => $organisation, 'branch' => $branch, 'approver' => $approver, 'category' => $category,
            'claimant1' => $this->user($organisation, $branch, [ClaimPermissions::SUBMIT_OWN]), 'claimant2' => $this->user($organisation, $branch, [ClaimPermissions::SUBMIT_OWN]),
            'payer1' => $this->user($organisation, $branch, [ClaimPermissions::PAY]), 'payer2' => $this->user($organisation, $branch, [ClaimPermissions::PAY])];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Claims User '.Str::random(4), 'email' => 'claims.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['assignment_type' => 'permanent', 'is_primary' => true, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $args
     * @return WorkerProcess
     */
    private function worker(User $actor, array $args): array
    {
        $args['idempotency_key'] ??= (string) Str::uuid();
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresClaimsWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-claims-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Takes the row lock $hold asks for, starts both workers, proves each is waiting behind this session, then releases.
     *
     * @param  list<WorkerProcess>  $workers
     */
    private function race(callable $hold, array $workers): string
    {
        DB::beginTransaction();
        try {
            $hold();
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

    /** @param list<WorkerProcess> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + ContentionTimeouts::readyTimeoutSeconds();
        do {
            if (collect($workers)->every(fn ($worker) => str_contains($worker['process']->getOutput(), 'READY '))) {
                return;
            }
            foreach ($workers as $worker) {
                if ($worker['process']->isTerminated() && ! str_contains($worker['process']->getOutput(), 'READY ')) {
                    throw new RuntimeException('Staff claim worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Staff claim worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required staff claim contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected staff claim blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic staff claim evidence.
            foreach (['hr_staff_claim_payments', 'hr_staff_claim_actions', 'hr_staff_claim_receipts', 'hr_staff_claims', 'hr_claim_counters',
                'hr_claim_category_versions', 'hr_claim_categories', 'hr_claim_settings', 'hr_record_retries'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            DB::table('audit_logs')->where('organisation_id', $id)->delete();
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
