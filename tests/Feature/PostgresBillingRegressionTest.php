<?php

namespace Tests\Feature;

use App\Domain\Access\BillingPermissions;
use App\Domain\Access\BranchAccessService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\ClinicalEncounterService;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffProfile;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Patient\Models\Patient;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\StaffBranchAssignmentBootstrapper;
use Tests\TestCase;

/** @phpstan-type Worker array{process: Process, input: InputStream} */
class PostgresBillingRegressionTest extends TestCase
{
    private const OBSERVER = 'pgsql_billing_observer';

    /** @var list<int> */
    private array $organisationIds = [];

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<InputStream> */
    private array $inputs = [];

    private int $sequence = 1;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL Phase 3B regressions require DB_CONNECTION=pgsql.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! app()->environment('testing') || preg_match('/(?:^|_)(?:test|testing)(?:_|$)/i', $database) !== 1) {
            throw new RuntimeException('Phase 3B concurrency tests require an isolated PostgreSQL test database.');
        }
        foreach (['invoices', 'invoice_lines', 'consultation_checkouts', 'payment_allocations'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Migrate the isolated PostgreSQL test database before Phase 3B regressions.');
            }
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

    public function test_two_cashiers_cannot_overpay_one_invoice(): void
    {
        $f = $this->billingFixture();
        $out = $this->race([$this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000)), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000), 'ca2')], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE'));
        $this->assertSame(4000, app(FinancialLedger::class)->state($f['invoice'])['self_pay']);
    }

    public function test_duplicate_complete_visitation_records_one_transition(): void
    {
        $f = $this->billingFixture(0);
        $args = ['visit_lock_version' => $f['visit']->lock_version];
        $out = $this->race([$this->billWorker($f, 'bill-complete', $args), $this->billWorker($f, 'bill-complete', $args, 'ca2')], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE'));
        $this->assertSame('completed', $f['visit']->refresh()->status);
        $this->assertSame(1, AuditLog::query()->where('organisation_id', $f['organisation']->id)->where('event', 'billing.visit_completed')->count());
    }

    public function test_payment_and_completion_require_the_same_financial_revision(): void
    {
        $f = $this->billingFixture();
        $out = $this->race([$this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000)), $this->billWorker($f, 'bill-complete', ['visit_lock_version' => $f['visit']->lock_version], 'ca2')], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE'));
        $this->assertSame('registered', $f['visit']->refresh()->status);
        app(CompleteVisitationService::class)->complete($f['ca'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $f['visit']->lock_version, 'lock_version' => $f['invoice']->refresh()->lock_version]);
        $this->assertSame('completed', $f['visit']->refresh()->status);
    }

    public function test_deferment_approval_and_payment_do_not_double_allocate(): void
    {
        $f = $this->billingFixture();
        $proposal = $this->proposal($f, 'deferment');
        $out = $this->race([$this->billWorker($f, 'bill-approve', ['kind' => 'deferment', 'proposal' => $proposal->public_id, 'proposal_lock_version' => $proposal->lock_version], 'approver'), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000))], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $state = app(FinancialLedger::class)->state($f['invoice']);
        $this->assertSame(4000, $state['self_pay'] + $state['deferred']);
        $this->assertSame(0, $state['due_now']);
    }

    public function test_panel_acceptance_and_self_pay_do_not_double_allocate(): void
    {
        $f = $this->billingFixture();
        $proposal = $this->proposal($f, 'panel');
        $out = $this->race([$this->billWorker($f, 'bill-approve', ['kind' => 'panel', 'proposal' => $proposal->public_id, 'proposal_lock_version' => $proposal->lock_version], 'approver'), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000))], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $state = app(FinancialLedger::class)->state($f['invoice']);
        $this->assertSame(4000, $state['self_pay'] + $state['panel']);
        $this->assertSame(0, $state['due_now']);
    }

    public function test_duplicate_build_and_finalization_preserve_one_current_invoice(): void
    {
        $f = $this->billingFixture(4000, false);
        $out = $this->race([$this->billWorker($f, 'bill-build'), $this->billWorker($f, 'bill-build', [], 'ca2')], $f['patient']);
        $this->assertSame(2, substr_count($out, 'SUCCESS'));
        $f['invoice'] = Invoice::query()->where('visit_id', $f['visit']->id)->sole();
        $out = $this->race([$this->billWorker($f, 'bill-finalize'), $this->billWorker($f, 'bill-finalize', [], 'ca2')], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE'));
        $this->assertSame(2, DB::table('invoice_lines')->where('invoice_id', $f['invoice']->id)->count());
        // Direct SQL proof reaches COMMIT, exercising deferred protection rather than model guards.
        $lineId = DB::table('invoice_lines')->where('invoice_id', $f['invoice']->id)->value('id');
        $this->assertPgRejects(fn () => DB::table('invoice_lines')->where('id', $lineId)->update(['quantity' => '-1.000']));
        $this->assertPgRejects(fn () => DB::table('invoice_lines')->where('id', $lineId)->update(['display_name' => 'Forbidden final rewrite']));
        $this->assertPgRejects(fn () => DB::table('invoices')->where('id', $f['invoice']->id)->update(['total_sen' => 1]));
        $this->assertPgRejects(fn () => DB::table('invoice_lines')->where('id', $lineId)->update(['branch_id' => 999999999]));
    }

    public function test_dispensary_completion_and_billing_generation_use_committed_actual_quantity(): void
    {
        $f = $this->completableFixture('10.000', '4.000');
        $this->prices($f, 4000);
        $out = $this->race([$this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]), $this->billWorker($f, 'bill-build', [], 'ca2')], $f['patient']);
        $this->assertStringContainsString('COMPLETED', $out);
        $invoice = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => null]);
        $this->assertSame('4.000', (string) DB::table('invoice_lines')->where('invoice_id', $invoice->id)->where('line_type', 'medicine')->value('quantity'));
        $this->assertSame('6.000', (string) $f['balance']->refresh()->quantity);
    }

    public function test_payment_permission_loss_wins_before_mutation(): void
    {
        $f = $this->billingFixture();
        $f['ca']->syncRoles([]);
        $out = $this->leaderThenFollower($this->worker(['revoke', (string) $f['ca']->id, 'payments.add.branch']), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000)));
        $this->assertStringContainsString('DENIED', $out);
        $this->assertSame(0, Payment::query()->where('organisation_id', $f['organisation']->id)->count());
    }

    public function test_assignment_loss_fails_closed_before_payment(): void
    {
        $f = $this->billingFixture();
        $out = $this->leaderThenFollower($this->worker(['end-assignment', (string) $f['ca']->id, (string) $f['branch']->id]), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000)));
        $this->assertSame(0, substr_count($out, 'SUCCESS'));
        $this->assertSame(0, Payment::query()->where('organisation_id', $f['organisation']->id)->count());
    }

    public function test_visit_state_loss_before_completion_fails_closed(): void
    {
        $f = $this->billingFixture(0);
        $out = $this->leaderThenFollower($this->worker(['state-loss', $f['visit']->visit_number, 'visit', (string) $f['ca2']->id]), $this->billWorker($f, 'bill-complete', ['visit_lock_version' => $f['visit']->lock_version]));
        $this->assertStringContainsString('STALE', $out);
        $this->assertSame('cancelled', $f['visit']->refresh()->status);
        $this->assertNull($f['visit']->completed_at);
    }

    public function test_last_remaining_payment_and_idempotent_retry_are_exact(): void
    {
        $f = $this->billingFixture();
        app(PaymentService::class)->add($f['ca'], $f['visit'], $f['invoice'], [...$this->payArgs($f, 2000), 'expected_branch_id' => $f['branch']->id, 'lock_version' => $f['invoice']->lock_version]);
        $f['invoice']->refresh();
        $a = $this->payArgs($f, 2000);
        $b = $this->payArgs($f, 2000);
        $out = $this->race([$this->billWorker($f, 'bill-pay', $a), $this->billWorker($f, 'bill-pay', $b)], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE'));
        $winner = Payment::query()->where('organisation_id', $f['organisation']->id)->latest('id')->firstOrFail();
        $retry = $winner->idempotency_key === $a['idempotency_key'] ? $a : $b;
        $workers = [$this->billWorker($f, 'bill-pay', $retry)];
        $this->runWorkers($workers);
        $this->assertStringContainsString('SUCCESS', $this->workerOutput($workers));
        $this->assertSame(2, Payment::query()->where('organisation_id', $f['organisation']->id)->count());
    }

    public function test_late_financial_audit_failure_rolls_back_payment_and_completion(): void
    {
        $f = $this->billingFixture();
        DB::unprepared("CREATE OR REPLACE FUNCTION kpone_phase3b_fail_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event LIKE 'billing.%' THEN RAISE EXCEPTION 'Synthetic late billing audit failure' USING ERRCODE='23514'; END IF; RETURN NEW; END $$; CREATE TRIGGER kpone_phase3b_fail_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION kpone_phase3b_fail_audit()");
        try {
            $version = $f['invoice']->lock_version;
            $workers = [$this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000))];
            $this->runWorkers($workers);
            $this->assertSame(1, $workers[0]['process']->getExitCode());
            $this->assertSame($version, $f['invoice']->refresh()->lock_version);
            $this->assertSame(0, Payment::query()->where('organisation_id', $f['organisation']->id)->count());
        } finally {
            DB::unprepared('DROP TRIGGER kpone_phase3b_fail_audit ON audit_logs; DROP FUNCTION kpone_phase3b_fail_audit()');
        }
        app(PaymentService::class)->add($f['ca'], $f['visit'], $f['invoice'], [...$this->payArgs($f, 4000), 'expected_branch_id' => $f['branch']->id, 'lock_version' => $f['invoice']->lock_version]);
        $f['invoice']->refresh();
        DB::unprepared("CREATE OR REPLACE FUNCTION kpone_phase3b_fail_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.event='billing.visit_completed' THEN RAISE EXCEPTION 'Synthetic late completion failure' USING ERRCODE='23514'; END IF; RETURN NEW; END $$; CREATE TRIGGER kpone_phase3b_fail_audit BEFORE INSERT ON audit_logs FOR EACH ROW EXECUTE FUNCTION kpone_phase3b_fail_audit()");
        try {
            $workers = [$this->billWorker($f, 'bill-complete', ['visit_lock_version' => $f['visit']->lock_version])];
            $this->runWorkers($workers);
            $this->assertSame(1, $workers[0]['process']->getExitCode());
            $this->assertSame('registered', $f['visit']->refresh()->status);
        } finally {
            DB::unprepared('DROP TRIGGER kpone_phase3b_fail_audit ON audit_logs; DROP FUNCTION kpone_phase3b_fail_audit()');
        }
    }

    public function test_price_publication_before_finalization_rejects_stale_preview(): void
    {
        $f = $this->billingFixture(4000, false);
        $f['invoice'] = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => null]);
        $f['publisher'] = $this->user($f['organisation'], $f['branch'], null, ['prices.publish.organisation', 'branch_context.switch.branch']);
        $out = $this->leaderThenFollower($this->billWorker($f, 'bill-price', ['book' => $f['book']->id, 'charge' => $f['charge']->id, 'price_version' => 1, 'amount_sen' => 4500, 'hold' => true], 'publisher'), $this->billWorker($f, 'bill-finalize'));
        $this->assertStringContainsString('STALE', $out);
        $this->assertSame('draft', $f['invoice']->refresh()->status);
        $this->assertSame(4000, $f['invoice']->total_sen);
    }

    public function test_no_medicine_checkout_and_encounter_edit_serialize_on_visit(): void
    {
        $f = $this->noMedicineFixture(false);
        $out = $this->race([$this->billWorker($f, 'bill-checkout', $this->checkoutArgs($f), 'doctor'), $this->billWorker($f, 'bill-edit', ['lock_version' => $f['encounter']->lock_version, 'clinical_note' => 'Synthetic concurrent checkout note', 'vitals' => [], 'diagnoses' => []], 'doctor')], $f['visit']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE') + substr_count($out, 'DENIED'));
        $this->assertSame(0, DispensaryCase::query()->where('visit_id', $f['visit']->id)->count());
    }

    public function test_reopen_before_invoice_generation_invalidates_checkout(): void
    {
        $f = $this->noMedicineFixture(true);
        $out = $this->leaderThenFollower($this->billWorker($f, 'bill-reopen', ['visit_lock_version' => $f['visit']->lock_version, 'checkout_lock_version' => $f['checkout']->lock_version, 'hold' => true], 'doctor'), $this->billWorker($f, 'bill-build'));
        $this->assertStringContainsString('STALE', $out);
        $this->assertSame(0, Invoice::query()->where('visit_id', $f['visit']->id)->count());
        $this->assertSame('serving', $f['queue']->refresh()->status);
    }

    public function test_payment_reversal_and_completion_have_one_valid_winner(): void
    {
        $f = $this->billingFixture();
        $receipt = app(PaymentService::class)->add($f['ca'], $f['visit'], $f['invoice'], [...$this->payArgs($f, 4000), 'expected_branch_id' => $f['branch']->id, 'lock_version' => $f['invoice']->lock_version]);
        $f['invoice']->refresh();
        $f['finance'] = $this->user($f['organisation'], $f['branch'], null, ['payments.reverse.branch', 'branch_context.switch.branch']);
        $out = $this->race([$this->billWorker($f, 'bill-reverse', ['payment' => $receipt->public_id, 'payment_lock_version' => 1, 'recording_error_only' => true, 'reason' => 'Synthetic recording correction'], 'finance'), $this->billWorker($f, 'bill-complete', ['visit_lock_version' => $f['visit']->lock_version])], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE'));
        $state = app(FinancialLedger::class)->state($f['invoice']);
        $this->assertSame($f['visit']->refresh()->status === 'completed' ? 0 : 4000, $state['due_now']);
    }

    public function test_void_reissue_and_payment_cannot_both_win(): void
    {
        $f = $this->billingFixture();
        $f['finance'] = $this->user($f['organisation'], $f['branch'], null, ['invoices.void.branch', 'branch_context.switch.branch']);
        $out = $this->race([$this->billWorker($f, 'bill-void', ['reason' => 'Synthetic full correction'], 'finance'), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 4000))], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame(1, substr_count($out, 'STALE'));
        if ($f['invoice']->refresh()->status === 'voided') {
            $new = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => null]);
            $this->assertSame($f['invoice']->public_id, $new->replaces_public_id);
        } else {
            $this->assertSame(4000, app(FinancialLedger::class)->state($f['invoice'])['self_pay']);
        }
    }

    public function test_old_outstanding_settlement_retains_completed_history_and_denies_cross_branch_probes(): void
    {
        $f = $this->billingFixture();
        $proposal = $this->proposal($f, 'deferment');
        app(ResponsibilityService::class)->approve($f['approver'], $f['visit'], $f['invoice'], 'deferment', $proposal->public_id, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $f['invoice']->lock_version, 'proposal_lock_version' => 1]);
        $f['invoice']->refresh();
        app(CompleteVisitationService::class)->complete($f['ca'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $f['visit']->lock_version, 'lock_version' => $f['invoice']->lock_version]);
        $before = $f['visit']->refresh()->completion_evidence;
        $out = $this->race([$this->billWorker($f, 'bill-pay', $this->payArgs($f, 3000)), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 3000), 'ca2')], $f['patient']);
        $this->assertSame(1, substr_count($out, 'SUCCESS'));
        $this->assertSame($before, $f['visit']->refresh()->completion_evidence);
        $this->assertSame(1000, app(FinancialLedger::class)->state($f['invoice'])['deferred']);
        $this->assertPgRejects(fn () => DB::table('patient_receivables')->where('id', $proposal->id)->update(['remaining_sen' => 0]));
        $other = $this->fixture();
        $f['outsider'] = $other['ca'];
        $args = ['bill-pay', (string) $other['ca']->id, (string) $other['branch']->id, $f['visit']->visit_number, base64_encode(json_encode([...$this->payArgs($f, 1000), 'invoice' => $f['invoice']->public_id, 'lock_version' => $f['invoice']->refresh()->lock_version], JSON_THROW_ON_ERROR))];
        $workers = [$this->worker($args), $this->billWorker($f, 'bill-pay', $this->payArgs($f, 1000), 'doctor')];
        $this->runWorkers($workers);
        $this->assertSame(2, substr_count($this->workerOutput($workers), 'DENIED'));
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $f['organisation']->id, 'code' => 'OTHER', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $actor = $this->user($f['organisation'], $branch, 'ca', $this->caPermissions());
        $args[1] = (string) $actor->id;
        $args[2] = (string) $branch->id;
        $workers = [$this->worker($args)];
        $this->runWorkers($workers);
        $this->assertStringContainsString('DENIED', $this->workerOutput($workers));
        $this->assertSame(1000, app(FinancialLedger::class)->state($f['invoice'])['deferred']);
    }

    private function billingFixture(int $total = 4000, bool $finalized = true): array
    {
        $f = $this->completableFixture('10.000', '4.000');
        app(DispensaryService::class)->complete($f['ca'], $f['case'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version]);
        $this->prices($f, $total);
        if ($finalized) {
            $f['invoice'] = app(BillingBuilderService::class)->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => null]);
            $f['invoice'] = app(BillingBuilderService::class)->finalize($f['ca'], $f['visit'], $f['invoice'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => $f['invoice']->lock_version]);
        }

        return $f;
    }

    private function assertPgRejects(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL accepted invalid retained financial evidence.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23514', '23503', '23505']);
        }
    }

    private function prices(array &$f, int $consultationPrice): void
    {
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'scope_key' => 'organisation', 'name' => 'Synthetic PG prices', 'currency' => 'MYR'])->save();
        foreach (['consultation', 'medicine'] as $type) {
            $charge = new ChargeDefinition;
            $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'code' => 'UAT-'.$type, 'type' => $type, 'display_name' => 'Synthetic '.$type, 'unit' => $type === 'consultation' ? 'consultation' : 'unit', 'source_key' => $type === 'consultation' ? 'consultation' : 'medicine:'.$f['medicine']->id, 'medicine_catalogue_item_id' => $type === 'medicine' ? $f['medicine']->id : null])->save();
            $price = new PriceEntry;
            $price->forceFill(['organisation_id' => $f['organisation']->id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'unit_price_sen' => $type === 'consultation' ? $consultationPrice : 0, 'version' => 1, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
            if ($type === 'consultation') {
                $f['charge'] = $charge;
            }
        }
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $f['organisation']->id, 'code' => 'cash', 'name' => 'Cash'])->save();
        $f['book'] = $book;
    }

    private function noMedicineFixture(bool $checkout): array
    {
        $f = $this->fixture();
        // Initial synthetic fixture only; no handoff or financial scenario has begun.
        DB::table('treatment_plan_medicine_orders')->where('treatment_plan_id', $f['plan']->id)->delete();
        $this->prices($f, 4000);
        if ($checkout) {
            $f['checkout'] = app(CompleteConsultationService::class)->complete($f['doctor'], $f['visit'], ['expected_branch_id' => $f['branch']->id, ...$this->checkoutArgs($f)]);
        }

        return $f;
    }

    private function checkoutArgs(array $f): array
    {
        return ['visit_lock_version' => $f['visit']->lock_version, 'queue_lock_version' => $f['queue']->lock_version, 'encounter_lock_version' => $f['encounter']->lock_version, 'lock_version' => $f['plan']->lock_version, 'service_deliveries' => []];
    }

    private function proposal(array &$f, string $kind): Model
    {
        $f['approver'] = $this->user($f['organisation'], $f['branch'], null, ['branch_context.switch.branch', 'coverage.approve.branch', 'outstanding.approve.branch']);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $f['organisation']->id, 'branch_id' => $f['branch']->id, 'user_id' => $f['approver']->id, 'capability' => $kind, 'limit_sen' => 10000]);
        $extra = ['due_date' => now()->addDay()->toDateString()];
        if ($kind === 'panel') {
            $extra['panel_id'] = DB::table('panels')->insertGetId(['organisation_id' => $f['organisation']->id, 'code' => 'UAT-PG', 'name' => 'Synthetic Panel', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $proposal = app(ResponsibilityService::class)->propose($f['ca'], $f['visit'], $f['invoice'], $kind, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $f['invoice']->lock_version, 'amount_sen' => 4000, 'reason' => 'Synthetic responsibility', ...$extra]);
        $f['invoice']->refresh();

        return $proposal;
    }

    private function payArgs(array $f, int $amount): array
    {
        return ['amount_sen' => $amount, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    }

    private function billWorker(array $f, string $mode, array $a = [], string $actor = 'ca'): array
    {
        return $this->worker([$mode, (string) $f[$actor]->id, (string) $f['branch']->id, $f['visit']->visit_number, base64_encode(json_encode(['invoice' => $f['invoice']->public_id ?? null, 'lock_version' => $f['invoice']->lock_version ?? null, ...$a], JSON_THROW_ON_ERROR))]);
    }

    private function fixture(): array
    {
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'DISP_PG_'.Str::upper(Str::random(8)), 'name' => 'Synthetic Phase 3A PG', 'is_active' => true])->save();
        $this->organisationIds[] = $organisation->id;
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'D'.Str::upper(Str::random(5)), 'name' => 'Synthetic Dispensary Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $operator = $this->user($organisation, $branch, null, ['queue.view.branch', 'queue.enter.branch', 'queue.call.branch', 'visits.view.branch', 'branch_context.switch.organisation']);
        $doctor = $this->user($organisation, $branch, 'resident_doctor', $this->doctorPermissions());
        $ca = $this->user($organisation, $branch, 'ca', $this->caPermissions());
        $ca2 = $this->user($organisation, $branch, 'ca', $this->caPermissions());
        $inventorySupervisor = $this->user($organisation, $branch, 'ca_supervisor', $this->inventorySupervisorPermissions());
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $organisation->id, 'patient_number' => sprintf('KP-%08d', 96_000_000 + $this->sequence), 'full_name' => 'Synthetic Dispensary Patient', 'search_name' => 'synthetic dispensary patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill(['organisation_id' => $organisation->id, 'branch_id' => $branch->id, 'patient_id' => $patient->id, 'visit_number' => sprintf('KPV-%08d', 96_000_000 + $this->sequence++), 'idempotency_key' => (string) Str::uuid(), 'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal', 'visit_reason' => 'Synthetic Dispensary reason', 'assigned_doctor_user_id' => $doctor->id, 'coverage_type' => 'self_pay', 'registered_at' => now()->utc(), 'registered_by_user_id' => $operator->id, 'updated_by_user_id' => $operator->id, 'lock_version' => 1])->save();
        app('session')->start();
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $queue = app(QueueEntryService::class)->enter($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version]);
        $queue = app(QueueEntryService::class)->call($operator, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        session([BranchAccessService::SESSION_KEY => $branch->id]);
        $encounter = app(ClinicalEncounterService::class)->start($doctor, $visit, ['expected_branch_id' => $branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, ['expected_branch_id' => $branch->id, 'profile_lock_version' => null]);
        app(PatientAllergyService::class)->review($doctor, $visit, ['expected_branch_id' => $branch->id, 'profile_lock_version' => $profile->lock_version]);
        $medicine = new MedicineCatalogueItem;
        $medicine->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'MED-'.Str::upper(Str::random(7)), 'display_name' => 'Synthetic concurrency medicine', 'strength_text' => 'Synthetic strength', 'dosage_form' => 'unit', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED, 'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id])->save();
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, ['expected_branch_id' => $branch->id, 'lock_version' => null, 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => '10.000',
            'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null,
            'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]], 'services' => []]);

        return compact('organisation', 'branch', 'operator', 'doctor', 'ca', 'ca2', 'inventorySupervisor', 'patient', 'visit', 'queue', 'encounter', 'profile', 'medicine', 'plan');
    }

    /** @return array<string, mixed> */
    private function sentFixture(): array
    {
        $f = $this->fixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $f['case'] = app(DispensaryHandoffService::class)->send($f['doctor'], $f['visit'], ['expected_branch_id' => $f['branch']->id, 'lock_version' => $f['plan']->lock_version]);
        $f['item'] = $f['case']->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();

        return $f;
    }

    /** @return array<string, mixed> */
    private function startedFixture(): array
    {
        $f = $this->sentFixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $f['case'] = app(DispensaryService::class)->start($f['ca'], $f['case'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version]);
        $f['item'] = $f['item']->refresh();

        return $f;
    }

    /** @return array<string, mixed> */
    private function patientDeclinedFixture(): array
    {
        $f = $this->startedFixture();
        $f['item'] = app(DispensaryService::class)->updateItem($f['ca'], $f['case'], $f['item'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version, 'status' => 'not_dispensed', 'quantity_dispensed' => '0.000', 'reason' => 'patient_declined', 'allocations' => []]);
        $f['case']->refresh();
        $f['exception'] = $f['item']->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();

        return $f;
    }

    /** @return array<string, mixed> */
    private function zeroCompletableFixture(): array
    {
        $f = $this->patientDeclinedFixture();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        app(DispensaryService::class)->acknowledge($f['doctor'], $f['exception'], ['case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);

        return $f;
    }

    /** @return array<string, mixed> */
    private function completableFixture(string $opening, string $actual): array
    {
        $f = $this->startedFixture();
        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'code' => 'ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        $mapping = new MedicineCatalogueInventorySku;
        $mapping->forceFill(['organisation_id' => $f['organisation']->id, 'medicine_catalogue_item_id' => $f['medicine']->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $f['inventorySupervisor']->id, 'approved_at' => now()->utc()])->save();
        $location = $this->location($f, 'DISP-A');
        $destination = $this->location($f, 'DISP-B');
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'B-'.Str::upper(Str::random(6)), 'expiry_date' => now()->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        app(InventoryMovementService::class)->openingBalance($f['inventorySupervisor'], ['expected_branch_id' => $f['branch']->id, 'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $opening]);
        $f['item'] = app(DispensaryService::class)->updateItem($f['ca'], $f['case'], $f['item'], ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version, 'status' => 'partial', 'quantity_dispensed' => $actual, 'reason' => 'patient_declined', 'allocations' => [[
            'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => $actual,
        ]]]);
        $f['case']->refresh();
        $exception = $f['item']->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        app(DispensaryService::class)->acknowledge($f['doctor'], $exception, ['case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);
        $f['sku'] = $sku;
        $f['batch'] = $batch;
        $f['location'] = $location;
        $f['destination'] = $destination;
        $f['balance'] = InventoryStockBalance::query()->where('inventory_location_id', $location->id)->sole();

        return $f;
    }

    /** @param array<string, mixed> $f */
    private function location(array $f, string $suffix): InventoryLocation
    {
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['organisation']->id, 'branch_id' => $f['branch']->id, 'code' => $suffix.Str::upper(Str::random(4)), 'name' => 'Synthetic '.$suffix, 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();

        return $location;
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function additionalCompletableCase(array $f, string $actual): array
    {
        $patient = new Patient;
        $patient->forceFill(['organisation_id' => $f['organisation']->id, 'patient_number' => sprintf('KP-%08d', 96_000_000 + $this->sequence), 'full_name' => 'Synthetic Competing Patient', 'search_name' => 'synthetic competing patient', 'sex' => 'unknown', 'lock_version' => 1])->save();
        $visit = new Visit;
        $visit->forceFill(['organisation_id' => $f['organisation']->id, 'branch_id' => $f['branch']->id, 'patient_id' => $patient->id, 'visit_number' => sprintf('KPV-%08d', 96_000_000 + $this->sequence++), 'idempotency_key' => (string) Str::uuid(), 'visit_type' => 'consultation', 'status' => Visit::STATUS_REGISTERED, 'priority' => 'normal', 'visit_reason' => 'Synthetic competing stock reason', 'assigned_doctor_user_id' => $f['doctor']->id, 'coverage_type' => 'self_pay', 'registered_at' => now()->utc(), 'registered_by_user_id' => $f['operator']->id, 'updated_by_user_id' => $f['operator']->id, 'lock_version' => 1])->save();
        session([BranchAccessService::SESSION_KEY => $f['branch']->id]);
        $queue = app(QueueEntryService::class)->enter($f['operator'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version]);
        $queue = app(QueueEntryService::class)->call($f['operator'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $encounter = app(ClinicalEncounterService::class)->start($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $profile = app(PatientAllergyService::class)->declareNoKnown($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'profile_lock_version' => null]);
        app(PatientAllergyService::class)->review($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'profile_lock_version' => $profile->lock_version]);
        $plan = app(TreatmentPlanService::class)->save($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'lock_version' => null, 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $f['medicine']->public_id, 'quantity_ordered' => '10.000', 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]], 'services' => []]);
        $case = app(DispensaryHandoffService::class)->send($f['doctor'], $visit, ['expected_branch_id' => $f['branch']->id, 'lock_version' => $plan->lock_version]);
        $case = app(DispensaryService::class)->start($f['ca2'], $case, ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $case->lock_version]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        $item = app(DispensaryService::class)->updateItem($f['ca2'], $case, $item, ['expected_branch_id' => $f['branch']->id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version, 'status' => 'partial', 'quantity_dispensed' => $actual, 'reason' => 'patient_declined', 'allocations' => [[
            'location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => $actual,
        ]]]);
        $case->refresh();
        $exception = $item->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        app(DispensaryService::class)->acknowledge($f['doctor'], $exception, ['case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version]);

        return compact('patient', 'visit', 'queue', 'encounter', 'profile', 'plan', 'case', 'item');
    }

    private function assertAuthorityLossFailsClosed(string $mode): void
    {
        $f = $this->zeroCompletableFixture();
        $leaderArgs = [$mode, (string) $f['ca']->id];
        if ($mode === 'revoke') {
            $leaderArgs[] = 'dispensary.complete.branch';
        } elseif ($mode === 'end-assignment') {
            $leaderArgs[] = (string) $f['branch']->id;
        }
        $leader = $this->worker($leaderArgs);
        $follower = $this->worker(['complete', (string) $f['ca']->id, (string) $f['branch']->id, $f['case']->public_id, (string) $f['case']->lock_version]);
        $this->assertStringContainsString('DENIED', $this->leaderThenFollower($leader, $follower));
        $this->assertNotSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    /** @return list<string> */
    private function doctorPermissions(): array
    {
        return ['consultations.complete.own', 'consultations.reopen.own', 'services.confirm.own', 'branch_context.switch.branch', 'queue.view.own', 'queue.call.own', 'encounters.view.own', 'encounters.start.own', 'encounters.update.own', 'allergies.view.own', 'allergies.update.own', 'allergies.review.own', 'treatment_plans.view.own', 'treatment_plans.create.own', 'treatment_plans.update.own', 'treatment_plans.send_to_dispensary.own', 'dispensary.acknowledge_partial.own'];
    }

    /** @return list<string> */
    private function caPermissions(): array
    {
        return [...BillingPermissions::roles()['ca'], 'branch_context.switch.branch', 'dispensary.view.branch', 'dispensary.start.branch', 'dispensary.update.branch', 'dispensary.complete.branch', 'dispensary.return_to_doctor.branch', 'inventory.view.branch', 'inventory.transfer.branch'];
    }

    /** @return list<string> */
    private function inventorySupervisorPermissions(): array
    {
        return [...$this->caPermissions(), 'inventory.opening_balance.branch', 'inventory.transfer.organisation'];
    }

    /** @param list<string> $permissions */
    private function user(Organisation $organisation, Branch $branch, ?string $role, array $permissions): User
    {
        $user = new User;
        $user->forceFill(['organisation_id' => $organisation->id, 'name' => 'Synthetic Phase 3A User', 'email' => 'disp.pg.'.Str::lower(Str::random(10)).'@kpone.test', 'is_active' => true])->save();
        if ($role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $profile = new StaffProfile;
        $profile->forceFill(['user_id' => $user->id, 'department_id' => null])->save();
        StaffBranchAssignmentBootstrapper::create($profile, $branch, ['assignment_type' => 'temporary', 'is_primary' => false, 'valid_from' => now()->subDay()->toDateString(), 'valid_until' => now()->addDay()->toDateString()]);

        return $user->refresh();
    }

    /** @param list<string> $arguments @return Worker */
    private function worker(array $arguments): array
    {
        $input = new InputStream;
        $process = new Process([PHP_BINARY, base_path(str_starts_with($arguments[0], 'bill-') ? 'tests/Support/PostgresBillingWorker.php' : 'tests/Support/PostgresDispensaryInventoryWorker.php'), ...$arguments, 'kpone-dispensary-pg-'.Str::lower(Str::random(8))], base_path());
        $process->setInput($input);
        $process->setTimeout(40);
        $this->workers[] = $process;
        $this->inputs[] = $input;

        return ['process' => $process, 'input' => $input];
    }

    /** @param list<Worker> $workers */
    private function race(array $workers, Model $blocker): string
    {
        DB::beginTransaction();
        try {
            $blocker->newQuery()->whereKey($blocker->getKey())->lockForUpdate()->firstOrFail();
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
        $this->finish($workers);

        return $this->workerOutput($workers);
    }

    private function leaderThenFollower(array $leader, array $follower): string
    {
        $leader['process']->start();
        $this->waitReady([$leader]);
        $leader['input']->write("GO\n");
        $this->waitForOutput($leader['process'], 'LOCKED');
        $leaderPid = $this->workerPid($leader['process']);
        $follower['process']->start();
        $this->waitReady([$follower]);
        $follower['input']->write("GO\n");
        $follower['input']->close();
        $this->waitForDatabaseBlock($follower['process'], $leaderPid);
        $leader['input']->write("COMMIT\n");
        $leader['input']->close();
        $this->finish([$leader, $follower]);

        return $this->workerOutput([$leader, $follower]);
    }

    /** @param list<Worker> $workers */
    private function runWorkers(array $workers): void
    {
        foreach ($workers as $worker) {
            $worker['process']->start();
        }
        $this->waitReady($workers);
        foreach ($workers as $worker) {
            $worker['input']->write("GO\n");
            $worker['input']->close();
        }
        $this->finish($workers, false);
    }

    /** @param list<Worker> $workers */
    private function finish(array $workers, bool $requireZero = true): void
    {
        foreach ($workers as $worker) {
            $worker['process']->wait();
            if ($requireZero) {
                $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getOutput().' STDERR: '.$worker['process']->getErrorOutput());
            }
        }
    }

    /** @param list<Worker> $workers */
    private function waitReady(array $workers): void
    {
        $deadline = microtime(true) + 12;
        do {
            if (collect($workers)->every(fn ($worker) => str_contains(str_replace("\r\n", "\n", $worker['process']->getOutput()), 'READY '))) {
                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Phase 3A worker did not report READY. '.$this->diagnostics($workers));
    }

    private function waitForOutput(Process $process, string $needle): void
    {
        $deadline = microtime(true) + 12;
        do {
            if (str_contains(str_replace("\r\n", "\n", $process->getOutput()), $needle)) {
                return;
            }
            if ($process->isTerminated()) {
                throw new RuntimeException('Phase 3A worker exited before '.$needle.'. '.$this->processDiagnostics($process));
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Phase 3A worker protocol timeout for '.$needle.'. '.$this->processDiagnostics($process));
    }

    private function workerPid(Process $process): int
    {
        preg_match('/READY ([1-9][0-9]*)/', str_replace("\r\n", "\n", $process->getOutput()), $matches);
        $pid = (int) ($matches[1] ?? 0);
        if ($pid <= 0) {
            throw new RuntimeException('Phase 3A worker did not report a backend PID. '.$this->processDiagnostics($process));
        }

        return $pid;
    }

    private function waitForDatabaseBlock(Process $process, int $expectedBlockerPid): void
    {
        $pid = $this->workerPid($process);
        $deadline = microtime(true) + 12;
        do {
            if ($process->isTerminated()) {
                throw new RuntimeException('Required Phase 3A contention was not reached. '.$this->processDiagnostics($process));
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
        throw new RuntimeException('Expected Phase 3A PostgreSQL blocker chain was not observed. '.$this->processDiagnostics($process));
    }

    private function observer(): Connection
    {
        return DB::connection(self::OBSERVER);
    }

    /** @param list<Worker> $workers */
    private function workerOutput(array $workers): string
    {
        return implode('', array_map(fn ($worker) => $worker['process']->getOutput(), $workers));
    }

    /** @param list<Worker> $workers */
    private function diagnostics(array $workers): string
    {
        return implode(' | ', array_map(fn ($worker) => $this->processDiagnostics($worker['process']), $workers));
    }

    private function processDiagnostics(Process $process): string
    {
        return 'exit='.var_export($process->getExitCode(), true).' stdout='.trim(str_replace("\r\n", "\n", $process->getOutput())).' stderr='.trim(str_replace("\r\n", "\n", $process->getErrorOutput()));
    }

    private function deleteOrganisation(int $id): void
    {
        DB::transaction(function () use ($id): void {
            foreach (['payment_reversals', 'payment_allocations', 'patient_receivables', 'coverage_allocations', 'payments', 'invoice_lines', 'invoices', 'billing_document_counters', 'price_entries', 'price_books', 'charge_definitions', 'payment_methods', 'billing_approval_limits'] as $table) {
                DB::table($table)->where('organisation_id', $id)->delete();
            }
            DB::table('service_deliveries')->where('organisation_id', $id)->delete();
            DB::table('consultation_checkouts')->where('organisation_id', $id)->delete();
            foreach (['stock_movements', 'dispensary_item_batch_allocations', 'dispensary_item_exceptions', 'dispensary_items', 'dispensary_handoffs', 'dispensary_cases', 'inventory_stock_balances', 'medicine_catalogue_inventory_skus', 'medicine_catalogue_aliases', 'inventory_batches', 'inventory_locations', 'inventory_skus', 'inventory_items', 'treatment_plan_service_orders', 'treatment_plan_medicine_orders', 'treatment_plans', 'clinical_service_catalogue_items', 'medicine_catalogue_items', 'clinical_encounter_allergy_reviews', 'patient_allergy_records', 'patient_allergy_profile_versions', 'patient_allergy_profiles', 'audit_logs', 'clinical_encounters', 'queue_entries', 'queue_number_counters', 'visits', 'visit_number_counters', 'patients', 'patient_number_counters', 'panels'] as $table) {
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
