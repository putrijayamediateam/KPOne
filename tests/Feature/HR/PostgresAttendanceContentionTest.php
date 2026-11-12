<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Attendance\ClockPermissions;
use App\Domain\HR\Attendance\Models\AttendanceCorrection;
use App\Domain\HR\Attendance\Models\ClockSession;
use App\Domain\HR\Attendance\Photos\ClockPhotoService;
use App\Domain\HR\Attendance\Services\CorrectionService;
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
 * HR-3a under real PostgreSQL contention. Every attendance write locks the staff member's account row first, so two
 * presses or two decisions run one after the other: exactly one wins and the other is refused with a plain reason.
 * Each race holds the staff member's row in this test's session until both workers are proven to be waiting on it.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresAttendanceContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_attendance_observer';

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

    public function test_two_clock_ins_at_once_open_one_session(): void
    {
        $f = $this->fixture();
        $out = $this->race($f['staff'], [
            $this->worker($f['staff'], ['op' => 'in']),
            $this->worker($f['staff'], ['op' => 'in']),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE clock: You are already clocked in since'), $out);
        $this->assertSame(1, ClockSession::query()->where('user_id', $f['staff']->id)->count());
        $this->assertSame(1, DB::table('hr_clock_events')->where('organisation_id', $f['organisation']->id)->count());
    }

    public function test_two_managers_deciding_one_correction_record_one_decision(): void
    {
        $f = $this->fixture();
        $now = CarbonImmutable::now('UTC');
        $session = new ClockSession;
        $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'user_id' => $f['staff']->id, 'branch_id' => $f['branch']->id,
            'local_date' => $now->subHours(3)->setTimezone($f['branch']->timezone)->toDateString(), 'timezone' => $f['branch']->timezone,
            'clock_in_at' => $now->subHours(3), 'status' => 'open'])->save();
        $correction = app(CorrectionService::class)->propose($f['staff'], ['kind' => 'set_out', 'session_id' => $session->public_id, 'reason_code' => 'forgot',
            'out_time' => $now->subHour()->setTimezone($f['branch']->timezone)->format('Y-m-d\TH:i'), 'idempotency_key' => (string) Str::uuid()]);
        $out = $this->race($f['staff'], [
            $this->worker($f['manager1'], ['op' => 'approve', 'correction' => $correction->public_id, 'expected_lock_version' => 1]),
            $this->worker($f['manager2'], ['op' => 'approve', 'correction' => $correction->public_id, 'expected_lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE attendance: '), $out);
        $this->assertSame('approved', AttendanceCorrection::query()->whereKey($correction->id)->value('status'));
        $this->assertSame('closed', ClockSession::query()->whereKey($session->id)->value('status'));
        $this->assertSame(1, DB::table('hr_clock_events')->where('correction_id', $correction->id)->count());
    }

    /**
     * TEAM.md section 7 hardening (HR-3a known limit): a clock-in racing a manager approving that person's correction.
     * Both lock the staff member's account row first, so they run one after the other in either order. Whichever goes
     * first, the approval lands once and closes the session at the corrected time, and the clock-in is either refused
     * ("already clocked in", when it goes first) or opens a new session that starts after the corrected clock-out time.
     * The two sessions never overlap.
     */
    public function test_a_clock_in_queued_before_an_approved_correction_is_refused_and_the_correction_lands_once(): void
    {
        $this->clockInAgainstApprovedCorrection(clockInFirst: true);
    }

    public function test_a_clock_in_queued_after_an_approved_correction_starts_after_the_corrected_clock_out(): void
    {
        $this->clockInAgainstApprovedCorrection(clockInFirst: false);
    }

    private function clockInAgainstApprovedCorrection(bool $clockInFirst): void
    {
        $f = $this->fixture();
        $now = CarbonImmutable::now('UTC');
        $session = new ClockSession;
        $session->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'user_id' => $f['staff']->id, 'branch_id' => $f['branch']->id,
            'local_date' => $now->subHours(3)->setTimezone($f['branch']->timezone)->toDateString(), 'timezone' => $f['branch']->timezone,
            'clock_in_at' => $now->subHours(3), 'status' => 'open'])->save();
        $correction = app(CorrectionService::class)->propose($f['staff'], ['kind' => 'set_out', 'session_id' => $session->public_id, 'reason_code' => 'forgot',
            'out_time' => $now->subHour()->setTimezone($f['branch']->timezone)->format('Y-m-d\TH:i'), 'idempotency_key' => (string) Str::uuid()]);
        $in = $this->worker($f['staff'], ['op' => 'in']);
        $approve = $this->worker($f['manager1'], ['op' => 'approve', 'correction' => $correction->public_id, 'expected_lock_version' => 1]);
        $out = $this->race($f['staff'], $clockInFirst ? [$in, $approve] : [$approve, $in], inOrder: true);

        // The approval always lands, once, and closes the session at the corrected time.
        $this->assertSame(1, substr_count($out, 'SUCCESS approve'), $out);
        $this->assertSame('approved', AttendanceCorrection::query()->whereKey($correction->id)->value('status'));
        $this->assertSame(1, DB::table('hr_clock_events')->where('correction_id', $correction->id)->count());
        $closed = ClockSession::query()->whereKey($session->id)->firstOrFail();
        $this->assertSame('closed', $closed->status);
        $this->assertNotNull($closed->clock_out_at);

        $sessions = ClockSession::query()->where('user_id', $f['staff']->id)->orderBy('clock_in_at')->get();
        if (! $clockInFirst) {
            // The approval went first: the clock-in opened a new session after the corrected clock-out.
            $this->assertSame(1, substr_count($out, 'SUCCESS in'), $out);
            $this->assertCount(2, $sessions, $out);
            $this->assertSame('open', $sessions[1]->status);
            $this->assertTrue($sessions[1]->clock_in_at->greaterThanOrEqualTo($closed->clock_out_at), $out);
        } else {
            // The clock-in went first and found the session still open.
            $this->assertSame(1, substr_count($out, 'STALE clock: You are already clocked in since'), $out);
            $this->assertCount(1, $sessions, $out);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_AT_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Attendance Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'A'.Str::upper(Str::random(5)), 'name' => 'Synthetic Attendance Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $manager1 = $this->user($organisation, $branch, [ClockPermissions::REVIEW, ClockPermissions::CLOCK_OWN]);
        $manager2 = $this->user($organisation, $branch, [ClockPermissions::REVIEW, ClockPermissions::CLOCK_OWN]);
        $staff = $this->user($organisation, $branch, [ClockPermissions::CLOCK_OWN]);
        // Settings from yesterday in the branch time zone (never the UTC clock), so corrections are open.
        DB::table('hr_attendance_settings')->insert(['organisation_id' => $organisation->id, 'effective_from' => CarbonImmutable::now($branch->timezone)->subDay()->toDateString(),
            'grace_minutes' => 5, 'forgotten_window_minutes' => 60, 'correction_days' => 7, 'created_by_user_id' => $manager1->id, 'created_at' => now()]);

        return ['organisation' => $organisation, 'branch' => $branch, 'manager1' => $manager1, 'manager2' => $manager2, 'staff' => $staff];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Attendance User '.Str::random(4), 'email' => 'attendance.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
        if ($args['op'] === 'in') {
            // HR-PHOTO: the camera's token, taken before the race (the worker makes its own synthetic photo).
            $args['capture_token'] ??= app(ClockPhotoService::class)->issueToken($actor, 'in')['token'];
        }
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresAttendanceWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-attendance-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the staff member's account row, starts both workers, proves each is waiting behind this session, then releases.
     *
     * With $inOrder, each worker is sent GO only once the one before it is proven waiting, so PostgreSQL queues them on
     * the row lock in that order and the race is decided the same way every run.
     *
     * @param  list<Worker>  $workers
     */
    private function race(User $staff, array $workers, bool $inOrder = false): string
    {
        DB::beginTransaction();
        try {
            DB::table('users')->where('id', $staff->id)->lockForUpdate()->first();
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            foreach ($workers as $worker) {
                $worker['process']->start();
            }
            $this->waitReady($workers);
            if ($inOrder) {
                foreach ($workers as $worker) {
                    $worker['input']->write("GO\n");
                    $worker['input']->close();
                    $this->waitForDatabaseBlock($worker['process'], $parentPid);
                }
            } else {
                foreach ($workers as $worker) {
                    $worker['input']->write("GO\n");
                    $worker['input']->close();
                }
                foreach ($workers as $worker) {
                    $this->waitForDatabaseBlock($worker['process'], $parentPid);
                }
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
            foreach (['hr_clock_photos', 'hr_clock_capture_tokens', 'hr_clock_events', 'hr_attendance_corrections', 'hr_clock_sessions', 'hr_attendance_retries', 'hr_attendance_settings'] as $table) {
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
