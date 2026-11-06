<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Models\OvertimeRequest;
use App\Domain\HR\Attendance\Services\OvertimeService;
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
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * HR-3b under real PostgreSQL contention. Every overtime write locks the staff member's account row first, so two asks
 * or two decisions run one after the other: exactly one wins and the other is refused with a plain reason. Each race
 * holds the staff member's row in this test's session until both workers are proven to be waiting on it.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresOvertimeContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_overtime_observer';

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
            $this->markTestSkipped('PostgreSQL attendance regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Attendance concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_clock_sessions')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before attendance regressions.');
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

    public function test_two_managers_deciding_one_overtime_request_record_one_decision(): void
    {
        $f = $this->fixture();
        $request = app(OvertimeService::class)->ask($f['staff'], ['session_id' => $f['session']->public_id, 'minutes' => 60, 'reason_code' => 'patient_load',
            'idempotency_key' => (string) Str::uuid()]);
        $out = $this->race($f['staff'], [
            $this->worker($f['manager1'], ['op' => 'approve_overtime', 'request' => $request->public_id, 'expected_lock_version' => 1]),
            $this->worker($f['manager2'], ['op' => 'approve_overtime', 'request' => $request->public_id, 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE attendance: This was changed by someone else.'), $out);
        $this->assertSame(['approved', 60], OvertimeRequest::query()->whereKey($request->id)->get(['status', 'minutes_approved'])->map(fn ($r) => [$r->status, $r->minutes_approved])->first());
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $f['organisation']->id)->where('event', 'hr.overtime.approved')->count());
    }

    public function test_two_asks_for_one_session_record_one_request(): void
    {
        $f = $this->fixture();
        $ask = ['op' => 'ask_overtime', 'session_id' => $f['session']->public_id, 'minutes' => 60, 'reason_code' => 'patient_load'];
        $out = $this->race($f['staff'], [$this->worker($f['staff'], $ask), $this->worker($f['staff'], $ask)]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE session_id: Overtime was already asked for on this day.'), $out);
        $this->assertSame(1, OvertimeRequest::query()->where('clock_session_id', $f['session']->id)->count());
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_OT_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Overtime Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'A'.Str::upper(Str::random(5)), 'name' => 'Synthetic Overtime Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $manager1 = $this->user($organisation, $branch, [ClockPermissions::REVIEW, ClockPermissions::OVERTIME_APPROVE, ClockPermissions::CLOCK_OWN]);
        $manager2 = $this->user($organisation, $branch, [ClockPermissions::REVIEW, ClockPermissions::OVERTIME_APPROVE, ClockPermissions::CLOCK_OWN]);
        $staff = $this->user($organisation, $branch, [ClockPermissions::CLOCK_OWN]);
        $staff->assignRole(Role::findOrCreate('ca', 'web'));
        // Settings from yesterday in the branch time zone (never the UTC clock), so corrections are open.
        DB::table('hr_attendance_settings')->insert(['organisation_id' => $organisation->id, 'effective_from' => CarbonImmutable::now($branch->timezone)->subDay()->toDateString(),
            'grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7, 'created_by_user_id' => $manager1->id, 'created_at' => now()]);
        $settingId = DB::table('hr_overtime_settings')->insertGetId(['organisation_id' => $organisation->id, 'effective_from' => CarbonImmutable::now($branch->timezone)->subDay()->toDateString(),
            'normal_multiplier' => 1.5, 'rest_day_multiplier' => 2, 'public_holiday_multiplier' => 3, 'nearing_hours' => 40, 'limit_hours' => 45,
            'created_by_user_id' => $manager1->id, 'created_at' => now()]);
        DB::table('hr_overtime_setting_roles')->insert(['overtime_setting_id' => $settingId, 'role_name' => 'ca']);
        // A closed four-hour session not on the roster, so all of it may be asked for.
        $now = CarbonImmutable::now('UTC');
        $session = new ClockSession;
        $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $staff->id, 'branch_id' => $branch->id,
            'local_date' => $now->subHours(5)->setTimezone($branch->timezone)->toDateString(), 'timezone' => $branch->timezone,
            'clock_in_at' => $now->subHours(5), 'clock_out_at' => $now->subHour(), 'status' => 'closed'])->save();

        return ['organisation' => $organisation, 'branch' => $branch, 'manager1' => $manager1, 'manager2' => $manager2, 'staff' => $staff, 'session' => $session->refresh()];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Overtime User '.Str::random(4), 'email' => 'overtime.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresAttendanceWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-overtime-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the staff member's account row, starts both workers, proves each is waiting behind this session, then releases.
     *
     * @param  list<Worker>  $workers
     */
    private function race(User $staff, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $staff->id)->lockForUpdate()->first();
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
                    throw new RuntimeException('Attendance worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Attendance worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required attendance contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected attendance blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic attendance evidence.
            $settingIds = DB::table('hr_overtime_settings')->where('organisation_id', $id)->pluck('id');
            DB::statement('ALTER TABLE hr_overtime_setting_roles DISABLE TRIGGER USER');
            DB::table('hr_overtime_setting_roles')->whereIn('overtime_setting_id', $settingIds)->delete();
            DB::statement('ALTER TABLE hr_overtime_setting_roles ENABLE TRIGGER USER');
            foreach (['hr_overtime_requests', 'hr_overtime_settings', 'hr_clock_events', 'hr_attendance_corrections', 'hr_clock_sessions', 'hr_attendance_retries', 'hr_attendance_settings'] as $table) {
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
