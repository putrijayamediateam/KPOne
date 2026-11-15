<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\PanelClaimAdjustmentService;
use App\Domain\Visit\Billing\Services\PanelShare;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
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
 * PCA-2 (design note v3.2): a Panel claim adjustment confirmation under real PostgreSQL contention with a claim decision,
 * a patient payment and a second confirmation. Each race holds the row both sessions lock (the claim, or the patient,
 * first in the shared lock order) until both workers are proven to be waiting behind it, then releases it, in list order.
 *
 * The fixture is built through the real screens inside the RefreshDatabase transaction and then committed, so
 * the worker processes can see it; the next test rebuilds the schema.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPanelAdjustmentRegressionTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    private const OBSERVER = 'pgsql_panel_adjustment_observer';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Panel adjustment regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Panel adjustment concurrency tests require an isolated PostgreSQL test database.');
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

    public function test_a_confirmation_queued_before_a_decision_resyncs_the_claim_and_the_stale_decision_is_refused(): void
    {
        [$claim, $adjustment] = $this->submittedClaimWithRequest();
        $out = $this->race('select id from panel_claims where id = ? for update', $claim->id, [
            $this->worker('confirm', $this->officer(), ['adjustment' => $adjustment->public_id, 'lock_version' => 1]),
            $this->worker('decision', $this->finance(), ['claim' => $claim->public_id, 'lock_version' => $claim->lock_version,
                'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic decision']),
        ]);

        $this->assertStringContainsString('SUCCESS '.$adjustment->public_id, $out);
        $this->assertStringContainsString('STALE claim: This claim has changed.', $out);
        $this->assertSame(['submitted', 8000], [$claim->refresh()->status, $claim->claim_amount_sen]);
    }

    public function test_a_decision_queued_before_a_confirmation_stands_and_the_confirmation_is_refused(): void
    {
        [$claim, $adjustment] = $this->submittedClaimWithRequest();
        $out = $this->race('select id from panel_claims where id = ? for update', $claim->id, [
            $this->worker('decision', $this->finance(), ['claim' => $claim->public_id, 'lock_version' => $claim->lock_version,
                'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic decision']),
            $this->worker('confirm', $this->officer(), ['adjustment' => $adjustment->public_id, 'lock_version' => 1]),
        ]);

        $this->assertStringContainsString('SUCCESS '.$claim->public_id, $out);
        $this->assertStringContainsString('STALE adjustment: This claim was decided or paid after the request.', $out);
        $this->assertSame(['accepted', 3000], [$claim->refresh()->status, $claim->claim_amount_sen]);
        $this->assertSame('requested', $adjustment->refresh()->status);
    }

    public function test_a_confirmation_queued_before_a_payment_makes_the_open_payment_form_reload(): void
    {
        [$visit, $invoice, $allocation, $adjustment] = $this->unpaidVisitWithRequest();
        $out = $this->race('select id from patients where id = ? for update', $visit->patient_id, [
            $this->worker('confirm', $this->officer(), ['adjustment' => $adjustment->public_id, 'lock_version' => 1]),
            $this->worker('payment', $this->ca(), ['visit' => $visit->visit_number, 'invoice' => $invoice->public_id, 'lock_version' => $invoice->lock_version,
                'amount_sen' => '1000', 'method' => 'cash']),
        ]);

        $this->assertStringContainsString('SUCCESS '.$adjustment->public_id, $out);
        $this->assertSame(1, substr_count($out, 'STALE '), $out);
        $this->assertSame(['total' => 9000, 'self_pay' => 0, 'panel' => 8000, 'deferred' => 0, 'due_now' => 1000], app(FinancialLedger::class)->state($invoice->refresh()));
        $this->assertSame(8000, PanelShare::effective($allocation->refresh()));
    }

    public function test_a_payment_queued_before_a_confirmation_and_the_confirmation_both_apply_and_the_patient_owes_nothing_more(): void
    {
        [$visit, $invoice, $allocation, $adjustment] = $this->unpaidVisitWithRequest();
        $out = $this->race('select id from patients where id = ? for update', $visit->patient_id, [
            $this->worker('payment', $this->ca(), ['visit' => $visit->visit_number, 'invoice' => $invoice->public_id, 'lock_version' => $invoice->lock_version,
                'amount_sen' => '1000', 'method' => 'cash']),
            $this->worker('confirm', $this->officer(), ['adjustment' => $adjustment->public_id, 'lock_version' => 1]),
        ]);

        $this->assertSame(2, substr_count($out, 'SUCCESS '), $out);
        $this->assertSame(['total' => 9000, 'self_pay' => 1000, 'panel' => 8000, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($invoice->refresh()));
        $this->assertSame(8000, PanelShare::effective($allocation->refresh()));
    }

    public function test_two_people_confirming_the_same_request_record_one_confirmation(): void
    {
        [$claim, $adjustment] = $this->submittedClaimWithRequest();
        $out = $this->race('select id from panel_claims where id = ? for update', $claim->id, [
            $this->worker('confirm', $this->officer(), ['adjustment' => $adjustment->public_id, 'lock_version' => 1]),
            $this->worker('confirm', $this->finance(), ['adjustment' => $adjustment->public_id, 'lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS'), $out);
        $this->assertStringContainsString('STALE adjustment: This record has changed. Reload and review it again.', $out);
        $this->assertSame(8000, $claim->refresh()->claim_amount_sen);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim_adjustment.confirmed')->count());
    }

    /**
     * A Submitted RM 30.00 claim on an approved RM 30.00 Panel share, with a +RM 50.00 adjustment waiting.
     *
     * @return array{0: PanelClaim, 1: PanelClaimAdjustment}
     */
    private function submittedClaimWithRequest(): array
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
        $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
        $adjustment = $this->requestAdjustment($allocation, $invoice);
        $this->finance();

        return [$claim->refresh(), $adjustment];
    }

    /**
     * A registered visit: RM 40.00 invoice, RM 30.00 approved Panel share, RM 10.00 still due, a +RM 50.00 adjustment waiting.
     *
     * @return array{0: Visit, 1: Invoice, 2: CoverageAllocation, 3: PanelClaimAdjustment}
     */
    private function unpaidVisitWithRequest(): array
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $adjustment = $this->requestAdjustment($allocation, $invoice);
        $this->ca();

        return [Visit::query()->findOrFail($invoice->visit_id), $invoice->refresh(), $allocation, $adjustment];
    }

    private function requestAdjustment(CoverageAllocation $allocation, Invoice $invoice): PanelClaimAdjustment
    {
        $this->selectBranch($this->officer(), $this->branch);

        return app(PanelClaimAdjustmentService::class)->request($this->officer(), $allocation, [
            'expected_branch_id' => $this->branch->id, 'invoice_lock_version' => $invoice->refresh()->lock_version, 'direction' => 'add',
            'amount_sen' => '5000', 'reason_code' => 'panel_service_charge', 'note' => null, 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private ?User $financeOfficer = null;

    private ?User $clinicAssistant = null;

    private function finance(): User
    {
        return $this->financeOfficer ??= $this->actor('finance_officer');
    }

    private function ca(): User
    {
        return $this->clinicAssistant ??= $this->actor('ca');
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
        $args = ['idempotency_key' => (string) Str::uuid(), ...$args];
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPanelAdjustmentWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-padj-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(ContentionTimeouts::processTimeoutSeconds());
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /**
     * Holds the row both sessions lock, starts both workers, proves each one is waiting behind this session, then
     * releases the lock. Each worker is released only after the one before it is proven to be waiting, so they queue
     * for the lock, and run, in list order.
     *
     * @param  list<Worker>  $workers
     */
    private function race(string $lockSql, int $key, array $workers, bool $inOrder = true): string
    {
        $this->commitFixture();
        DB::beginTransaction();
        try {
            DB::select($lockSql, [$key]);
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
                    throw new RuntimeException('Panel adjustment worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Panel adjustment worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required Panel adjustment contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected Panel adjustment blocker chain was not observed. '.$this->processDiagnostics($process));
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
