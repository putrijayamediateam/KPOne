<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPackDocument;
use App\Domain\Visit\Billing\Models\PanelClaimPackPrint;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ContentionTimeouts;

/**
 * PNL-25: two recorded prints of one Panel claim pack at the same moment under real PostgreSQL contention. Both
 * wait behind the pack's advisory lock, so exactly one freezes version 1 (ORIGINAL) and the other is a COPY of it;
 * the same idempotency key sent twice records one print.
 *
 * The fixture is built through the real screens inside the RefreshDatabase transaction and then committed, so
 * the worker processes can see it; the next test rebuilds the schema.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPanelClaimPackIssueRaceTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    private const OBSERVER = 'pgsql_panel_claim_observer';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private ?User $secondOfficer = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Panel claim regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Panel claim concurrency tests require an isolated PostgreSQL test database.');
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

    public function test_two_people_printing_the_same_pack_at_once_freeze_one_version_and_one_original(): void
    {
        $ids = $this->claimIds(2);
        $out = $this->race($ids, [
            $this->worker($this->officer(), $this->args($ids)),
            $this->worker($this->colleague(), $this->args($ids)),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS original'), $out);
        $this->assertSame(1, substr_count($out, 'SUCCESS copy'), $out);
        $this->assertSame(1, PanelClaimPackDocument::query()->count());
        $this->assertSame(1, PanelClaimPackDocument::query()->value('version'));
        $this->assertSame(2, PanelClaimPackPrint::query()->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.pack_issued')->count());
    }

    public function test_the_same_idempotency_key_sent_twice_at_once_records_one_print(): void
    {
        $ids = $this->claimIds(1);
        $args = $this->args($ids);
        $out = $this->race($ids, [
            $this->worker($this->officer(), $args),
            $this->worker($this->officer(), $args),
        ]);

        $this->assertSame(2, substr_count($out, 'SUCCESS '), $out);
        $this->assertSame(1, PanelClaimPackDocument::query()->count());
        $this->assertSame(1, PanelClaimPackPrint::query()->count());
    }

    /** @return list<int> */
    private function claimIds(int $count): array
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            [$allocation, $invoice] = $this->approvedAllocation($panel);
            $this->selectBranch($this->officer(), $this->branch);
            $this->post(route('panel-claims.store', $allocation), [
                'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
                'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
                'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            ])->assertSessionHasNoErrors();
            $ids[] = (int) PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->value('id');
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, mixed>
     */
    private function args(array $ids): array
    {
        return ['claims' => PanelClaim::query()->whereIn('id', $ids)->orderBy('id')->pluck('public_id')->all(), 'idempotency_key' => (string) Str::uuid()];
    }

    private function colleague(): User
    {
        return $this->secondOfficer ??= $this->actor('panel_officer');
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
    private function worker(User $actor, array $args): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPanelClaimPackWorker.php'), (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-panel-claim-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the pack's advisory lock, starts both workers, proves each one is waiting behind this
     * session, then releases the lock so they run strictly one after the other. With $inOrder, each worker is
     *
     * @param  list<Worker>  $workers
     */
    private function race(array $claimIds, array $workers): string
    {
        $this->commitFixture();
        DB::beginTransaction();
        try {
            $sorted = $claimIds;
            sort($sorted);
            $packKey = hash('sha256', json_encode($sorted, JSON_THROW_ON_ERROR));
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$this->organisation->id.':'.$this->branch->id.':'.$packKey]);
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
                    throw new RuntimeException('Panel claim worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Panel claim worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required Panel claim contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected Panel claim blocker chain was not observed. '.$this->processDiagnostics($process));
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
