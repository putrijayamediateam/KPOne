<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
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
 * PNL-9 (B.E.R.N.I.S.S for the owner, 2026-11-05): PNL-2 bulk claim creation racing a single creation, or another
 * bulk creation, on the same approved Panel allocation under real PostgreSQL contention. Both paths lock the
 * invoice, then the allocation, and refuse an allocation that already has a claim, so only one claim is ever
 * created and a refused batch creates nothing (all-or-nothing). Closes the PNL-2 known limit.
 *
 * The fixture is built through the real screens inside the RefreshDatabase transaction and then committed, so
 * the worker processes can see it; the next test rebuilds the schema.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPanelBulkClaimRegressionTest extends BillingTestCase
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

    public function test_a_bulk_creation_and_a_single_creation_of_the_same_invoice_create_one_claim(): void
    {
        $panel = $this->panel();
        $pair = $this->approvedAllocation($panel);
        $out = $this->race($pair[1], [
            $this->worker('single', $this->officer(), $this->single($pair)),
            $this->worker('bulk', $this->colleague(), $this->bulk([$pair])),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS 1'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        // Whichever ran second found the claim already there (a batch names the invoice first).
        $this->assertStringContainsString('A claim pack already exists for this approved Panel allocation.', $out);
        $this->assertSame(1, PanelClaim::query()->where('coverage_allocation_id', $pair[0]->id)->count());
        $this->assertSame(2, DB::table('panel_claim_events')->count(), 'One claim: one prepared and one submitted event.');
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());
    }

    public function test_a_batch_queued_first_creates_every_claim_and_the_later_single_creation_is_refused(): void
    {
        $panel = $this->panel();
        $first = $this->approvedAllocation($panel);
        $shared = $this->approvedAllocation($panel);
        $out = $this->race($shared[1], [
            $this->worker('bulk', $this->colleague(), $this->bulk([$first, $shared])),
            $this->worker('single', $this->officer(), $this->single($shared)),
        ], inOrder: true);

        $this->assertStringContainsString('SUCCESS 2', $out);
        $this->assertStringContainsString('STALE claim: A claim pack already exists for this approved Panel allocation.', $out);
        $this->assertSame(2, PanelClaim::query()->count());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());
    }

    public function test_a_single_creation_queued_first_makes_the_whole_batch_refused_and_nothing_of_it_is_kept(): void
    {
        $panel = $this->panel();
        $first = $this->approvedAllocation($panel);
        $shared = $this->approvedAllocation($panel);
        $out = $this->race($shared[1], [
            $this->worker('single', $this->officer(), $this->single($shared)),
            $this->worker('bulk', $this->colleague(), $this->bulk([$first, $shared])),
        ], inOrder: true);

        $this->assertStringContainsString('SUCCESS 1', $out);
        $this->assertStringContainsString('STALE claims: Invoice '.$shared[1]->invoice_number.': A claim pack already exists for this approved Panel allocation.', $out);
        // All-or-nothing: the batch's other invoice was not claimed either.
        $this->assertSame(1, PanelClaim::query()->count());
        $this->assertSame(0, PanelClaim::query()->where('coverage_allocation_id', $first[0]->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());
    }

    public function test_two_overlapping_batches_create_each_claim_once(): void
    {
        $panel = $this->panel();
        $first = $this->approvedAllocation($panel);
        $shared = $this->approvedAllocation($panel);
        $last = $this->approvedAllocation($panel);
        $out = $this->race($shared[1], [
            $this->worker('bulk', $this->officer(), $this->bulk([$first, $shared])),
            $this->worker('bulk', $this->colleague(), $this->bulk([$shared, $last])),
        ]);

        // One batch wins; the other is refused as a whole, so its other invoice stays unclaimed.
        $this->assertSame(1, substr_count($out, 'SUCCESS 2'), $out);
        $this->assertSame(1, substr_count($out, 'STALE claims: Invoice '.$shared[1]->invoice_number.': A claim pack already exists'), $out);
        $this->assertSame(2, PanelClaim::query()->count());
        $this->assertSame(1, PanelClaim::query()->where('coverage_allocation_id', $shared[0]->id)->count());
        $this->assertSame(1, PanelClaim::query()->whereIn('coverage_allocation_id', [$first[0]->id, $last[0]->id])->count());
    }

    private function panel(): Panel
    {
        return Panel::factory()->create(['organisation_id' => $this->organisation->id]);
    }

    private function colleague(): User
    {
        return $this->secondOfficer ??= $this->actor('panel_officer');
    }

    /**
     * @param  array{0: CoverageAllocation, 1: Invoice}  $pair
     * @return array<string, mixed>
     */
    private function single(array $pair): array
    {
        return ['allocation' => $pair[0]->public_id, 'allocation_lock_version' => $pair[0]->refresh()->lock_version,
            'invoice_lock_version' => $pair[1]->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString()];
    }

    /**
     * @param  list<array{0: CoverageAllocation, 1: Invoice}>  $pairs
     * @return array<string, mixed>
     */
    private function bulk(array $pairs): array
    {
        return ['submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'remarks' => null,
            'claims' => array_map(static fn (array $pair): array => ['allocation' => $pair[0]->public_id,
                'allocation_lock_version' => $pair[0]->refresh()->lock_version, 'invoice_lock_version' => $pair[1]->refresh()->lock_version,
                'idempotency_key' => (string) Str::uuid()], $pairs)];
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPanelClaimCreationWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-panel-claim-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the invoice row both creations lock first, starts both workers, proves each one is waiting behind this
     * session, then releases the lock so they run strictly one after the other. With $inOrder, each worker is
     * released to start only after the one before it is proven to be waiting, so they run in list order.
     *
     * @param  list<Worker>  $workers
     */
    private function race(Invoice $invoice, array $workers, bool $inOrder = false): string
    {
        $this->commitFixture();
        DB::beginTransaction();
        try {
            DB::select('select id from invoices where id = ? for update', [$invoice->id]);
            $parentPid = (int) DB::scalar('select pg_backend_pid()');
            foreach ($workers as $worker) {
                $worker['process']->start();
            }
            $this->waitReady($workers);
            foreach ($workers as $worker) {
                $worker['input']->write("GO\n");
                $worker['input']->close();
                if ($inOrder) {
                    $this->waitForDatabaseBlock($worker['process'], $parentPid);
                }
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
