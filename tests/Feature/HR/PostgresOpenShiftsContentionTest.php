<?php

namespace Tests\Feature\HR;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\HR\OpenShifts\OpenShiftPermissions;
use App\Domain\HR\OpenShifts\Services\OpenShiftService;
use App\Domain\HR\Records\RecordPermissions;
use App\Domain\HR\Records\Services\LocumSignIns;
use App\Domain\HR\Records\Services\StaffFileService;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterRules;
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
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * HR-15b under real PostgreSQL contention: two managers confirming two different locums for one open shift confirm one,
 * and the shift joins the draft roster once. The race holds the open shift row in this test's session until both
 * workers are proven to be waiting.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresOpenShiftsContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_open_shifts_observer';

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
            $this->markTestSkipped('PostgreSQL open shift regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Open shift concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_open_shifts')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before open shift regressions.');
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

    public function test_two_managers_confirming_two_locums_for_one_shift_confirm_one(): void
    {
        $f = $this->fixture();
        $shiftId = (int) DB::table('hr_open_shifts')->where('public_id', $f['shift'])->value('id');

        $out = $this->race('hr_open_shifts', $shiftId, [
            $this->worker($f['hr'], ['ask' => $f['asks'][0], 'expected_lock_version' => 1]),
            $this->worker($f['hr2'], ['ask' => $f['asks'][1], 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS confirm'), $out);
        $this->assertSame(1, substr_count($out, 'STALE open_shift: '), $out);
        $this->assertSame(1, DB::table('hr_open_shift_requests')->where('open_shift_id', $shiftId)->where('status', 'confirmed')->count());
        $this->assertSame(1, DB::table('hr_open_shift_requests')->where('open_shift_id', $shiftId)->where('status', 'declined')->count());
        $this->assertSame(1, DB::table('hr_roster_shifts')->where('organisation_id', $f['organisation']->id)->count());
        $this->assertSame('filled', DB::table('hr_open_shifts')->where('id', $shiftId)->value('status'));
    }

    /**
     * TEAM.md section 7 hardening (HR-15b known limit): two managers confirm two locums for two different open shifts in
     * the same branch week, while that week has no draft roster yet. Both confirms take the week's advisory lock in
     * nextDraft(); the test holds it until both are proven waiting. The first starts the draft, and the second finds it
     * and uses it, so the week gets one draft holding both shifts, not two drafts or a duplicate-week error.
     */
    public function test_two_confirms_into_one_branch_week_with_no_draft_share_one_new_draft(): void
    {
        $f = $this->fixture(twoShifts: true);
        $this->assertSame(0, DB::table('hr_roster_weeks')->where('branch_id', $f['branch']->id)->count());

        $out = $this->raceOnAdvisoryLock($f['branch'], $f['date'], [
            $this->worker($f['hr'], ['ask' => $f['asks'][0], 'expected_lock_version' => 1]),
            $this->worker($f['hr2'], ['ask' => $f['asks'][1], 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(2, substr_count($out, 'SUCCESS confirm'), $out);
        $this->assertSame(1, DB::table('hr_roster_weeks')->where('branch_id', $f['branch']->id)->count());
        $versions = DB::table('hr_roster_versions')->where('branch_id', $f['branch']->id)->get();
        $this->assertCount(1, $versions);
        $this->assertSame('draft', $versions[0]->status);
        $this->assertSame(2, DB::table('hr_roster_shifts')->where('roster_version_id', $versions[0]->id)->count());
        $this->assertSame(2, DB::table('hr_open_shifts')->where('organisation_id', $f['organisation']->id)->where('status', 'filled')->count());
        $this->assertSame(2, DB::table('hr_open_shift_requests')->where('organisation_id', $f['organisation']->id)->where('status', 'confirmed')->count());
    }

    /** @return array<string, mixed> */
    private function fixture(bool $twoShifts = false): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_OPS_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Open Shift Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'O'.Str::upper(Str::random(5)), 'name' => 'Synthetic Open Shift Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $managing = [OpenShiftPermissions::MANAGE, RosterPermissions::MANAGE, RecordPermissions::MANAGE];
        $hr = $this->user($organisation, $branch, $managing);
        $hr2 = $this->user($organisation, $branch, $managing);
        $admin = $this->user($organisation, $branch, ['staff.manage.organisation', 'access.manage.organisation'], 'technical_admin');
        $files = app(StaffFileService::class);
        $signIns = app(LocumSignIns::class);
        $date = now('Asia/Kuala_Lumpur')->addDays(7)->toDateString();
        $shift = app(OpenShiftService::class)->post($hr, ['branch_id' => $branch->id, 'date' => $date, 'start_time' => '08:00', 'end_time' => '14:00',
            'break_minutes' => 0, 'session_type' => 'morning', 'role_needed' => 'resident_doctor', 'idempotency_key' => (string) Str::uuid()]);
        // With $twoShifts, the second locum asks for a second shift on the same day (so the same branch week).
        $shifts = [$shift, $twoShifts ? app(OpenShiftService::class)->post($hr, ['branch_id' => $branch->id, 'date' => $date, 'start_time' => '14:00', 'end_time' => '20:00',
            'break_minutes' => 0, 'session_type' => 'evening', 'role_needed' => 'resident_doctor', 'idempotency_key' => (string) Str::uuid()]) : $shift];
        $asks = [];
        foreach ([1, 2] as $n) {
            $locum = $this->user($organisation, $branch, [OpenShiftPermissions::REQUEST_OWN, RosterPermissions::VIEW_OWN], PermissionCatalogue::LOCUM_ROLE);
            $worker = $files->addLocum($hr, ['display_name' => "Synthetic Locum {$n}", 'phone' => '012-345 6789', 'idempotency_key' => (string) Str::uuid()]);
            $signIns->request($hr, $worker->public_id, ['email' => $locum->email, 'idempotency_key' => (string) Str::uuid()]);
            $request = DB::table('hr_locum_sign_ins')->where('worker_id', $worker->id)->first();
            $signIns->link($admin, $request->public_id, ['user_id' => $locum->id, 'expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()]);
            app(OpenShiftService::class)->ask($locum, $shifts[$n - 1], ['idempotency_key' => (string) Str::uuid()]);
            $asks[] = (string) DB::table('hr_open_shift_requests')->where('locum_user_id', $locum->id)->value('public_id');
        }

        return ['organisation' => $organisation, 'branch' => $branch, 'hr' => $hr, 'hr2' => $hr2, 'shift' => $shift, 'asks' => $asks, 'date' => $date];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions, ?string $role = null): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Open Shift User '.Str::random(4), 'email' => 'open-shifts.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $user->assignRole(Role::findOrCreate($role ?? 'hr_manager', 'web'));
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresOpenShiftsWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-open-shifts-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the open shift row, starts both workers, proves each is waiting behind this session,
     * then releases.
     *
     * @param  list<WorkerProcess>  $workers
     */
    private function race(string $table, int $rowId, array $workers): string
    {
        DB::beginTransaction();
        try {
            DB::table($table)->where('id', $rowId)->lockForUpdate()->first();
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

    /**
     * Holds the branch week's advisory lock (the key nextDraft() takes), starts both workers, proves each is waiting
     * behind this session, then releases.
     *
     * @param  list<WorkerProcess>  $workers
     */
    private function raceOnAdvisoryLock(Branch $branch, string $date, array $workers): string
    {
        $weekStart = app(RosterRules::class)->weekStart($branch, $date)->toDateString();
        DB::beginTransaction();
        try {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['hr_open_shift_week:'.$branch->organisation_id.':'.$branch->id.':'.$weekStart]);
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
                    throw new RuntimeException('Open shift worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Open shift worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required open shift contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected open shift blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic open shift evidence.
            foreach (['hr_open_shift_requests', 'hr_open_shifts', 'hr_locum_sign_ins', 'hr_roster_requests', 'hr_roster_actions', 'hr_roster_shifts', 'hr_roster_versions',
                'hr_roster_weeks', 'hr_record_changes', 'hr_record_retries'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            DB::statement('ALTER TABLE hr_workers DISABLE TRIGGER USER');
            DB::table('hr_workers')->where('organisation_id', $id)->delete();
            DB::statement('ALTER TABLE hr_workers ENABLE TRIGGER USER');
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
