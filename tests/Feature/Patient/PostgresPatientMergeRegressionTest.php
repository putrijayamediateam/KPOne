<?php

namespace Tests\Feature\Patient;

use App\Domain\Patient\Models\Patient;
use App\Domain\Patient\Models\PatientMergeRequest;
use App\Domain\Patient\Services\PatientMergeService;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\ContentionTimeouts;

/**
 * REG-8 (owner backlog 2026-11-06): two-person patient merge under real PostgreSQL contention. Two sessions decide
 * or propose on the same records at the same moment; PatientMergeService serialises them by locking both patients
 * in id order and then the request row. Each race holds a shared patient row in this test's session until both
 * workers are proven to be waiting behind it, then releases it, so they run one after the other in whichever
 * order PostgreSQL grants the lock.
 *
 * Races 1 to 3 are held by either the patient pair lock or the request row lock (removing both lets a second
 * approval hit the unique patient_merges.patient_merge_request_id); race 4 by the duplicate's patient lock (the
 * one-open-request index is the database backstop); race 5 by the shared record B in the pair lock.
 *
 * The fixture is built through the real service inside the RefreshDatabase transaction and then committed, so the
 * worker processes can see it; the next test rebuilds the schema.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPatientMergeRegressionTest extends VisitTestCase
{
    private const OBSERVER = 'pgsql_patient_merge_observer';

    private const CHANGED = 'This request has changed. Reload and review it again.';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL patient merge regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Patient merge concurrency tests require an isolated PostgreSQL test database.');
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
        DB::purge(self::OBSERVER);
        parent::tearDown();
    }

    public function test_two_approvers_approving_the_same_request_merge_once(): void
    {
        [$kept, $duplicate, $request] = $this->proposed();
        $out = $this->race($duplicate, [
            $this->worker('approve', $this->actor('ca_supervisor'), $this->decision($request)),
            $this->worker('approve', $this->actor('director'), $this->decision($request)),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS '.$request->id.' approved'), $out);
        $this->assertStringContainsString('STALE request: '.self::CHANGED, $out);
        $this->assertSame($kept->id, $duplicate->refresh()->merged_into_patient_id);
        $this->assertSame(1, DB::table('patient_merges')->where('patient_merge_request_id', $request->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'patient.merged')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'patient_merge.approved')->count());
    }

    public function test_an_approval_and_a_rejection_at_once_record_exactly_one_decision(): void
    {
        [$kept, $duplicate, $request] = $this->proposed();
        $out = $this->race($duplicate, [
            $this->worker('approve', $this->actor('ca_supervisor'), $this->decision($request)),
            $this->worker('reject', $this->actor('director'), $this->decision($request)),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS '), $out);
        $this->assertStringContainsString('STALE request: '.self::CHANGED, $out);
        $status = $request->refresh()->status;
        $this->assertContains($status, [PatientMergeRequest::STATUS_APPROVED, PatientMergeRequest::STATUS_REJECTED]);
        // The records are merged if and only if the approval won.
        $merged = $status === PatientMergeRequest::STATUS_APPROVED;
        $this->assertSame($merged ? $kept->id : null, $duplicate->refresh()->merged_into_patient_id);
        $this->assertSame($merged ? 1 : 0, DB::table('patient_merges')->count());
        $this->assertSame(1, DB::table('audit_logs')->whereIn('event', ['patient_merge.approved', 'patient_merge.rejected'])->count());
    }

    public function test_an_approval_and_the_proposer_withdrawing_at_once_record_exactly_one_outcome(): void
    {
        $ca = $this->actor('ca');
        [$kept, $duplicate, $request] = $this->proposed($ca);
        $out = $this->race($duplicate, [
            $this->worker('approve', $this->actor('ca_supervisor'), $this->decision($request)),
            $this->worker('withdraw', $ca, $this->decision($request)),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS '), $out);
        $this->assertStringContainsString('STALE request: '.self::CHANGED, $out);
        $status = $request->refresh()->status;
        $this->assertContains($status, [PatientMergeRequest::STATUS_APPROVED, PatientMergeRequest::STATUS_WITHDRAWN]);
        $merged = $status === PatientMergeRequest::STATUS_APPROVED;
        $this->assertSame($merged ? $kept->id : null, $duplicate->refresh()->merged_into_patient_id);
        $this->assertSame($merged ? 1 : 0, DB::table('patient_merges')->count());
    }

    public function test_two_proposals_for_the_same_duplicate_at_once_leave_one_open_request(): void
    {
        $duplicate = $this->patient();
        [$first, $second] = [$this->patient(), $this->patient()];
        $this->selectBranch($ca = $this->actor('ca'));
        $out = $this->race($duplicate, [
            $this->worker('propose', $ca, $this->proposal($first, $duplicate)),
            $this->worker('propose', $this->actor('ca_supervisor'), $this->proposal($second, $duplicate)),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS '), $out);
        $this->assertStringContainsString('STALE other_patient_number: A merge request for this patient is already waiting for approval.', $out);
        $this->assertSame(1, PatientMergeRequest::query()->where('merged_patient_id', $duplicate->id)->where('status', PatientMergeRequest::STATUS_PROPOSED)->count());
    }

    public function test_approving_a_into_b_and_b_into_c_at_once_never_leaves_a_chain(): void
    {
        $ca = $this->actor('ca');
        [$a, $b, $c] = [$this->patient(), $this->patient(), $this->patient()];
        $bIntoA = $this->propose($ca, $a, $b);
        $cIntoB = $this->propose($ca, $b, $c);
        $out = $this->race($b, [
            $this->worker('approve', $this->actor('ca_supervisor'), $this->decision($bIntoA)),
            $this->worker('approve', $this->actor('director'), $this->decision($cIntoB)),
        ]);

        // Both requests share record B, so they run one after the other and the second sees what the first did.
        $this->assertSame(1, substr_count($out, ' approved'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        // B into A first raises B's version, so C into B is refused as changed; C into B first leaves a record
        // merged into B, so B into A is refused as a chain. Any other refusal (an allergy or a visit) fails here.
        $this->assertMatchesRegularExpression(
            '/STALE \w+: (One of the patient records changed after this request was made\.|Other records are already merged into this duplicate\.)/',
            $out,
        );
        $this->assertSame(1, DB::table('patient_merges')->count());
        $chains = DB::table('patients as child')
            ->join('patients as parent', 'parent.id', '=', 'child.merged_into_patient_id')
            ->whereNotNull('parent.merged_into_patient_id')->count();
        $this->assertSame(0, $chains);
        $this->assertSame(1, Patient::query()->whereKey([$a->id, $b->id, $c->id])->whereNotNull('merged_into_patient_id')->count());
    }

    /**
     * A kept record, a duplicate and a waiting merge request between them, proposed by a CA.
     *
     * @return array{0: Patient, 1: Patient, 2: PatientMergeRequest}
     */
    private function proposed(?User $proposer = null): array
    {
        [$kept, $duplicate] = [$this->patient(), $this->patient()];

        return [$kept, $duplicate, $this->propose($proposer ?? $this->actor('ca'), $kept, $duplicate)];
    }

    private function propose(User $actor, Patient $kept, Patient $other): PatientMergeRequest
    {
        $this->selectBranch($actor);

        return app(PatientMergeService::class)->propose($actor, $kept->refresh(), $this->proposal($kept, $other));
    }

    /** @return array<string, mixed> */
    private function proposal(Patient $kept, Patient $other): array
    {
        return [
            'kind' => PatientMergeRequest::KIND_MERGE,
            'kept_patient_id' => $kept->id,
            'other_patient_number' => $other->patient_number,
            'reason_code' => 'same_person_registered_twice',
            'expected_branch_id' => $this->branch->id,
            'kept_lock_version' => $kept->refresh()->lock_version,
            'other_lock_version' => $other->refresh()->lock_version,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function decision(PatientMergeRequest $request): array
    {
        return ['request_id' => $request->id, 'lock_version' => $request->refresh()->lock_version];
    }

    /** Makes the fixture visible to the worker processes; the next test rebuilds the schema. */
    private function commitFixture(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
            RefreshDatabaseState::$migrated = false;
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return Worker
     */
    private function worker(string $mode, User $actor, array $args): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPatientMergeWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-merge-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the shared patient row the service locks, starts both workers, proves each one is waiting behind this
     * session (directly or behind the other worker), then releases the lock so they run strictly one after the other.
     *
     * @param  list<Worker>  $workers
     */
    private function race(Patient $shared, array $workers): string
    {
        $this->commitFixture();
        DB::beginTransaction();
        try {
            DB::select('select id from patients where id = ? for update', [$shared->id]);
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
                    throw new RuntimeException('Patient merge worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Patient merge worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required patient merge contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected patient merge blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }
}
