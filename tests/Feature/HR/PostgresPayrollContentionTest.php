<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Claims\ClaimPermissions;
use App\Domain\HR\Claims\Services\ClaimService;
use App\Domain\HR\Payroll\PayrollPermissions;
use App\Domain\HR\Payroll\Services\PayrollRunService;
use App\Domain\HR\Payroll\Services\PayrollSetupService;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\StaffFileService;
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
 * HR-7b under real PostgreSQL contention (HR-PLAN 16). A Director locking a payroll run that pays a staff claim and a
 * Finance Officer recording a payment of the same claim wait on the claim's row lock; exactly one of them pays it, and
 * the other is refused with a plain reason.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresPayrollContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_payroll_observer';

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
            $this->markTestSkipped('PostgreSQL payroll regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Payroll concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_workers')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before payroll regressions.');
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

    public function test_a_payroll_lock_and_a_finance_payment_of_the_same_claim_never_both_succeed(): void
    {
        $f = $this->fixture();
        $claim = DB::table('hr_staff_claims')->where('public_id', $f['claim'])->first();
        $this->assertNotNull($claim);
        $run = DB::table('hr_payroll_runs')->where('public_id', $f['run'])->first();
        $this->assertNotNull($run);
        $out = $this->race(fn () => DB::table('hr_staff_claims')->where('id', $claim->id)->lockForUpdate()->first(), [
            $this->worker($f['director'], ['op' => 'lock', 'run' => $f['run'], 'expected_lock_version' => (int) $run->lock_version]),
            $this->worker($f['payer'], ['op' => 'pay', 'claim' => $f['claim'], 'method' => 'bank_transfer', 'paid_on' => now('Asia/Kuala_Lumpur')->toDateString(),
                'reference' => 'SYN-REF-3333', 'expected_lock_version' => (int) $claim->lock_version]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS '), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $payments = DB::table('hr_staff_claim_payments')->where('claim_id', $claim->id)->pluck('method')->all();
        $this->assertCount(1, $payments);
        $this->assertSame('paid', DB::table('hr_staff_claims')->where('id', $claim->id)->value('status'));
        $status = DB::table('hr_payroll_runs')->where('id', $run->id)->value('status');
        if (str_contains($out, 'SUCCESS lock')) {
            $this->assertSame(['payroll', 'locked'], [$payments[0], $status], $out);
        } else {
            $this->assertSame(['bank_transfer', 'approved'], [$payments[0], $status], $out);
            $this->assertStringContainsString('STALE payroll: Staff claim', $out);
        }
    }

    /** @return array<string, mixed> one approved run holding one approved staff claim, for a Director to lock */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_PAY_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Payroll Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'P'.Str::upper(Str::random(5)), 'name' => 'Synthetic Payroll Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $preparer = $this->user($organisation, $branch, [PayrollPermissions::PREPARE, RecordPermissions::MANAGE]);
        $director = $this->user($organisation, $branch, [PayrollPermissions::PREPARE, PayrollPermissions::APPROVE, RecordPermissions::MANAGE]);
        $approver = $this->user($organisation, $branch, [ClaimPermissions::APPROVE, ClaimPermissions::MANAGE]);
        $claimant = $this->user($organisation, $branch, [ClaimPermissions::SUBMIT_OWN]);
        $payer = $this->user($organisation, $branch, [ClaimPermissions::PAY]);
        $k = fn (): string => (string) Str::uuid();

        $claims = app(ClaimService::class);
        $category = $claims->saveCategory($approver, ['name' => 'Travel and parking', 'needs_receipt' => false, 'is_active' => true, 'idempotency_key' => $k()]);
        $claim = $claims->send($claimant, $branch, [], ['category_id' => $category, 'spent_on' => now('Asia/Kuala_Lumpur')->toDateString(), 'amount' => '12.50',
            'purpose' => 'Synthetic parking at a training day', 'idempotency_key' => $k()]);
        $claims->decide($approver, $claim, 'approve', ['expected_lock_version' => 1, 'idempotency_key' => $k()]);

        $setup = app(PayrollSetupService::class);
        $group = $setup->saveGroup($preparer, ['name' => 'Synthetic pay', 'pay_day' => '28', 'is_active' => true, 'idempotency_key' => $k()]);
        $types = [];
        foreach (['overtime_type_id' => ['Overtime', 'earnings'], 'unpaid_leave_type_id' => ['Unpaid leave', 'deduction'], 'claim_type_id' => ['Staff claim refund', 'refund']] as $field => [$name, $kind]) {
            $types[$field] = $setup->saveItemType($preparer, ['name' => $name, 'kind' => $kind, 'is_active' => true, 'idempotency_key' => $k()]);
        }
        $worker = app(StaffFileService::class)->openFile($preparer, ['user_id' => $claimant->id, 'idempotency_key' => $k()]);
        $profile = $setup->enterProfile($preparer, ['worker_id' => $worker->public_id, 'pay_group_id' => $group, 'effective_from' => '2026-01-01', 'pay_type' => 'monthly',
            'rate' => '2,000.00', 'epf' => false, 'socso' => false, 'eis' => false, 'mtd' => false, 'hrd' => false, 'items' => [], 'idempotency_key' => $k()]);
        $setup->decideProfile($director, $profile, 'confirm', ['expected_lock_version' => 1, 'idempotency_key' => $k()]);

        $runs = app(PayrollRunService::class);
        $runs->saveLineTypes($preparer, [...$types, 'expected_version' => 0, 'idempotency_key' => $k()]);
        $run = $runs->prepare($preparer, ['pay_group_id' => $group, 'month' => now('Asia/Kuala_Lumpur')->format('Y-m'), 'kind' => 'regular', 'idempotency_key' => $k()]);
        $version = fn (): int => (int) DB::table('hr_payroll_runs')->where('public_id', $run)->value('lock_version');
        $runs->send($preparer, $run, ['expected_lock_version' => $version(), 'idempotency_key' => $k()]);
        $runs->decide($director, $run, 'approve', ['expected_lock_version' => $version(), 'idempotency_key' => $k()]);

        return ['director' => $director, 'payer' => $payer, 'claim' => $claim, 'run' => $run];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Payroll User '.Str::random(4), 'email' => 'payroll.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPayrollWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-payroll-pg-'.Str::lower(Str::random(8))], base_path());
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
                    throw new RuntimeException('Payroll worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Payroll worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required payroll contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected payroll blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic payroll and staff claim evidence.
            foreach (['hr_payroll_run_actions', 'hr_payroll_lines', 'hr_payroll_manual_lines', 'hr_payroll_run_people', 'hr_payroll_runs', 'hr_payroll_line_types',
                'hr_pay_profile_items', 'hr_pay_profiles', 'hr_pay_item_type_versions', 'hr_pay_item_types', 'hr_pay_groups', 'hr_staff_claim_payments',
                'hr_staff_claim_actions', 'hr_staff_claim_receipts', 'hr_staff_claims', 'hr_claim_counters', 'hr_claim_category_versions', 'hr_claim_categories',
                'hr_claim_settings', 'hr_record_changes', 'hr_employment_periods', 'hr_workers', 'hr_record_retries'] as $table) {
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
