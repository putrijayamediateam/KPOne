<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Leave\LeavePermissions;
use App\Domain\HR\Leave\Models\LeaveRequest;
use App\Domain\HR\Leave\Models\LeaveType;
use App\Domain\HR\Leave\Services\LeaveEntryService;
use App\Domain\HR\Leave\Services\LeaveRequestService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
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
 * HR-2a under real PostgreSQL contention. Every leave write locks the applicant's account row first, so two writes that
 * spend the same last days run one after the other: exactly one wins and the other is refused with a plain reason.
 * Each race holds the applicant's row in this test's session until both workers are proven to be waiting on it.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresLeaveContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_leave_observer';

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
            $this->markTestSkipped('PostgreSQL leave regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Leave concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_leave_requests')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before leave regressions.');
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

    public function test_an_approval_and_a_negative_adjustment_spending_the_last_day_have_exactly_one_winner(): void
    {
        $f = $this->fixture(2);
        $request = app(LeaveRequestService::class)->apply($f['applicant'], ['leave_type_id' => $f['type']->id, 'start_date' => $f['day']->toDateString(),
            'end_date' => $f['day']->toDateString(), 'idempotency_key' => (string) Str::uuid()]);
        $entry = app(LeaveEntryService::class)->enter($f['manager1'], 'adjustment', ['user_id' => $f['applicant']->id, 'leave_type_id' => $f['type']->id,
            'year' => $f['day']->year, 'days' => '-1', 'reason_code' => 'correction', 'idempotency_key' => (string) Str::uuid()]);
        $out = $this->race($f['applicant'], [
            $this->worker($f['manager1'], ['op' => 'approve', 'request' => $request->public_id, 'expected_lock_version' => 1]),
            $this->worker($f['manager2'], ['op' => 'confirm', 'entry' => $entry->public_id, 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE leave: Not enough leave left'), $out);
        $this->assertSame(0, (int) DB::table('hr_leave_ledger')->where('user_id', $f['applicant']->id)->sum('halves'));
    }

    public function test_two_managers_approving_one_request_record_one_approval(): void
    {
        $f = $this->fixture(4);
        $request = app(LeaveRequestService::class)->apply($f['applicant'], ['leave_type_id' => $f['type']->id, 'start_date' => $f['day']->toDateString(),
            'end_date' => $f['day']->toDateString(), 'idempotency_key' => (string) Str::uuid()]);
        $out = $this->race($f['applicant'], [
            $this->worker($f['manager1'], ['op' => 'approve', 'request' => $request->public_id, 'expected_lock_version' => 1]),
            $this->worker($f['manager2'], ['op' => 'approve', 'request' => $request->public_id, 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE leave: '), $out);
        $this->assertSame('approved', LeaveRequest::query()->whereKey($request->id)->value('status'));
        $this->assertSame(1, DB::table('hr_leave_ledger')->where('leave_request_id', $request->id)->where('kind', 'taken')->count());
    }

    public function test_two_overlapping_applications_at_once_record_one_request(): void
    {
        $f = $this->fixture(10);
        $input = ['op' => 'apply', 'leave_type_id' => $f['type']->id, 'start_date' => $f['day']->toDateString(), 'end_date' => $f['day']->toDateString()];
        $out = $this->race($f['applicant'], [
            $this->worker($f['applicant'], [...$input, 'idempotency_key' => (string) Str::uuid()]),
            $this->worker($f['applicant'], [...$input, 'idempotency_key' => (string) Str::uuid()]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE start_date: You already have leave on'), $out);
        $this->assertSame(1, LeaveRequest::query()->where('user_id', $f['applicant']->id)->count());
    }

    /** @return array<string, mixed> */
    private function fixture(int $halves): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_LV_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Leave Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'L'.Str::upper(Str::random(5)), 'name' => 'Synthetic Leave Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $manager1 = $this->user($organisation, $branch, [LeavePermissions::APPROVE, LeavePermissions::MANAGE, LeavePermissions::REQUEST_OWN]);
        $manager2 = $this->user($organisation, $branch, [LeavePermissions::APPROVE, LeavePermissions::MANAGE, LeavePermissions::REQUEST_OWN]);
        $applicant = $this->user($organisation, $branch, [LeavePermissions::REQUEST_OWN]);
        // A Monday two weeks ahead in the branch time zone (never the UTC clock).
        $day = CarbonImmutable::now($branch->timezone)->startOfDay()->addWeeks(2)->startOfWeek(CarbonImmutable::MONDAY);
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $day->toDateString()) ?: throw new RuntimeException('Bad date.');
        DB::table('hr_branch_working_days')->insert(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'weekdays' => json_encode([1, 2, 3, 4, 5]),
            'updated_by_user_id' => $manager1->id, 'created_at' => now(), 'updated_at' => now()]);
        $type = new LeaveType;
        $type->forceFill(['organisation_id' => $organisation->id, 'code' => 'AL', 'name' => 'Synthetic annual leave', 'is_paid' => true, 'uses_balance' => true,
            'allow_half_days' => true, 'evidence_needed' => false, 'updated_by_user_id' => $manager1->id])->save();
        DB::table('hr_leave_ledger')->insert(['organisation_id' => $organisation->id, 'user_id' => $applicant->id, 'leave_type_id' => $type->id, 'year' => $day->year,
            'kind' => 'grant', 'halves' => $halves, 'created_by_user_id' => $manager1->id, 'created_at' => now()]);

        return ['organisation' => $organisation, 'branch' => $branch, 'type' => $type, 'day' => $day, 'manager1' => $manager1, 'manager2' => $manager2, 'applicant' => $applicant];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Leave User '.Str::random(4), 'email' => 'leave.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
     * @return Worker
     */
    private function worker(User $actor, array $args): array
    {
        $args['idempotency_key'] ??= (string) Str::uuid();
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresLeaveWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-leave-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the applicant's account row, starts both workers, proves each is waiting behind this session, then releases.
     *
     * @param  list<Worker>  $workers
     */
    private function race(User $applicant, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $applicant->id)->lockForUpdate()->first();
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
                    throw new RuntimeException('Leave worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Leave worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required leave contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected leave blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic leave evidence.
            foreach (['hr_leave_ledger', 'hr_leave_request_actions', 'hr_leave_retries', 'hr_leave_requests', 'hr_leave_adjustments', 'hr_leave_openings', 'hr_leave_entitlements'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            foreach (['hr_public_holidays', 'hr_branch_working_days', 'hr_leave_types', 'audit_logs'] as $table) {
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
