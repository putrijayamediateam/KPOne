<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use App\Domain\Visit\Billing\Models\PanelRemittance;
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
 * PNL-5 (owner backlog 2026-11-05): Panel claim payments under real PostgreSQL contention. Two sessions pay or
 * reverse on the same claim at the same moment; the services serialise them with a row lock on the claim. Each
 * race holds that claim row in this test's session until both workers are proven to be waiting behind it, then
 * releases it, so they run one after the other in whichever order PostgreSQL grants the lock.
 *
 * The fixture is built through the real screens inside the RefreshDatabase transaction and then committed, so
 * the worker processes can see it; the next test rebuilds the schema.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPanelPaymentRegressionTest extends BillingTestCase
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

    public function test_a_split_payment_and_a_single_claim_payment_cannot_both_pay_the_full_amount(): void
    {
        [$panel, $claim] = $this->acceptedClaim();
        $out = $this->race($claim, [
            $this->worker('claim-payment', $this->officer(), ['claim' => $claim->public_id, 'amount_sen' => 3000]),
            $this->worker('split-payment', $this->finance(), ['panel' => $panel->code, 'amount_sen' => 3000,
                'splits' => [['claim' => $claim->public_id, 'amount_sen' => 3000]]]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        // Whichever ran second found the claim already paid in full, so it was refused (the wording depends on which).
        $this->assertMatchesRegularExpression('/has no accepted amount waiting for payment|can only be recorded after an accepted decision/', $out);
        $this->assertSame(3000, $this->received($claim));
        $this->assertSame('paid', $claim->refresh()->status);
    }

    public function test_two_part_payments_racing_stay_within_the_accepted_amount(): void
    {
        [$panel, $claim] = $this->acceptedClaim();
        $out = $this->race($claim, [
            $this->worker('claim-payment', $this->officer(), ['claim' => $claim->public_id, 'amount_sen' => 2000]),
            $this->worker('split-payment', $this->finance(), ['panel' => $panel->code, 'amount_sen' => 2000,
                'splits' => [['claim' => $claim->public_id, 'amount_sen' => 2000]]]),
        ]);

        // Whichever runs first leaves RM 10.00 unpaid, so the second is refused rather than over-paying.
        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $this->assertSame(2000, $this->received($claim));
        $this->assertSame('partially_paid', $claim->refresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.payment_received')->count());
    }

    public function test_a_reversal_racing_a_new_payment_leaves_the_claim_status_true(): void
    {
        [, $claim] = $this->acceptedClaim(paid: 3000);
        $paid = (string) DB::table('panel_claim_payments')->where('panel_claim_id', $claim->id)->value('public_id');
        $this->commitFixture();
        $out = $this->race($claim, [
            $this->worker('reverse', $this->finance(), ['kind' => 'payment', 'target' => $paid, 'reason_code' => 'wrong_amount',
                'note' => 'Synthetic: wrong amount', 'recording_error_only' => true]),
            $this->worker('claim-payment', $this->officer(), ['claim' => $claim->public_id, 'amount_sen' => 1000]),
        ]);

        // The reversal always succeeds. The new payment succeeds only if it ran after the reversal; before it,
        // nothing was left unpaid. Either way the claim's status matches what it has still received.
        $this->assertStringContainsString('SUCCESS reversed:1', $out);
        $this->assertSame(1, PanelClaimPaymentReversal::query()->count());
        $claim->refresh();
        if (substr_count($out, 'SUCCESS') === 2) {
            $this->assertSame(1000, $this->received($claim));
            $this->assertSame('partially_paid', $claim->status);
        } else {
            $this->assertStringContainsString('exceeds the accepted amount still outstanding', $out);
            $this->assertSame(0, $this->received($claim));
            $this->assertSame('accepted', $claim->status);
        }
    }

    public function test_two_people_reversing_the_same_payment_record_one_reversal(): void
    {
        [, $claim] = $this->acceptedClaim(paid: 3000);
        $paid = (string) DB::table('panel_claim_payments')->where('panel_claim_id', $claim->id)->value('public_id');
        $reversal = ['kind' => 'payment', 'target' => $paid, 'reason_code' => 'recorded_twice', 'note' => 'Synthetic: entered twice', 'recording_error_only' => true];
        $this->commitFixture();
        $out = $this->race($claim, [
            $this->worker('reverse', $this->finance(), $reversal),
            $this->worker('reverse', $this->actor('director'), $reversal),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertStringContainsString('STALE reversal: This payment has already been reversed.', $out);
        $this->assertSame(1, PanelClaimPaymentReversal::query()->count());
        $this->assertSame('accepted', $claim->refresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.payment_reversed')->count());
    }

    public function test_the_same_split_payment_sent_twice_at_once_is_recorded_once(): void
    {
        [$panel, $claim] = $this->acceptedClaim();
        $args = ['panel' => $panel->code, 'amount_sen' => 1500, 'idempotency_key' => (string) Str::uuid(),
            'splits' => [['claim' => $claim->public_id, 'amount_sen' => 1500]]];
        $finance = $this->finance();
        $out = $this->race($claim, [$this->worker('split-payment', $finance, $args), $this->worker('split-payment', $finance, $args)]);

        $remittance = PanelRemittance::query()->sole();
        // Both calls answered with the same record: the second returned the first instead of paying again.
        $this->assertSame(2, substr_count($out, 'SUCCESS '.$remittance->public_id), $out);
        $this->assertSame(1500, $this->received($claim));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_remittance.recorded')->count());
    }

    /**
     * One Submitted claim of RM 30.00 accepted in full by its Panel, optionally already paid by the Panel Officer.
     *
     * @return array{0: Panel, 1: PanelClaim}
     */
    private function acceptedClaim(int $paid = 0): array
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
        $this->post(route('panel-claims.decision', $claim), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version,
            'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision',
        ])->assertSessionHasNoErrors();
        if ($paid > 0) {
            $this->post(route('panel-claims.payment', $claim), [
                'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
                'amount_sen' => $paid, 'received_date' => $today,
            ])->assertSessionHasNoErrors();
        }
        $this->finance();

        return [$panel, $claim->refresh()];
    }

    private ?User $financeOfficer = null;

    private function finance(): User
    {
        return $this->financeOfficer ??= $this->actor('finance_officer');
    }

    private function received(PanelClaim $claim): int
    {
        return (int) $claim->receivedPayments()->sum('amount_sen');
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
     * then releases the lock so they run strictly one after the other.
     *
     * @param  list<Worker>  $workers
     */
    private function race(PanelClaim $claim, array $workers): string
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
