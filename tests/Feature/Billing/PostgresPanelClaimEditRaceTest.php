<?php

namespace Tests\Feature\Billing;

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
 * PNL-22 (Panel pilot work): editing a Panel claim against a Panel decision or a payment, under real PostgreSQL
 * contention. Two sessions act on the same claim at the same moment; the service serialises them with a row lock on
 * the claim and a lock_version check. Each race holds that claim row in this test's session until both workers are
 * proven to be waiting behind it, then releases it, so they run one after the other (in list order when asked).
 *
 * The fixture is built through the real screens inside the RefreshDatabase transaction and then committed, so
 * the worker processes can see it; the next test rebuilds the schema.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPanelClaimEditRaceTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    private const OBSERVER = 'pgsql_panel_payment_observer';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Panel payment regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Panel payment concurrency tests require an isolated PostgreSQL test database.');
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

    public function test_a_decision_that_runs_first_makes_the_queued_amount_edit_stale(): void
    {
        $claim = $this->submittedClaim();
        $v = $claim->lock_version;
        $out = $this->race($claim, [
            $this->worker('claim-decision', $this->officer(), ['claim' => $claim->public_id, 'lock_version' => $v,
                'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision']),
            $this->worker('claim-adjust', $this->officer(), ['claim' => $claim->public_id, 'lock_version' => $v,
                'amount_sen' => 2000, 'reason' => 'Synthetic: Panel agreed a lower amount']),
        ], inOrder: true);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertStringContainsString('STALE claim: This claim has changed. Reload it before continuing.', $out);
        $claim->refresh();
        $this->assertSame('accepted', $claim->status);
        $this->assertSame(3000, $claim->claim_amount_sen);
        $this->assertSame(3000, $claim->accepted_amount_sen);
        $this->assertSame(0, DB::table('panel_claim_events')->where('panel_claim_id', $claim->id)->where('event_type', 'amount_adjusted')->count());
    }

    public function test_an_amount_edit_that_runs_first_makes_the_queued_decision_stale(): void
    {
        $claim = $this->submittedClaim();
        $v = $claim->lock_version;
        $out = $this->race($claim, [
            $this->worker('claim-adjust', $this->officer(), ['claim' => $claim->public_id, 'lock_version' => $v,
                'amount_sen' => 2000, 'reason' => 'Synthetic: Panel agreed a lower amount']),
            $this->worker('claim-decision', $this->officer(), ['claim' => $claim->public_id, 'lock_version' => $v,
                'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision']),
        ], inOrder: true);

        // The decision was made on the RM 30.00 the officer saw, so it must not accept an amount that no longer exists.
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertStringContainsString('STALE claim: This claim has changed. Reload it before continuing.', $out);
        $claim->refresh();
        $this->assertSame('submitted', $claim->status);
        $this->assertSame(2000, $claim->claim_amount_sen);
        $this->assertNull($claim->accepted_amount_sen);
        $this->assertSame(0, DB::table('panel_claim_events')->where('panel_claim_id', $claim->id)->where('event_type', 'decision_accepted')->count());
    }

    public function test_a_decision_and_an_amount_edit_at_once_never_leave_an_accepted_amount_different_from_the_claim(): void
    {
        $claim = $this->submittedClaim();
        $v = $claim->lock_version;
        $out = $this->race($claim, [
            $this->worker('claim-adjust', $this->officer(), ['claim' => $claim->public_id, 'lock_version' => $v,
                'amount_sen' => 2000, 'reason' => 'Synthetic: Panel agreed a lower amount']),
            $this->worker('claim-decision', $this->officer(), ['claim' => $claim->public_id, 'lock_version' => $v,
                'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision']),
        ]);

        // Whichever order PostgreSQL grants the lock, exactly one is applied and the other is refused as stale.
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE claim:'), $out);
        $claim->refresh();
        $this->assertSame($v + 1, $claim->lock_version);
        $this->assertTrue($claim->accepted_amount_sen === null || $claim->accepted_amount_sen === $claim->claim_amount_sen);
    }

    public function test_an_amount_edit_and_a_payment_at_once_cannot_change_an_accepted_claim(): void
    {
        $claim = $this->submittedClaim(accept: true);
        $out = $this->race($claim, [
            $this->worker('claim-adjust', $this->officer(), ['claim' => $claim->public_id, 'lock_version' => $claim->lock_version,
                'amount_sen' => 2000, 'reason' => 'Synthetic: late amount change']),
            $this->worker('claim-payment', $this->officer(), ['claim' => $claim->public_id, 'amount_sen' => 3000]),
        ]);

        // An accepted claim is not editable, so the edit is refused whether it runs before or after the payment.
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $claim->refresh();
        $this->assertSame(3000, $claim->claim_amount_sen);
        $this->assertSame(3000, $claim->accepted_amount_sen);
        $this->assertSame(3000, (int) $claim->receivedPayments()->sum('amount_sen'));
        $this->assertSame('paid', $claim->status);
        $this->assertSame(0, DB::table('panel_claim_events')->where('panel_claim_id', $claim->id)->where('event_type', 'amount_adjusted')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'panel_claim.amount_adjusted')->count());
    }

    /** One Submitted claim of RM 30.00, optionally already accepted in full by its Panel. */
    private function submittedClaim(bool $accept = false): PanelClaim
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $today = CarbonImmutable::now($this->branch->timezone)->toDateString();
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(), 'submitted_date' => $today,
        ])->assertSessionHasNoErrors();
        $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
        if ($accept) {
            $this->post(route('panel-claims.decision', $claim), [
                'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version,
                'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision',
            ])->assertSessionHasNoErrors();
        }

        return $claim->refresh();
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
        $args = ['idempotency_key' => (string) Str::uuid(), 'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'reference' => null, ...$args];
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPanelPaymentWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-panel-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the claim row the services lock, starts both workers, proves each one is waiting behind this session,
     * then releases the lock so they run strictly one after the other. With $inOrder, each worker is started only
     * after the one before it is proven to be waiting, so they queue for the claim lock, and run, in list order.
     *
     * @param  list<Worker>  $workers
     */
    private function race(PanelClaim $claim, array $workers, bool $inOrder = false): string
    {
        $this->commitFixture();
        DB::beginTransaction();
        try {
            DB::select('select id from panel_claims where id = ? for update', [$claim->id]);
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
                    throw new RuntimeException('Panel payment worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Panel payment worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required Panel payment contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected Panel payment blocker chain was not observed. '.$this->processDiagnostics($process));
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
