<?php

namespace Tests\Feature;

use App\Domain\Access\BranchAccessService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Models\CashClosingDecision;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\CashClosingService;
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
 * BIL-4 (daily cash closing) under real PostgreSQL contention: two sessions count or decide the same branch and
 * business date at the same moment. The service serialises them with an advisory lock per branch and date; each
 * race holds that lock in this test's session until both workers are proven to be waiting on it, then releases it.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresCashClosingRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_cash_closing_observer';

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
            $this->markTestSkipped('PostgreSQL cash closing regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Cash closing concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('cash_closings') || ! Schema::hasTable('cash_closing_decisions')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before cash closing regressions.');
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

    public function test_two_simultaneous_counts_for_one_day_become_consecutive_revisions(): void
    {
        $f = $this->fixture();
        $out = $this->race($f, [
            $this->worker($f, 'cash-count', 'ca', ['counted_cash' => '35.50', 'variance_reason' => null]),
            $this->worker($f, 'cash-count', 'ca2', ['counted_cash' => '35.00', 'variance_reason' => 'Synthetic: RM 0.50 short']),
        ]);

        $this->assertSame(2, substr_count($out, 'SUCCESS'), $out);
        $closings = CashClosing::query()->where('organisation_id', $f['organisation']->id)->orderBy('revision')->get();
        $this->assertSame([1, 2], $closings->pluck('revision')->all());
        $this->assertSame([3550, 3550], $closings->pluck('expected_cash_sen')->all());
        $this->assertEqualsCanonicalizing([$f['ca']->id, $f['ca2']->id], $closings->pluck('counted_by_user_id')->all());
        $this->assertSame(2, $this->audits($f, 'billing.cash_counted'));
    }

    public function test_a_new_count_and_an_approval_of_the_old_count_have_exactly_one_winner(): void
    {
        $f = $this->fixture();
        $first = $this->recordCount($f, 'ca', '35.50');
        $out = $this->race($f, [
            $this->worker($f, 'cash-count', 'ca2', ['counted_cash' => '35.50', 'variance_reason' => null]),
            $this->worker($f, 'cash-decide', 'supervisor', ['closing' => $first->public_id, 'decision' => 'approved', 'reason' => null]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE cash_closing: '), $out);
        $revisions = CashClosing::query()->where('organisation_id', $f['organisation']->id)->orderBy('revision')->pluck('revision')->all();
        $decisions = CashClosingDecision::query()->where('organisation_id', $f['organisation']->id)->get();
        if ($decisions->isNotEmpty()) {
            // The approval won: the day is closed and the second count was refused.
            $this->assertSame([1], $revisions);
            $this->assertSame([[$first->id, 'approved']], $decisions->map(fn ($d) => [$d->cash_closing_id, $d->decision])->all());
            $this->assertStringContainsString('This day is already closed and approved', $out);
            $this->assertSame(1, $this->audits($f, 'billing.cash_closing_approved'));
            $this->assertSame(1, $this->audits($f, 'billing.cash_counted'));
        } else {
            // The count won: the old count is no longer the latest, so its approval was refused.
            $this->assertSame([1, 2], $revisions);
            $this->assertStringContainsString('already decided or replaced by a newer count', $out);
            $this->assertSame(0, $this->audits($f, 'billing.cash_closing_approved'));
            $this->assertSame(2, $this->audits($f, 'billing.cash_counted'));
        }
    }

    public function test_two_approvers_deciding_the_same_count_record_one_decision(): void
    {
        $f = $this->fixture();
        $closing = $this->recordCount($f, 'ca', '35.50');
        $out = $this->race($f, [
            $this->worker($f, 'cash-decide', 'supervisor', ['closing' => $closing->public_id, 'decision' => 'approved', 'reason' => null]),
            $this->worker($f, 'cash-decide', 'finance', ['closing' => $closing->public_id, 'decision' => 'returned', 'reason' => 'Synthetic: count again']),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE cash_closing: '), $out);
        $this->assertStringContainsString('already decided or replaced by a newer count', $out);
        $decision = CashClosingDecision::query()->where('organisation_id', $f['organisation']->id)->sole();
        $this->assertSame($closing->id, $decision->cash_closing_id);
        $this->assertContains([$decision->decision, $decision->decided_by_user_id], [['approved', $f['supervisor']->id], ['returned', $f['finance']->id]]);
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $f['organisation']->id)->whereIn('event', ['billing.cash_closing_approved', 'billing.cash_closing_returned'])->count());
    }

    public function test_the_same_request_sent_twice_at_once_records_one_count(): void
    {
        $f = $this->fixture();
        $key = (string) Str::uuid();
        $args = ['counted_cash' => '35.50', 'variance_reason' => null, 'idempotency_key' => $key];
        $out = $this->race($f, [$this->worker($f, 'cash-count', 'ca', $args), $this->worker($f, 'cash-count', 'ca', $args)]);

        $this->assertSame(2, substr_count($out, 'SUCCESS'), $out);
        $closing = CashClosing::query()->where('organisation_id', $f['organisation']->id)->sole();
        // Both calls answered with the same record: the second returned the first instead of making another.
        $this->assertSame(2, substr_count($out, 'SUCCESS '.$closing->public_id), $out);
        $this->assertSame(1, $this->audits($f, 'billing.cash_counted'));
    }

    /** @param array<string, mixed> $f */
    private function audits(array $f, string $event): int
    {
        return DB::table('audit_logs')->where('organisation_id', $f['organisation']->id)->where('event', $event)->count();
    }

    /**
     * An own organisation and branch, two CAs, a CA Supervisor and a Finance Officer (explicit grants), and
     * yesterday's cash receipts: RM 10.00 and RM 25.50 posted, plus a reversed one that does not count.
     *
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'CASH_PG_'.Str::upper(Str::random(8)), 'name' => 'Synthetic cash closing PG', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'C'.Str::upper(Str::random(5)), 'name' => 'Synthetic Cash Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $count = ['payments.reconcile.branch', 'branch_context.switch.branch'];
        $approve = [...$count, CashClosingService::APPROVE_PERMISSION];
        $f = ['organisation' => $organisation, 'branch' => $branch, 'ca' => $this->user($organisation, $branch, $count), 'ca2' => $this->user($organisation, $branch, $count),
            'supervisor' => $this->user($organisation, $branch, $approve), 'finance' => $this->user($organisation, $branch, $approve),
            'day' => CarbonImmutable::now($branch->timezone)->subDay()->startOfDay()];
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $organisation->id, 'patient_number' => sprintf('KP-%08d', random_int(97_000_000, 97_999_999)), 'full_name' => 'Synthetic Cash Patient', 'search_name' => 'synthetic cash patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $organisation->id, 'code' => 'CASH', 'name' => 'Synthetic cash', 'is_active' => true, 'classification' => 'cash'])->save();
        // Only this isolated PostgreSQL fixture inserts bare synthetic Cash receipts: all user triggers on `payments`
        // are switched off inside this one insert transaction (re-enabled before commit), because the deferred
        // billing check would refuse a receipt with no invoice allocation. Foreign keys stay enforced.
        DB::transaction(function () use ($organisation, $branch, $patient, $method, $f): void {
            DB::statement('ALTER TABLE payments DISABLE TRIGGER USER');
            foreach ([[1000, 'posted', 1], [2550, 'posted', 600], [9000, 'reversed', 60]] as [$sen, $status, $minutes]) {
                (new Payment)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id,
                    'payment_method_id' => $method->id, 'receipt_number' => 'SYN-'.Str::upper(Str::random(12)), 'amount_sen' => $sen, 'currency' => 'MYR', 'method_snapshot' => $method->name,
                    'status' => $status, 'reference' => null, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', (string) Str::uuid()),
                    'received_at' => $f['day']->addMinutes($minutes)->utc(), 'recorded_by_user_id' => $f['ca']->id, 'lock_version' => 1])->save();
            }
            DB::statement('ALTER TABLE payments ENABLE TRIGGER USER');
        });

        return $f;
    }

    /** @param array<string, mixed> $f */
    private function recordCount(array $f, string $actor, string $counted): CashClosing
    {
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);

        return app(CashClosingService::class)->count($f[$actor], ['expected_branch_id' => $f['branch']->id, 'business_date' => $f['day']->toDateString(),
            'counted_cash' => $counted, 'variance_reason' => null, 'idempotency_key' => (string) Str::uuid()]);
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Cash Closing User', 'email' => 'cash.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
    private function worker(array $f, string $mode, string $actor, array $args): array
    {
        $args = ['business_date' => $f['day']->toDateString(), 'idempotency_key' => (string) Str::uuid(), ...$args];
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresCashClosingWorker.php'), $mode, (string) $f[$actor]->id, (string) $f['branch']->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-cash-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the service's own advisory lock for this branch and day, starts both workers, proves each one is
     * waiting behind this session, then releases the lock so they run strictly one after the other.
     *
     * @param  array<string, mixed>  $f
     * @param  list<Worker>  $workers
     */
    private function race(array $f, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['cash_closing:'.$f['organisation']->id.':'.$f['branch']->id.':'.$f['day']->toDateString()]);
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
                    throw new RuntimeException('Cash closing worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Cash closing worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required cash closing contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected cash closing blocker chain was not observed. '.$this->processDiagnostics($process));
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
            foreach (['cash_closing_decisions', 'cash_closings'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            foreach (['payments', 'payment_methods', 'audit_logs', 'patients', 'patient_number_counters'] as $table) {
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
