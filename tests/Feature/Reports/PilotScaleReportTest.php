<?php

namespace Tests\Feature\Reports;

use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Shared\Services\DailySummaryReportService;
use App\Domain\Shared\Services\PanelSummaryReportService;
use App\Domain\Shared\Services\StockMovementReportService;
use App\Domain\Shared\Services\TransactionReportService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestCase;
use Tests\Support\OtcDispensaryFixtures;
use Tests\Support\ReportEvidence;

class PilotScaleReportTest extends BillingTestCase
{
    use OtcDispensaryFixtures, ReportEvidence;

    /** Fixed synthetic graph shared by the original-output capture and regression test. */
    public function fixedFixture(): User
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:55:00', 'Asia/Kuala_Lumpur'));
        $first = $this->finalizedFixture(10000);
        $second = $this->anotherReportInvoice();
        $third = $this->anotherReportInvoice();
        foreach ([$first, $second, $third] as $index => $fixture) {
            $fixture[2]->patient->forceFill(['patient_number' => 'KP-8800000'.($index + 1), 'full_name' => $index === 0 ? ' =Synthetic, "雪"' : 'Synthetic Scale Patient '.($index + 1)])->save();
        }
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        foreach ([[$first, 'deduct', 1000], [$second, 'add', 1200], [$third, 'add', 600]] as [$fixture, $direction, $amount]) {
            $invoice = $fixture[3]->refresh();
            $adjustment = app(InvoiceAdjustmentService::class)->add($finance, $fixture[2], $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'threshold_version' => 0, 'direction' => $direction, 'input_type' => 'fixed', 'input_value' => $amount, 'confirm_above_limit' => true, 'reason' => 'Synthetic private adjustment', 'idempotency_key' => (string) Str::uuid()]);
            if ($fixture === $third) {
                $director = $this->actor('director');
                $this->selectBranch($director);
                app(InvoiceAdjustmentService::class)->reverse($director, $fixture[2], $invoice->refresh(), $adjustment->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'reason' => 'Synthetic private reversal', 'idempotency_key' => (string) Str::uuid()]);
                $this->selectBranch($finance);
            }
        }
        $this->travelTo(CarbonImmutable::parse('2026-12-30 12:00:00', 'Asia/Kuala_Lumpur'));
        $stock = $this->fixture();
        $stock['sku']->forceFill(['sku_code' => 'SCALE-SMALL-SKU'])->save();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:05:00', 'Asia/Kuala_Lumpur'));
        foreach (['CASH' => 'cash', 'CARD' => null] as $code => $classification) {
            (new PaymentMethod)->forceFill(['organisation_id' => $this->organisation->id, 'code' => $code, 'name' => 'Synthetic '.$code, 'classification' => $classification, 'is_active' => true])->save();
        }
        $this->selectBranch($first[1]);
        $invoice = $first[3]->refresh();
        $cash = app(PaymentService::class)->add($first[1], $first[2], $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 2500, 'method' => 'CASH', 'idempotency_key' => (string) Str::uuid()]);
        app(PaymentService::class)->add($first[1], $first[2], $invoice->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'method' => 'CARD', 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($finance);
        app(InvoiceCorrectionService::class)->reverse($finance, $first[2], $invoice->refresh(), $cash, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'payment_lock_version' => $cash->lock_version, 'recording_error_only' => true, 'reason' => 'Synthetic recording reversal']);
        PaymentMethod::query()->where('organisation_id', $this->organisation->id)->where('code', 'CARD')->update(['is_active' => false]);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'name' => 'Synthetic Scale Panel']);
        $officer = $this->actor('panel_officer');
        foreach (['panel' => [$officer, 2000], 'deferment' => [$finance, 1000]] as $capability => [$approver, $amount]) {
            DB::table('billing_approval_limits')->insert(['organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id, 'user_id' => $approver->id, 'capability' => $capability, 'limit_sen' => $amount]);
            $this->selectBranch($finance);
            $proposal = app(ResponsibilityService::class)->propose($finance, $first[2], $invoice->refresh(), $capability, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'panel_id' => $panel->id, 'due_date' => '2027-01-05', 'reason' => 'Synthetic responsibility']);
            $this->selectBranch($approver);
            app(ResponsibilityService::class)->approve($approver, $first[2], $invoice->refresh(), $capability, $proposal->public_id, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
            if ($capability === 'panel') {
                $claim = app(PanelClaimService::class)->prepare($officer, $proposal->refresh(), ['expected_branch_id' => $this->branch->id]);
                app(PanelClaimService::class)->submit($officer, $claim, ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version]);
            }
        }
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        DB::transaction(function () use ($stock, $supervisor): void {
            $moves = app(InventoryMovementService::class);
            $moves->recordPurchaseReceipt($supervisor, $stock['location'], $stock['sku'], $stock['batch'], '5.125', (string) Str::uuid(), 0);
            $moves->recordAdjustment($supervisor, $stock['location'], $stock['sku'], $stock['batch'], 'out', '1.250', (string) Str::uuid());
            $moves->recordTransferDispatch($supervisor, $stock['location'], $stock['sku'], $stock['batch'], '0.375', (string) Str::uuid());
            $moves->recordTransferReceipt($supervisor, $stock['location'], $stock['sku'], $stock['batch'], '0.125', (string) Str::uuid());
        });
        $this->travelTo(CarbonImmutable::parse('2027-01-02 10:00:00', 'Asia/Kuala_Lumpur'));
        $this->selectBranch($finance);

        return $finance;
    }

    /** @param array<string,class-string> $classes
     * @return array<string,mixed>
     */
    public function outputs(User $actor, array $classes): array
    {
        $result = [];
        foreach ($classes as $key => $class) {
            foreach (['page', 'csv'] as $kind) {
                $output = app($class)->execute($actor, ['expected_branch_id' => $this->branch->id, 'from' => '2026-12-31', 'to' => '2027-01-02'], true, $kind === 'csv');
                $result[$key.'-'.$kind] = $kind === 'page' ? $output['result'] : $output['csv'];
            }
        }

        return $result;
    }

    public function test_batched_reports_equal_original_small_fixture_outputs_and_change_only_audit(): void
    {
        $actor = $this->fixedFixture();
        $before = $this->sourceEvidence();
        $outputs = $this->outputs($actor, ['daily' => DailySummaryReportService::class, 'panel' => PanelSummaryReportService::class, 'stock' => StockMovementReportService::class, 'transactions' => TransactionReportService::class]);
        $expected = json_decode(file_get_contents(base_path('tests/Fixtures/PilotScale/original-small-reports.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $outputs);
        $this->assertSame($before, $this->sourceEvidence());
        $invoices = Invoice::query()->where('organisation_id', $actor->organisation_id)->orderBy('id')->get();
        $individual = $invoices->mapWithKeys(fn ($invoice): array => [$invoice->id => app(FinancialLedger::class)->state($invoice)])->all();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $batched = app(FinancialLedger::class)->states($invoices);
        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertSame($individual, $batched);
    }

    public function test_ledger_batches_preserve_reconciliation_refusal_and_stock_aggregate_never_saturates(): void
    {
        $actor = $this->fixedFixture();
        $invoice = Invoice::query()->where('organisation_id', $actor->organisation_id)->orderBy('id')->firstOrFail();
        $invoice->forceFill(['total_sen' => 0]); // Unsaved inconsistent object: never modify financial evidence.
        foreach ([fn () => app(FinancialLedger::class)->state($invoice), fn () => app(FinancialLedger::class)->states(collect([$invoice]))] as $read) {
            try {
                $read();
                $this->fail('A non-reconciling invoice was accepted.');
            } catch (ValidationException $exception) {
                $this->assertSame(['invoice' => ['Financial evidence does not reconcile. Review is required.']], $exception->errors());
            }
        }
        $method = new \ReflectionMethod(StockMovementReportService::class, 'aggregateMilli');
        foreach (['9223372036854775808.000', '-9223372036854775808.000', '1.125'] as $invalid) {
            try {
                $method->invoke(app(StockMovementReportService::class), $invalid);
                $this->fail('An unrepresentable aggregate was silently converted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('report', $exception->errors());
            }
        }
        $this->assertSame(1125, $method->invoke(app(StockMovementReportService::class), '1125.000'));
    }
}
