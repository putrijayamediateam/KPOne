<?php

namespace Tests\Feature\Billing;

use App\Domain\Shared\Services\ClinicInsightsReportService;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceCredit;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Services\CashClosingService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\IssuedBillingDocumentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;

/**
 * BIL-10 (owner decisions 2026-11-06): after completion, a refund gives back part or all of what the patient paid on
 * one posted receipt, with an invoice credit of the same amount. Recorded by a CA Supervisor or Finance Officer and
 * approved or rejected by a Finance Officer or Director who did not record it. Until approved nothing on the invoice
 * changes; once approved the invoice total and what was paid both fall by the credit, so nothing becomes due again.
 */
class RefundAfterCompletionTest extends BillingTestCase
{
    public function test_a_partial_refund_after_completion_lowers_total_and_paid_together_only_once_approved(): void
    {
        $f = $this->completedFixture();
        $ledger = app(FinancialLedger::class);
        $before = $ledger->state($f['invoice']);
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], $before);
        $rows = $this->evidenceRows();

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page
            ->where('billing.refunds.visitCompleted', true)
            ->where('billing.refunds.receipts.0', fn ($r) => $r['publicId'] === $f['receipt']->public_id && $r['kind'] === 'after_completion'
                && $r['amountSen'] === 4000 && $r['refundableSen'] === 4000 && $r['canRecord'] === true)
            ->where('billing.refunds.afterCompletionReasons.medicine_returned', 'Medicine returned'));

        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'overpaid', 1500))->assertSessionHasErrors('reason_code');
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'medicine_returned', 0))->assertSessionHasErrors('amount_sen');
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'medicine_returned', 4001))->assertSessionHasErrors('amount_sen');
        $input = $this->input($f, 'medicine_returned', 1500);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $input)->assertSessionHasNoErrors();
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $input)->assertSessionHasNoErrors(); // Same-key retry.
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), [...$input, 'amount_sen' => 1400])->assertSessionHasErrors('refund'); // Changed payload.
        $refund = PaymentRefund::query()->sole();
        $credit = InvoiceCredit::query()->sole();
        $this->assertSame(['after_completion', 1500, 'medicine_returned'], [$refund->kind, $refund->amount_sen, $refund->reason_code]);
        $this->assertSame([$f['invoice']->id, $refund->id, 1500], [$credit->invoice_id, $credit->payment_refund_id, $credit->amount_sen]);
        $this->assertSame(['amount_sen' => 1500, 'payment_method_id' => $f['cash']->id, 'kind' => 'after_completion', 'reason_code' => 'medicine_returned'],
            json_decode((string) DB::table('audit_logs')->where('event', 'billing.refund_recorded')->sole()->metadata, true));
        // Waiting: nothing on the invoice changes, and the rest of the receipt stays refundable.
        $this->assertSame($before, $ledger->state($f['invoice']->refresh()));
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page->where('billing.refunds.receipts.0.refundableSen', 2500));
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'goodwill', 2501))->assertSessionHasErrors('amount_sen');

        // The recorder never decides; a Director approves.
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'approved']), $this->decide($f))->assertForbidden();
        $director = $this->actor('director');
        $director->givePermissionTo('payments.refunds.record.branch');
        $this->selectBranch($director);
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'approved']), $this->decide($f))->assertSessionHasNoErrors();
        $after = $ledger->state($f['invoice']->refresh());
        $this->assertSame(['total' => 2500, 'self_pay' => 2500, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], $after);
        $this->assertSame([$f['invoice']->id => $after], $ledger->states(collect([$f['invoice']])));
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page
            ->where('billing.invoice.refundCreditSen', 1500)->where('billing.payments.0.refundedSen', 1500)->where('billing.invoice.state.total', 2500));
        // The invoice, receipt, allocation and completion evidence are untouched; the visit stays completed.
        $this->assertSame($rows, $this->evidenceRows());
        $this->assertSame(Visit::STATUS_COMPLETED, $f['visit']->refresh()->status);
        $this->assertSame('posted', $f['receipt']->refresh()->status);

        // A second partial refund up to what is left, then nothing more.
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'service_not_given', 2500))->assertSessionHasNoErrors();
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page->where('billing.refunds.receipts.0.canRecord', false)->where('billing.refunds.receipts.0.refundableSen', 0));
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'other', 1))->assertSessionHasErrors('amount_sen');
        $this->assertSame(2, PaymentRefund::query()->count());
    }

    public function test_a_rejected_refund_after_completion_changes_nothing_and_frees_its_amount(): void
    {
        $f = $this->completedFixture();
        // A Finance Officer may record and approve, but never decide a refund they recorded.
        $recorder = $this->actor('finance_officer');
        $this->selectBranch($recorder);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'goodwill', 500))->assertSessionHasNoErrors();
        $own = PaymentRefund::query()->sole();
        $this->post(route('billing.refund.decide', [$f['visit'], $own->public_id, 'approved']), $this->decide($f))->assertSessionHasErrors('refund');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_refund_decisions')->insert(['organisation_id' => $own->organisation_id, 'payment_refund_id' => $own->id,
            'decision' => 'approved', 'reason_code' => null, 'decided_by_user_id' => $recorder->id, 'decided_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('b', 64)]), 'cannot decide');
        $this->selectBranch($this->actor('director'));
        $this->post(route('billing.refund.decide', [$f['visit'], $own->public_id, 'rejected']), [...$this->decide($f), 'reason_code' => 'duplicate'])->assertSessionHasNoErrors();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'invoice_corrected', 4000))->assertSessionHasNoErrors();
        $refund = PaymentRefund::query()->latest('id')->firstOrFail();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'rejected']), [...$this->decide($f), 'reason_code' => 'amount_wrong'])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($f['invoice']->refresh()));
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'invoice_corrected', 4000))->assertSessionHasNoErrors();
        $this->assertSame(3, InvoiceCredit::query()->count());
    }

    public function test_only_the_patient_share_can_be_refunded_and_the_panel_share_stays_covered(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = new Panel;
        $panel->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'SYN-PANEL', 'name' => 'Synthetic Panel', 'is_active' => true])->save();
        $finance = $this->actor('finance_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $finance->id, 'capability' => 'panel', 'limit_sen' => 4000]);
        $responsibility = app(ResponsibilityService::class);
        $proposal = $responsibility->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '3000', 'panel_id' => $panel->id, 'reason' => 'Synthetic', 'member_reference' => 'SYN-1']);
        $responsibility->approve($finance, $visit, $invoice->refresh(), 'panel', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
        $f = $this->payAndComplete($ca, $visit, $invoice, 1000);

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$visit, $f['receipt']->public_id]), $this->input($f, 'medicine_returned', 1001))->assertSessionHasErrors('amount_sen');
        $this->post(route('billing.refund', [$visit, $f['receipt']->public_id]), $this->input($f, 'medicine_returned', 1000))->assertSessionHasNoErrors();
        $this->selectBranch($finance);
        $this->post(route('billing.refund.decide', [$visit, PaymentRefund::query()->sole()->public_id, 'approved']), $this->decide($f))->assertSessionHasNoErrors();
        $this->assertSame(['total' => 3000, 'self_pay' => 0, 'panel' => 3000, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($invoice->refresh()));
        // The PostgreSQL payable and reconciliation checks see neither the refund nor the credit, so they still hold.
        $this->assertSame(4000, (int) DB::selectOne('SELECT kpone_invoice_payable(?) AS p', [$invoice->id])->p);
        // Panel claims compare and freeze what the invoice charged before refund credits, so no credit reads as a discount.
        $this->assertSame(4000, app(FinancialLedger::class)->chargedBeforeCredits($invoice));
    }

    public function test_a_cash_refund_after_completion_is_paid_out_on_its_day_and_the_receipt_stays_on_its_own(): void
    {
        $receiptDay = CarbonImmutable::now('Asia/Kuala_Lumpur')->startOfDay()->subDays(2)->setTime(10, 0);
        $refundDay = $receiptDay->addDay();
        $this->travelTo($receiptDay);
        $f = $this->completedFixture();
        $this->travelTo($refundDay);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'medicine_returned', 1500))->assertSessionHasNoErrors();
        $closing = app(CashClosingService::class);
        $this->assertSame(['count' => 1, 'sen' => 4000], $closing->expected($f['visit']->branch, $receiptDay->startOfDay()));
        $this->assertSame(['count' => 0, 'sen' => -1500], $closing->expected($f['visit']->branch, $refundDay->startOfDay()));
        $this->assertSame(['count' => 1, 'sen' => 1500, 'waiting' => 1], $closing->cashRefunds($f['visit']->branch, $refundDay->startOfDay()));

        // Decision C: the Daily summary shows it on the payout day, as part of Cash refunds paid; Total received is unchanged.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $range = ['expected_branch_id' => $f['visit']->branch_id, 'from' => $receiptDay->toDateString(), 'to' => $refundDay->toDateString()];
        $daily = $this->postJson(route('reports.daily-summary.run'), $range)->assertOk()->json('result');
        $this->assertSame('Refunded after completion (payout date, MYR)', $daily['columns'][15]);
        $this->assertSame(['40.00', '0.00', '0.00'], [$daily['rows'][0][11], $daily['rows'][0][12], $daily['rows'][0][15]]);
        $this->assertSame(['0.00', '15.00', '15.00'], [$daily['rows'][1][11], $daily['rows'][1][12], $daily['rows'][1][15]]);
        $this->assertSame('15.00', $daily['totals']['Refunded after completion (payout date, MYR)']);
        $this->assertSame('40.00', $daily['totals']['Total received (Cash + Non-cash) (receipt date, MYR)']);
        // Decision C: Transaction Detail shows it on the receipt's own row and in a total; original and net are unchanged.
        $tx = $this->postJson(route('reports.transactions.run'), $range)->assertOk()->json('result');
        $this->assertSame('Refunded after completion (MYR)', end($tx['columns']));
        $this->assertSame(['40.00', '0.00', '40.00', 'Posted', '15.00'], array_slice($tx['rows'][0], 8));
        $this->assertSame('15.00', $tx['totals']['Refunded after completion (MYR)']);
        $this->assertSame('40.00', $tx['totals']['Net received (MYR)']);
        // A rejected refund leaves both columns at zero.
        $this->post(route('billing.refund.decide', [$f['visit'], PaymentRefund::query()->sole()->public_id, 'rejected']), [...$this->decide($f), 'reason_code' => 'not_paid_out'])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $this->postJson(route('reports.transactions.run'), $range)->assertOk()->json('result.totals')['Refunded after completion (MYR)']);
        $this->assertSame('0.00', $this->postJson(route('reports.daily-summary.run'), $range)->assertOk()->json('result.totals')['Refunded after completion (payout date, MYR)']);
    }

    public function test_the_next_issued_invoice_is_revised_with_the_refund_credit_and_the_receipt_is_not(): void
    {
        $f = $this->completedFixture();
        $issuer = app(IssuedBillingDocumentService::class);
        $issue = fn (?Payment $payment) => $issuer->issue($f['ca'], $f['visit'], $f['invoice']->refresh(), $payment, ['expected_branch_id' => $f['visit']->branch_id, 'idempotency_key' => (string) Str::uuid()]);
        $this->selectBranch($f['ca']);
        $this->assertSame('original', $issue(null)->label);
        $this->assertSame('original', $issue($f['receipt'])->label);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'medicine_returned', 1500))->assertSessionHasNoErrors();
        $this->selectBranch($f['ca']);
        $this->assertSame('copy', $issue(null)->label, 'A waiting refund changes nothing on paper.');
        $this->selectBranch($this->actor('director'));
        $this->post(route('billing.refund.decide', [$f['visit'], PaymentRefund::query()->sole()->public_id, 'approved']), $this->decide($f))->assertSessionHasNoErrors();
        $this->selectBranch($f['ca']);
        $revised = $issue(null);
        $this->assertSame('revised', $revised->label);
        $shown = $issuer->show($f['ca'], $f['visit'], $revised->public_id);
        $this->assertSame([1500, 2500, 2500], [$shown['billing']['invoice']['refundCreditSen'], $shown['billing']['invoice']['state']['total'], $shown['billing']['invoice']['state']['self_pay']]);
        $this->assertSame('copy', $issue($f['receipt'])->label, 'The receipt stays the evidence of what was paid.');
    }

    public function test_insights_and_the_sales_csv_take_an_approved_credit_off_net_sales_on_its_approval_day_and_the_log_shows_it(): void
    {
        $saleDay = CarbonImmutable::now('Asia/Kuala_Lumpur')->startOfDay()->subDays(3)->setTime(10, 0);
        $approvalDay = $saleDay->addDays(2);
        $this->travelTo($saleDay);
        $f = $this->completedFixture();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->input($f, 'medicine_returned', 1500))->assertSessionHasNoErrors();
        $this->travelTo($approvalDay);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.refund.decide', [$f['visit'], PaymentRefund::query()->sole()->public_id, 'approved']), $this->decide($f))->assertSessionHasNoErrors();

        $director = $this->actor('director');
        $this->selectBranch($director);
        $report = fn (CarbonImmutable $day) => app(ClinicInsightsReportService::class)
            ->report($director, 'sales', 'all', $day->toDateString(), $day->toDateString(), null)['report']['summary'];
        // The sale day keeps its figures; the credit is its own row on the approval day.
        $sale = $report($saleDay);
        $this->assertSame([4000, 0, 4000], [$sale['totalSen'], $sale['reconciliation']['refundCreditsSen'], $sale['reconciliation']['netSen']]);
        $approval = $report($approvalDay);
        $this->assertSame(['grossSen' => 0, 'discountsSen' => 0, 'surchargesSen' => 0, 'refundCreditsSen' => 1500, 'netSen' => -1500], $approval['reconciliation']);
        $this->assertSame(-1500, $approval['totalSen']);
        $both = app(ClinicInsightsReportService::class)->report($director, 'sales', 'all', $saleDay->toDateString(), $approvalDay->toDateString(), null)['report']['summary'];
        $this->assertSame([2500, 4000, 1500], [$both['totalSen'], $both['newSen'] + $both['returningSen'], $both['reconciliation']['refundCreditsSen']]);

        $director->givePermissionTo('reports.sales.export.organisation');
        $csv = $this->get(route('insights.sales-summary.download', ['from' => $approvalDay->toDateString(), 'to' => $approvalDay->toDateString(), 'branch' => 'all']))->assertOk()->streamedContent();
        $this->assertStringContainsString('current,"Refund credits",15.00,MYR', str_replace("\r", '', $csv));

        $this->selectBranch($finance);
        $messages = array_column($this->actingAs($finance)->getJson(route('billing.activity', [$f['visit'], $f['invoice']]).'?'.http_build_query(['expected_branch_id' => $f['visit']->branch_id]))->assertOk()->json('data'), 'message');
        $this->assertContains('Refund after completion recorded', $messages);
        $this->assertContains('Refund after completion approved; invoice credited', $messages);
    }

    public function test_an_open_visit_still_refunds_only_reversed_receipts_and_postgres_refuses_what_the_service_refuses(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $receipt = Payment::query()->sole();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $open = ['visit' => $visit, 'cash' => $cash];
        $this->post(route('billing.refund', [$visit, $receipt->public_id]), $this->input($open, 'medicine_returned', 1000))->assertSessionHasErrors('reason_code');
        $raw = ['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'payment_id' => $receipt->id,
            'payment_method_id' => $cash->id, 'method_snapshot' => 'Synthetic cash', 'amount_sen' => 1000, 'kind' => 'after_completion', 'reason_code' => 'goodwill',
            'recorded_by_user_id' => $supervisor->id, 'paid_out_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'created_at' => now()];
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_refunds')->insert($raw), 'completed visit');

        $f = $this->payAndCompleteRemaining($ca, $visit, $invoice, $receipt, $cash);
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_refunds')->insert([...$raw, 'amount_sen' => 4001]), 'more than the receipt');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_refunds')->insert([...$raw, 'reason_code' => 'overpaid']));
        // Without its credit, the refund is refused when the transaction commits.
        $this->assertDatabaseRefuses('23514', function () use ($raw): void {
            DB::statement('SET CONSTRAINTS payment_refunds_need_credit IMMEDIATE');
            DB::table('payment_refunds')->insert($raw);
        }, 'invoice credit');
        DB::statement('SET CONSTRAINTS payment_refunds_need_credit DEFERRED');
        $this->post(route('billing.refund', [$visit, $receipt->public_id]), $this->input($f, 'goodwill', 1000))->assertSessionHasNoErrors();
        $refund = PaymentRefund::query()->sole();
        $credit = InvoiceCredit::query()->sole();
        $this->assertDatabaseRefuses('23514', fn () => DB::table('invoice_credits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id,
            'invoice_id' => $invoice->id, 'payment_refund_id' => $refund->id, 'amount_sen' => 999, 'created_at' => now()]));
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('invoice_credits')->where('id', $credit->id)->update(['amount_sen' => 1]));
        $this->assertDatabaseRefuses('P0001', fn () => DB::statement('TRUNCATE invoice_credits'));
        $this->assertThrows(fn () => $credit->forceFill(['amount_sen' => 1])->save(), LogicException::class);
    }

    /** @return array{ca: User, visit: Visit, invoice: Invoice, receipt: Payment, cash: PaymentMethod} */
    private function completedFixture(): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();

        return $this->payAndComplete($ca, $visit, $invoice, 4000);
    }

    /** @return array{ca: User, visit: Visit, invoice: Invoice, receipt: Payment, cash: PaymentMethod} */
    private function payAndComplete(User $ca, Visit $visit, Invoice $invoice, int $amount): array
    {
        $cash = $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => (string) $amount, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->refresh()->status);

        return ['ca' => $ca, 'visit' => $visit, 'invoice' => $invoice->refresh(), 'receipt' => Payment::query()->latest('id')->firstOrFail(), 'cash' => $cash];
    }

    /** @return array{ca: User, visit: Visit, invoice: Invoice, receipt: Payment, cash: PaymentMethod} */
    private function payAndCompleteRemaining(User $ca, Visit $visit, Invoice $invoice, Payment $receipt, PaymentMethod $cash): array
    {
        $this->selectBranch($ca);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $this->selectBranch($this->actor('ca_supervisor'));

        return ['ca' => $ca, 'visit' => $visit->refresh(), 'invoice' => $invoice->refresh(), 'receipt' => $receipt, 'cash' => $cash];
    }

    private function assertDatabaseRefuses(string $sqlState, callable $statement, ?string $message = null): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail('PostgreSQL accepted a statement it must refuse.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null, $exception->getMessage());
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    private function cashMethod(Visit $visit): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();

        return $method;
    }

    /** @param array{visit: Visit, cash: PaymentMethod} $f
     * @return array<string, mixed> */
    private function input(array $f, ?string $reason, int $amount): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'payment_method_id' => $f['cash']->id, 'reason_code' => $reason, 'amount_sen' => (string) $amount, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @param array{visit: Visit} $f
     * @return array<string, mixed> */
    private function decide(array $f): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, string> */
    private function evidenceRows(): array
    {
        return collect(['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'visits'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
