<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Applications\ApplicationPermissions;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
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
 * HR-17c under real PostgreSQL contention (HR-PLAN 19.6, "Whole group"). Two HR managers confirming the same slot for two
 * candidates at once get one interview; the second waits on the slot's lock, then is refused with a plain reason.
 * HR-17d: two managers adding the tenth interview document of one position type at once get one; the other is refused.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresInterviewContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_interview_observer';

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
            $this->markTestSkipped('PostgreSQL interview regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Interview concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_job_interviews')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before interview regressions.');
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

    public function test_two_managers_confirming_one_slot_for_two_candidates_get_one_interview(): void
    {
        $f = $this->fixture();
        $start = '2030-01-08T02:00:00Z';
        $a = $this->scheduledApplication($f, $start);
        $b = $this->scheduledApplication($f, $start);
        $slotLock = "hr_job_interview_slot:{$f['organisation']->id}:{$start}";
        $out = $this->race(fn () => DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$slotLock]), [
            $this->worker($f['hr1'], ['application' => $a['public_id'], 'start' => $start, 'expected_lock_version' => $a['lock']]),
            $this->worker($f['hr2'], ['application' => $b['public_id'], 'start' => $start, 'expected_lock_version' => $b['lock']]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE start: This time was just confirmed for another candidate.'), $out);
        $this->assertSame(1, DB::table('hr_job_interviews')->where('organisation_id', $f['organisation']->id)->where('status', 'confirmed')->count());
        $this->assertSame(1, DB::table('hr_job_applications')->where('organisation_id', $f['organisation']->id)->where('status', 'interview')->count());
        $this->assertSame(1, DB::table('hr_job_applications')->where('organisation_id', $f['organisation']->id)->where('status', 'scheduling')->count());
    }

    public function test_two_managers_adding_the_tenth_document_of_a_type_at_once_get_one(): void
    {
        $f = $this->fixture();
        $org = $f['organisation']->id;
        foreach (range(1, 9) as $n) {
            DB::table('hr_interview_documents')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $org, 'position_type' => 'ca',
                'title' => "Synthetic document {$n}", 'stored_path' => "hr-interview-documents/{$org}/synthetic-{$n}.bin", 'bytes' => 10,
                'sha256' => hash('sha256', "synthetic-{$n}"), 'status' => 'active', 'uploaded_by_user_id' => $f['hr1']->id, 'uploaded_at' => now(),
                'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $typeLock = "hr_interview_documents:{$org}:ca";
        $out = $this->race(fn () => DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$typeLock]), [
            $this->worker($f['hr1'], ['op' => 'upload', 'position_type' => 'ca', 'title' => 'Synthetic tenth A', 'marker' => 'tenth-a']),
            $this->worker($f['hr2'], ['op' => 'upload', 'position_type' => 'ca', 'title' => 'Synthetic tenth B', 'marker' => 'tenth-b']),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS upload'), $out);
        $this->assertSame(1, substr_count($out, 'STALE file: This position type already has 10 documents.'), $out);
        $this->assertSame(10, DB::table('hr_interview_documents')->where('organisation_id', $org)->where('position_type', 'ca')->where('status', 'active')->count());
        $paths = DB::table('hr_interview_documents')->where('organisation_id', $org)->where('title', 'like', 'Synthetic tenth%')->pluck('stored_path');
        $this->assertCount(1, $paths);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_INT_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Interview Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'I'.Str::upper(Str::random(5)), 'name' => 'Synthetic Interview Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $hr1 = $this->user($organisation, $branch, [ApplicationPermissions::MANAGE]);
        DB::table('hr_branch_working_days')->insert(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'weekdays' => json_encode([1, 2, 3, 4, 5, 6, 7]),
            'updated_by_user_id' => $hr1->id, 'created_at' => now(), 'updated_at' => now()]);
        $position = DB::table('hr_job_positions')->insertGetId(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'title' => 'Synthetic Assistant',
            'position_type' => 'ca', 'branch_id' => $branch->id, 'is_open' => true, 'created_by_user_id' => $hr1->id, 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now()]);

        return ['organisation' => $organisation, 'branch' => $branch, 'position' => $position, 'hr1' => $hr1,
            'hr2' => $this->user($organisation, $branch, [ApplicationPermissions::MANAGE])];
    }

    /**
     * An application at Scheduling whose round-1 picks include $start, set up straight in the isolated database.
     *
     * @param  array<string, mixed>  $f
     * @return array{public_id:string, lock:int}
     */
    private function scheduledApplication(array $f, string $start): array
    {
        $publicId = (string) Str::uuid();
        $now = now()->utc();
        $id = DB::table('hr_job_applications')->insertGetId(['public_id' => $publicId, 'organisation_id' => $f['organisation']->id, 'position_id' => $f['position'],
            'status' => 'new', 'name_encrypted' => Crypt::encryptString('Synthetic Applicant'), 'phone_encrypted' => Crypt::encryptString('012-345 6789'),
            'email_encrypted' => Crypt::encryptString('applicant@example.test'), 'cv_path' => 'hr-applications/synthetic.bin', 'cv_bytes' => 10,
            'cv_sha256' => str_repeat('a', 64), 'privacy_notice_version' => 'draft-hr17-applications-v1', 'form_nonce' => bin2hex(random_bytes(16)),
            'submitted_at' => $now, 'stage_entered_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('hr_job_applications')->where('id', $id)->update(['status' => 'phone_screening', 'stage_entered_at' => $now]);
        DB::table('hr_job_screenings')->insert(['organisation_id' => $f['organisation']->id, 'application_id' => $id, 'ticked' => '[]', 'rating' => 4,
            'outcome' => 'pass', 'actor_user_id' => $f['hr1']->id, 'created_at' => $now]);
        DB::table('hr_job_applications')->where('id', $id)->update(['status' => 'scheduling', 'stage_entered_at' => $now, 'lock_version' => 3]);
        DB::table('hr_job_interview_picks')->insert(['organisation_id' => $f['organisation']->id, 'application_id' => $id, 'round' => 1,
            'starts' => json_encode([$start, '2030-01-08T03:00:00Z', '2030-01-08T04:00:00Z']), 'branch_id' => $f['branch']->id, 'created_at' => $now]);

        return ['public_id' => $publicId, 'lock' => 3];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Interview User '.Str::random(4), 'email' => 'interview.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresInterviewWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-interview-pg-'.Str::lower(Str::random(8))], base_path());
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
                    throw new RuntimeException('Interview worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Interview worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required interview contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected interview blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic hiring evidence.
            foreach (['hr_interview_document_events', 'hr_interview_documents', 'hr_job_application_messages', 'hr_job_interviews', 'hr_job_interview_picks', 'hr_job_application_events', 'hr_job_screenings',
                'hr_job_applications', 'hr_job_positions', 'hr_record_retries', 'hr_branch_working_days'] as $table) {
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
