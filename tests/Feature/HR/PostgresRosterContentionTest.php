<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Roster\Models\RosterVersion;
use App\Domain\HR\Roster\RosterPermissions;
use App\Domain\HR\Roster\Services\RosterService;
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
 * HR-1 under real PostgreSQL contention. Every roster write locks the rostered person's account row, so two Directors
 * approving overlapping shifts for one person at two branches, or two Directors approving the same week, run one
 * after the other: exactly one approval wins and the other is refused with a plain reason. Each race holds the
 * contended row in this test's session until both workers are proven to be waiting on it.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresRosterContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_roster_observer';

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
            $this->markTestSkipped('PostgreSQL roster regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Roster concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_roster_versions')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before roster regressions.');
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

    public function test_overlapping_shifts_for_one_person_at_two_branches_have_exactly_one_approval(): void
    {
        $f = $this->fixture();
        $a = $this->sentWeek($f, $f['branchA'], '08:00', '14:00');
        $b = $this->sentWeek($f, $f['branchB'], '12:00', '18:00');
        $out = $this->race($f['subject'], [$this->worker($f['director1'], $a), $this->worker($f['director2'], $b)]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE roster: '), $out);
        $this->assertStringContainsString('already has a shift from', $out);
        $statuses = RosterVersion::query()->whereIn('id', [$a->id, $b->id])->pluck('status')->sort()->values()->all();
        $this->assertSame(['approved', 'submitted'], $statuses);
        $this->assertSame(1, DB::table('audit_logs')->where('organisation_id', $f['organisation']->id)->where('event', 'hr.roster.approved')->count());
    }

    public function test_two_directors_approving_the_same_week_record_one_approval(): void
    {
        $f = $this->fixture();
        $a = $this->sentWeek($f, $f['branchA'], '08:00', '14:00');
        $out = $this->race($f['subject'], [$this->worker($f['director1'], $a), $this->worker($f['director2'], $a)]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE roster: '), $out);
        $version = $a->refresh();
        $this->assertSame('approved', $version->status);
        $this->assertContains($version->decided_by_user_id, [$f['director1']->id, $f['director2']->id]);
        $this->assertSame(1, DB::table('hr_roster_actions')->where('roster_version_id', $a->id)->where('action', 'approved')->count());
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_PG_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Roster Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branches = [];
        foreach (['A', 'B'] as $suffix) {
            $branch = new Branch;
            $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'R'.$suffix.Str::upper(Str::random(4)), 'name' => 'Synthetic Roster Branch '.$suffix, 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
            $branches[] = $branch;
        }
        [$a, $b] = $branches;
        $week = CarbonImmutable::now($a->timezone)->startOfDay()->addWeek()->startOfWeek(CarbonImmutable::MONDAY);

        return ['organisation' => $organisation, 'branchA' => $a, 'branchB' => $b, 'week' => $week,
            'hr' => $this->user($organisation, $branches, [RosterPermissions::MANAGE]),
            'director1' => $this->user($organisation, $branches, [RosterPermissions::MANAGE, RosterPermissions::APPROVE]),
            'director2' => $this->user($organisation, $branches, [RosterPermissions::MANAGE, RosterPermissions::APPROVE]),
            'subject' => $this->user($organisation, $branches, [RosterPermissions::VIEW_OWN])];
    }

    /** @param array<string, mixed> $f */
    private function sentWeek(array $f, Branch $branch, string $start, string $end): RosterVersion
    {
        $service = app(RosterService::class);
        $version = $service->startDraft($f['hr'], ['branch_id' => $branch->id, 'week' => $f['week']->toDateString(), 'mode' => 'blank', 'idempotency_key' => (string) Str::uuid()])['version'];
        $version = $service->addShift($f['hr'], $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'staff_user_id' => $f['subject']->id,
            'date' => $f['week']->toDateString(), 'start_time' => $start, 'end_time' => $end, 'break_minutes' => 0, 'session_type' => 'morning', 'idempotency_key' => (string) Str::uuid()]);

        return $service->submit($f['hr'], $version->public_id, ['expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()]);
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<string>  $permissions
     */
    private function user(Organisation $organisation, array $branches, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Roster User '.Str::random(4), 'email' => 'roster.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        foreach ($branches as $i => $branch) {
            StaffBranchAssignmentBootstrapper::create($profile, $branch, ['assignment_type' => 'permanent', 'is_primary' => $i === 0, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => null]);
        }

        return $user->refresh();
    }

    /** @return Worker */
    private function worker(User $actor, RosterVersion $version): array
    {
        $args = ['version' => $version->public_id, 'expected_version_lock' => $version->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid()];
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresRosterWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-roster-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the rostered person's account row (or, for the same week, the workers queue on the week row behind the
     * first), starts both workers, proves each is waiting behind this session, then releases.
     *
     * @param  list<Worker>  $workers
     */
    private function race(User $subject, array $workers): string
    {
        DB::beginTransaction();
        try {
            // Both approvals lock their week first, then the subject; holding the subject row makes the first block
            // here and, for the same week, the second block on the first's week lock (a chain back to this session).
            DB::table('users')->where('id', $subject->id)->lockForUpdate()->first();
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
                    throw new RuntimeException('Roster worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Roster worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required roster contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected roster blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic roster evidence.
            foreach (['hr_roster_requests', 'hr_roster_actions', 'hr_roster_shifts', 'hr_roster_versions'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            foreach (['hr_roster_weeks', 'hr_minimum_staffing', 'audit_logs'] as $table) {
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
