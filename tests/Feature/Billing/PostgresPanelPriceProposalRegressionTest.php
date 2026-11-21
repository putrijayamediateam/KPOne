<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
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
use Tests\Feature\Clinical\ClinicalTestCase;
use Tests\Support\ContentionTimeouts;

/**
 * Panel price changes by proposal under real PostgreSQL contention: two approvers on one proposal, an approval against a
 * withdrawal in both orders, and two proposals for one Panel book and charge. Each race holds the row both sessions lock
 * until both workers are proven to be waiting behind it, then releases it, in list order.
 *
 * @phpstan-type Worker array{process: Process, input: InputStream}
 */
class PostgresPanelPriceProposalRegressionTest extends ClinicalTestCase
{
    private const OBSERVER = 'pgsql_panel_price_proposal_observer';

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private User $officer;

    private User $finance;

    private User $director;

    private PriceBook $book;

    private ChargeDefinition $charge;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Panel price proposal regressions require DB_CONNECTION=pgsql.');
        }
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', (string) DB::connection()->getDatabaseName()) !== 1) {
            throw new RuntimeException('Panel price proposal concurrency tests require an isolated PostgreSQL test database.');
        }
        Config::set('database.connections.'.self::OBSERVER, config('database.connections.'.config('database.default')));
        DB::purge(self::OBSERVER);
        $supervisor = $this->actor('ca_supervisor');
        $this->officer = $this->actor('panel_officer');
        $this->finance = $this->actor('finance_officer');
        $this->director = $this->actor('director');
        foreach ([$supervisor, $this->officer, $this->finance, $this->director] as $user) {
            $this->selectBranch($user);
        }
        $references = app(PricingReferenceAdministrationService::class);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-RACE', 'name' => 'Synthetic race Panel']);
        $this->book = $references->createPriceBook($supervisor, null, ['name' => 'Synthetic race book', 'price_tier' => 'panel', 'panel_id' => $panel->id]);
        $medicine = MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id, 'code' => 'RACE-MED', 'display_name' => 'Synthetic race medicine', 'order_unit' => 'tablet',
            'created_by_user_id' => $supervisor->id, 'updated_by_user_id' => $supervisor->id,
        ]);
        $this->charge = $references->createMedicineCharge($supervisor, $medicine, ['code' => 'RACE-MED', 'display_name' => 'Synthetic race medicine']);
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

    private function proposal(): PriceChangeProposal
    {
        return app(PanelPriceProposalService::class)->propose($this->officer, $this->book, $this->charge, [
            'expected_branch_id' => $this->branch->id, 'expected_version' => 0, 'amount_sen' => 1450, 'agreement_reference' => 'AGR-RACE',
            'effective_date' => CarbonImmutable::now($this->branch->timezone)->addDay()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_two_approvers_on_one_proposal_write_one_price(): void
    {
        $proposal = $this->proposal();
        $out = $this->race('select id from price_change_proposals where id = ? for update', $proposal->id, [
            $this->worker('approve', $this->finance, ['proposal' => $proposal->public_id, 'lock_version' => 1]),
            $this->worker('approve', $this->director, ['proposal' => $proposal->public_id, 'lock_version' => 1]),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS '), $out);
        $this->assertStringContainsString('STALE proposal: '.PanelPriceProposalService::CHANGED, $out);
        $this->assertSame(1, PriceEntry::query()->where('price_book_id', $this->book->id)->count());
        $this->assertSame('approved', $proposal->refresh()->status);
        $this->assertSame(1, DB::table('price_change_proposal_events')->where('price_change_proposal_id', $proposal->id)->where('event', 'approved')->count());
    }

    public function test_an_approval_queued_before_a_withdrawal_stands_and_the_withdrawal_is_refused(): void
    {
        $proposal = $this->proposal();
        $out = $this->race('select id from price_change_proposals where id = ? for update', $proposal->id, [
            $this->worker('approve', $this->finance, ['proposal' => $proposal->public_id, 'lock_version' => 1]),
            $this->worker('withdraw', $this->officer, ['proposal' => $proposal->public_id, 'lock_version' => 1]),
        ]);

        $this->assertStringContainsString('SUCCESS '.$proposal->public_id, $out);
        $this->assertStringContainsString('STALE proposal: '.PanelPriceProposalService::CHANGED, $out);
        $this->assertSame('approved', $proposal->refresh()->status);
        $this->assertSame(1, PriceEntry::query()->where('price_book_id', $this->book->id)->count());
    }

    public function test_a_withdrawal_queued_before_an_approval_stands_and_no_price_is_written(): void
    {
        $proposal = $this->proposal();
        $out = $this->race('select id from price_change_proposals where id = ? for update', $proposal->id, [
            $this->worker('withdraw', $this->officer, ['proposal' => $proposal->public_id, 'lock_version' => 1]),
            $this->worker('approve', $this->finance, ['proposal' => $proposal->public_id, 'lock_version' => 1]),
        ]);

        $this->assertStringContainsString('SUCCESS '.$proposal->public_id, $out);
        $this->assertStringContainsString('STALE proposal: '.PanelPriceProposalService::CHANGED, $out);
        $this->assertSame('withdrawn', $proposal->refresh()->status);
        $this->assertSame(0, PriceEntry::query()->where('price_book_id', $this->book->id)->count());
    }

    public function test_two_proposals_for_one_book_and_charge_leave_one_open(): void
    {
        $second = $this->actor('panel_officer');
        $this->selectBranch($second);
        $args = fn (string $reference) => ['book' => $this->book->id, 'charge' => $this->charge->id, 'expected_version' => 0, 'amount_sen' => 1500,
            'agreement_reference' => $reference, 'effective_date' => CarbonImmutable::now($this->branch->timezone)->addDay()->toDateString()];
        $out = $this->race('select id from charge_definitions where id = ? for update', $this->charge->id, [
            $this->worker('propose', $this->officer, $args('AGR-A')),
            $this->worker('propose', $second, $args('AGR-B')),
        ]);

        $this->assertSame(1, substr_count($out, 'SUCCESS '), $out);
        $this->assertStringContainsString('STALE proposal: A price change for this Panel and charge is already waiting', $out);
        $this->assertSame(1, PriceChangeProposal::query()->where('status', 'proposed')->count());
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
        $process = new Process([PHP_BINARY, base_path('tests/Support/PostgresPanelPriceProposalWorker.php'), $mode, (string) $actor->id, (string) $this->branch->id,
            base64_encode(json_encode($args, JSON_THROW_ON_ERROR)), 'kpone-ppp-pg-'.Str::lower(Str::random(8))], base_path());
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
                    throw new RuntimeException('Panel price proposal worker ended before READY. '.$this->processDiagnostics($worker['process']));
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Panel price proposal worker did not report READY. '.implode(' | ', array_map(fn ($w) => $this->processDiagnostics($w['process']), $workers)));
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        preg_match('/READY ([1-9][0-9]*)/', $process->getOutput(), $matches);
        $pid = (int) ($matches[1] ?? 0);
        $deadline = microtime(true) + ContentionTimeouts::protocolTimeoutSeconds();
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required Panel price proposal contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected Panel price proposal blocker chain was not observed. '.$this->processDiagnostics($process));
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
