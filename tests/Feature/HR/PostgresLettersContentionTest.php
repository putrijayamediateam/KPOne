<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Letters\LetterPermissions;
use App\Domain\HR\Letters\Services\LetterService;
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
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/**
 * HR-10a under real PostgreSQL contention. Two letters issued at the same moment queue on the year's counter and take two
 * numbers, never one; two replacements of one letter record one replacement. Each race holds the row in this test's
 * session until both workers are proven to be waiting.
 *
 * @phpstan-type WorkerProcess array{process: Process, input: InputStream}
 */
class PostgresLettersContentionTest extends TestCase
{
    private const OBSERVER = 'pgsql_letters_observer';

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
            $this->markTestSkipped('PostgreSQL letter regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Letter concurrency tests require an isolated PostgreSQL test database.');
        }
        if (! Schema::hasTable('hr_letters')) {
            throw new RuntimeException('Migrate the isolated PostgreSQL test database before letter regressions.');
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

    public function test_two_letters_issued_at_once_take_two_numbers(): void
    {
        $f = $this->fixture();
        $this->issue($f['hr'], $f);
        $counter = (int) DB::table('hr_letter_counters')->where('organisation_id', $f['organisation']->id)->value('id');

        $out = $this->race('hr_letter_counters', $counter, [$this->worker($f['hr'], $this->input($f)), $this->worker($f['hr2'], $this->input($f))]);

        $this->assertSame(2, substr_count($out, 'SUCCESS HRL/'), $out);
        $references = DB::table('hr_letters')->where('organisation_id', $f['organisation']->id)->orderBy('number')->pluck('reference')->all();
        $year = (string) DB::table('hr_letter_counters')->where('id', $counter)->value('year');
        $this->assertSame(["HRL/{$year}/000001", "HRL/{$year}/000002", "HRL/{$year}/000003"], $references);
        $this->assertSame(3, (int) DB::table('hr_letter_counters')->where('id', $counter)->value('last_number'));
    }

    public function test_two_replacements_of_one_letter_record_one_replacement(): void
    {
        $f = $this->fixture();
        $original = $this->issue($f['hr'], $f);
        $letterId = (int) DB::table('hr_letters')->where('public_id', $original)->value('id');

        $out = $this->race('hr_letters', $letterId, [
            $this->worker($f['hr'], $this->input($f, ['replaces' => $original])),
            $this->worker($f['hr2'], $this->input($f, ['replaces' => $original])),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS HRL/'), $out);
        $this->assertSame(1, substr_count($out, 'STALE replaces: This letter is void, so it cannot be replaced. Issue a new letter.'), $out);
        $this->assertSame(1, DB::table('hr_letters')->where('replaces_letter_id', $letterId)->count());
        $this->assertSame('replaced', DB::table('hr_letters')->where('id', $letterId)->value('void_reason'));
        // The refused replacement used no number.
        $this->assertSame(2, (int) DB::table('hr_letter_counters')->where('organisation_id', $f['organisation']->id)->value('last_number'));
    }

    /** @param array<string, mixed> $f */
    private function issue(User $actor, array $f): string
    {
        return app(LetterService::class)->issue($actor, $this->input($f));
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $f, array $overrides = []): array
    {
        return ['worker_id' => $f['worker'], 'template_id' => $f['template'], 'language' => 'en', 'expected_template_version' => 1,
            'idempotency_key' => (string) Str::uuid(), ...$overrides];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'HR_LTR_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Letter Organisation', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'L'.Str::upper(Str::random(5)), 'name' => 'Synthetic Letter Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        DB::table('branch_business_profiles')->insert(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'clinic_name' => 'Synthetic Letter Clinic',
            'address_line_1' => '1 Example Street', 'postcode' => '50000', 'city' => 'Example City', 'state' => 'Example State', 'country' => 'MY',
            'phone' => '+60320000001', 'email' => 'letters@clinic.test', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $manage = [LetterPermissions::ISSUE, RecordPermissions::MANAGE, RecordPermissions::VIEW_ORGANISATION];
        $hr = $this->user($organisation, $branch, $manage);
        $hr2 = $this->user($organisation, $branch, $manage);
        $staff = $this->user($organisation, $branch, [RecordPermissions::VIEW_OWN], 'ca');
        $worker = app(StaffFileService::class)->openFile($hr, ['user_id' => $staff->id, 'idempotency_key' => (string) Str::uuid()]);
        $template = app(LetterService::class)->createTemplate($hr, ['type' => 'confirmation', 'title' => 'Synthetic confirmation',
            'body_en' => 'Dear {name}, this synthetic letter {reference} confirms your appointment.', 'body_bm' => 'Kepada {name}, surat sintetik {reference} ini mengesahkan pelantikan anda.',
            'idempotency_key' => (string) Str::uuid()]);

        return ['organisation' => $organisation, 'branch' => $branch, 'hr' => $hr, 'hr2' => $hr2, 'staff' => $staff, 'worker' => $worker->public_id, 'template' => $template];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, array $permissions, ?string $role = null): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Letter User '.Str::random(4), 'email' => 'letters.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresLettersWorker.php'), (string) $actor->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-letters-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds a row (the year's counter, or a letter), starts both workers, proves each is waiting behind this session,
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
                    throw new RuntimeException('Letter worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Letter worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required letter contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected letter blocker chain was not observed. '.$this->processDiagnostics($process));
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
            // Only this isolated PostgreSQL test fixture cleanup may discard retained synthetic letter evidence.
            foreach (['hr_letter_opens', 'hr_letters', 'hr_letter_template_versions', 'hr_letter_templates', 'hr_letter_counters', 'hr_record_changes', 'hr_record_retries'] as $table) {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
                if ($table === 'hr_letters') {
                    DB::table($table)->where('organisation_id', $id)->update(['replaces_letter_id' => null]);
                }
                DB::table($table)->where('organisation_id', $id)->delete();
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
            }
            DB::statement('ALTER TABLE hr_workers DISABLE TRIGGER USER');
            DB::table('hr_workers')->where('organisation_id', $id)->delete();
            DB::statement('ALTER TABLE hr_workers ENABLE TRIGGER USER');
            DB::table('branch_business_profiles')->where('organisation_id', $id)->delete();
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
