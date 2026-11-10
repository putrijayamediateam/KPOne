<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentCorrection;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;

/**
 * BIL-16 (owner decisions 2026-11-10): a receipt recorded in error on a completed visit is corrected in one step by a
 * Finance Officer who did not record it: the wrong receipt is reversed and a replacement records what was really
 * received. Only money not received becomes due. It takes effect at once, on any date. Never a refund.
 */
class ReceiptCorrectionTest extends BillingTestCase
{
    public function test_a_wrong_method_is_corrected_in_one_step_and_nothing_becomes_due(): void
    {
        $f = $this->completedFixture();
        $card = new PaymentMethod;
        $card->forceFill(['organisation_id' => $f['visit']->organisation_id, 'code' => 'card', 'name' => 'Synthetic card', 'classification' => 'non_cash'])->save();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('billing.show', $f['visit']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.corrections.receipts.0.number', $f['receipt']->receipt_number)->where('billing.corrections.receipts.0.refused', null));

        $input = $this->input($f, '4000', 'card');
        $this->post(route('billing.receipt.correct', [$f['visit'], $f['invoice'], $f['receipt']]), $input)->assertRedirect(route('billing.show', $f['visit']))->assertSessionHasNoErrors();
        $this->post(route('billing.receipt.correct', [$f['visit'], $f['invoice'], $f['receipt']]), $input)->assertSessionHasNoErrors(); // Same-key retry returns the same correction.

        $correction = PaymentCorrection::query()->sole();
        $replacement = Payment::query()->whereKey($correction->replacement_payment_id)->sole();
        $this->assertSame('reversed', $f['receipt']->refresh()->status);
        $this->assertSame(['posted', 4000, 'Synthetic card', $finance->id], [$replacement->status, (int) $replacement->amount_sen, $replacement->method_snapshot, $replacement->recorded_by_user_id]);
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], $this->state($f['invoice']));
        $this->assertSame(Visit::STATUS_COMPLETED, $f['visit']->refresh()->status);
        $this->assertSame(['record_version' => $f['invoice']->refresh()->lock_version, 'reversed_sen' => 4000, 'received_sen' => 4000, 'replacement' => true],
            json_decode((string) DB::table('audit_logs')->where('event', 'billing.receipt_corrected')->sole()->metadata, true));
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page
            ->where('billing.corrections.corrections.0.original', $f['receipt']->receipt_number)->where('billing.corrections.corrections.0.replacement', $replacement->receipt_number)
            ->where('billing.corrections.corrections.0.reason', 'Synthetic: recorded as cash, paid by card')->where('billing.corrections.receipts.0.number', $replacement->receipt_number)
            ->where('billing.corrections.receipts.0.refused', 'You recorded this receipt, so someone else must correct it.'));
    }

    public function test_less_or_no_money_received_becomes_due_on_the_completed_visit(): void
    {
        $f = $this->completedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.receipt.correct', [$f['visit'], $f['invoice'], $f['receipt']]), $this->input($f, '2500', 'cash'))->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 2500, 'panel' => 0, 'deferred' => 0, 'due_now' => 1500], $this->state($f['invoice']));

        // The replacement can itself be corrected by someone other than its recorder: nothing was really received.
        $replacement = Payment::query()->whereKey(PaymentCorrection::query()->sole()->replacement_payment_id)->sole();
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.receipt.correct', [$f['visit'], $f['invoice']->refresh(), $replacement]), [...$this->input($f, '0', null), 'payment_lock_version' => $replacement->lock_version])->assertSessionHasNoErrors();
        $this->assertNull(PaymentCorrection::query()->latest('id')->firstOrFail()->replacement_payment_id);
        $this->assertSame(['total' => 4000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 4000], $this->state($f['invoice']));
        $this->assertSame(Visit::STATUS_COMPLETED, $f['visit']->refresh()->status);

        // Owner decision 2026-11-10: the money now due is collected on the completed visit, never more than is due.
        $this->selectBranch($f['ca']);
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page->where('billing.can.pay', true));
        $this->post(route('billing.payment', [$f['visit'], $f['invoice']]), ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $f['invoice']->refresh()->lock_version, 'amount_sen' => '4001', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('amount_sen');
        $this->post(route('billing.payment', [$f['visit'], $f['invoice']]), ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $f['invoice']->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], $this->state($f['invoice']));
    }

    public function test_money_due_after_correcting_a_receipt_beside_pay_later_is_collected_first_and_pay_later_still_works(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $debt = $this->payTenAndDeferThirty($ca, $visit, $invoice);
        $first = Payment::query()->sole();
        $f = ['visit' => $visit, 'invoice' => $invoice->refresh(), 'receipt' => $first, 'cash' => $cash];
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.receipt.correct', [$visit, $invoice, $first]), $this->input($f, '500', 'cash'))->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 500, 'panel' => 0, 'deferred' => 3000, 'due_now' => 500], $this->state($invoice));
        // The next receipt pays the RM5.00 due first, then RM10.00 of the pay-later.
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1500', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 2000, 'panel' => 0, 'deferred' => 2000, 'due_now' => 0], $this->state($invoice));
        $this->assertSame(2000, $debt->refresh()->remaining_sen);
        $this->assertSame(1000, (int) DB::table('payment_allocations')->latest('id')->value('deferment_applied_sen'));
    }

    public function test_only_a_finance_officer_who_did_not_record_it_corrects_and_only_after_completion(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $receipt = Payment::query()->sole();
        $open = ['visit' => $visit, 'invoice' => $invoice->refresh(), 'receipt' => $receipt, 'cash' => $cash];
        // Before completion the existing reversal applies; this path refuses.
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.receipt.correct', [$visit, $invoice, $receipt]), $this->input($open, '4000', 'cash'))->assertSessionHasErrors('correction');
        $this->selectBranch($ca);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $f = [...$open, 'invoice' => $invoice->refresh()];
        foreach (['ca', 'ca_supervisor', 'director', 'panel_officer'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->post(route('billing.receipt.correct', [$visit, $invoice, $receipt]), $this->input($f, '4000', 'cash'))->assertForbidden();
        }
        // Another branch's Finance Officer is refused.
        $puchong = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '!=', $visit->branch_id)->firstOrFail();
        $outsider = $this->actor('finance_officer', $puchong);
        $this->selectBranch($outsider, $puchong);
        $this->post(route('billing.receipt.correct', [$visit, $invoice, $receipt]), [...$this->input($f, '4000', 'cash'), 'expected_branch_id' => $puchong->id])->assertNotFound();
        $this->assertSame(0, PaymentCorrection::query()->count());
        $this->assertSame('posted', $receipt->refresh()->status);
    }

    public function test_more_than_the_receipt_missing_confirmations_and_stale_or_reused_requests_are_refused(): void
    {
        $f = $this->completedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $url = route('billing.receipt.correct', [$f['visit'], $f['invoice'], $f['receipt']]);
        $this->post($url, $this->input($f, '4001', 'cash'))->assertSessionHasErrors('received_sen');
        $this->post($url, $this->input($f, '1000', null))->assertSessionHasErrors('method');
        $this->post($url, [...$this->input($f, '4000', 'cash'), 'recording_error_only' => '0'])->assertSessionHasErrors('recording_error_only');
        $this->post($url, [...$this->input($f, '4000', 'cash'), 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post($url, [...$this->input($f, '4000', 'cash'), 'lock_version' => 0])->assertSessionHasErrors('invoice');
        $this->post($url, [...$this->input($f, '4000', 'cash'), 'payment_lock_version' => 99])->assertSessionHasErrors('correction');
        $input = $this->input($f, '4000', 'cash');
        $this->post($url, $input)->assertSessionHasNoErrors();
        $this->post($url, [...$input, 'received_sen' => '3000'])->assertSessionHasErrors('idempotency_key'); // Same key, different correction.
        $this->assertSame(1, PaymentCorrection::query()->count());
    }

    public function test_the_finance_officer_who_recorded_a_receipt_cannot_correct_it(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $f = ['visit' => $visit, 'invoice' => $invoice->refresh(), 'receipt' => Payment::query()->sole(), 'cash' => $cash];
        $this->selectBranch($finance);
        $this->post(route('billing.receipt.correct', [$visit, $invoice, $f['receipt']]), $this->input($f, '4000', 'cash'))->assertSessionHasErrors('correction');
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.corrections.receipts.0.refused', 'You recorded this receipt, so someone else must correct it.'));
        $this->assertSame(0, PaymentCorrection::query()->count());
    }

    public function test_a_receipt_with_a_refund_cannot_be_corrected(): void
    {
        $g = $this->completedFixture();
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->post(route('billing.refund', [$g['visit'], $g['receipt']->public_id]), ['expected_branch_id' => $g['visit']->branch_id, 'payment_method_id' => $g['cash']->id,
            'reason_code' => 'goodwill', 'amount_sen' => '500', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.receipt.correct', [$g['visit'], $g['invoice'], $g['receipt']]), $this->input($g, '4000', 'cash'))->assertSessionHasErrors('correction');
        $this->get(route('billing.show', $g['visit']))->assertInertia(fn (Assert $page) => $page->where('billing.corrections.receipts.0.refused', 'This receipt has a refund, so it cannot be corrected.'));
        $this->assertSame(0, PaymentCorrection::query()->count());
    }

    public function test_a_pay_later_instalment_keeps_its_pay_later_part(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $debt = $this->payTenAndDeferThirty($ca, $visit, $invoice);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '2000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $instalment = Payment::query()->latest('id')->firstOrFail();
        $f = ['visit' => $visit, 'invoice' => $invoice->refresh(), 'receipt' => $instalment, 'cash' => $cash];
        $this->assertSame(['total' => 4000, 'self_pay' => 3000, 'panel' => 0, 'deferred' => 1000, 'due_now' => 0], $this->state($invoice));
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.corrections', null)); // A CA cannot correct.

        $this->selectBranch($this->actor('finance_officer'));
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.corrections.receipts.1.payLaterSen', 2000));
        $url = route('billing.receipt.correct', [$visit, $invoice, $instalment]);
        // Pay-later cannot grow back, so the amount received cannot drop below the instalment's pay-later part.
        $this->post($url, $this->input($f, '1500', 'cash'))->assertSessionHasErrors('received_sen');
        $this->post($url, $this->input($f, '0', null))->assertSessionHasErrors('received_sen');
        $this->post($url, $this->input($f, '2000', 'cash'))->assertSessionHasNoErrors();
        $replacement = Payment::query()->whereKey(PaymentCorrection::query()->sole()->replacement_payment_id)->sole();
        $this->assertSame(1000, $debt->refresh()->remaining_sen);
        $this->assertSame($replacement->id, $debt->last_payment_id);
        $this->assertSame(2000, (int) DB::table('payment_allocations')->where('payment_id', $replacement->id)->value('deferment_applied_sen'));
        $this->assertSame(['total' => 4000, 'self_pay' => 3000, 'panel' => 0, 'deferred' => 1000, 'due_now' => 0], $this->state($invoice));
    }

    public function test_corrections_are_append_only_and_postgres_refuses_what_the_service_refuses(): void
    {
        $f = $this->completedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.receipt.correct', [$f['visit'], $f['invoice'], $f['receipt']]), $this->input($f, '4000', 'cash'))->assertSessionHasNoErrors();
        $correction = PaymentCorrection::query()->sole();
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('payment_corrections')->where('id', $correction->id)->update(['received_sen' => 1]));
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('payment_corrections')->delete());
        $this->assertDatabaseRefuses('P0001', fn () => DB::statement('TRUNCATE payment_corrections'));
        $this->assertThrows(fn () => $correction->forceFill(['received_sen' => 1])->save(), LogicException::class);
        $row = collect($correction->refresh()->getAttributes())->except(['id'])->all();
        // A second correction of the same receipt, or one pointing at a receipt its author recorded.
        $fresh = ['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()];
        $this->assertDatabaseRefuses('23505', fn () => DB::table('payment_corrections')->insert([...$row, ...$fresh]));
        $replacement = Payment::query()->whereKey($correction->replacement_payment_id)->sole();
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_corrections')->insert([...$row, ...$fresh, 'original_payment_id' => $replacement->id]), 'reversal, after completion');
        // The guard runs before the unique keys: more than the receipt, or an amount that is not the replacement's.
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_corrections')->insert([...$row, ...$fresh, 'received_sen' => 4001]), 'more than the reversed receipt');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_corrections')->insert([...$row, ...$fresh, 'received_sen' => 3000]), 'keeping its pay-later part');
    }

    /** @return array{ca: User, visit: Visit, invoice: Invoice, receipt: Payment, cash: PaymentMethod} */
    private function completedFixture(): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->refresh()->status);

        return ['ca' => $ca, 'visit' => $visit, 'invoice' => $invoice->refresh(), 'receipt' => Payment::query()->latest('id')->firstOrFail(), 'cash' => $cash];
    }

    /** RM10.00 paid by the CA, RM30.00 pay-later approved by a CA Supervisor, then the visit completed. */
    private function payTenAndDeferThirty(User $ca, Visit $visit, Invoice $invoice): PatientReceivable
    {
        $supervisor = $this->actor('ca_supervisor');
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('billing-approval-limits.store'), ['expected_branch_id' => $this->branch->id, 'user_id' => $supervisor->id, 'capability' => 'deferment', 'limit_sen' => 5000])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.propose', [$visit, $invoice, 'deferment']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => '3000', 'due_date' => now($this->branch->timezone)->addDays(7)->toDateString(), 'reason' => 'Synthetic pay later reason'])->assertSessionHasNoErrors();
        $debt = PatientReceivable::query()->sole();
        $this->selectBranch($supervisor);
        $this->post(route('billing.approve', [$visit, $invoice, 'deferment', $debt->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $debt->lock_version])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();

        return $debt;
    }

    /** @param array{visit: Visit, invoice: Invoice, receipt: Payment} $f
     * @return array<string, mixed> */
    private function input(array $f, string $received, ?string $method): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $f['invoice']->refresh()->lock_version, 'payment_lock_version' => $f['receipt']->refresh()->lock_version,
            'reason' => 'Synthetic: recorded as cash, paid by card', 'recording_error_only' => '1', 'received_sen' => $received, 'method' => $method, 'idempotency_key' => (string) Str::uuid()];
    }

    private function cashMethod(Visit $visit): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();

        return $method;
    }

    /** @return array{total:int,self_pay:int,panel:int,deferred:int,due_now:int} */
    private function state(Invoice $invoice): array
    {
        return app(FinancialLedger::class)->state($invoice->refresh());
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
}
