<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Services\BranchDayGate;
use App\Domain\Visit\Billing\Services\CashClosingService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-32 (owner decisions 2026-11-20 to 2026-11-22): the Branch Manager approves the daily close and may open the close
 * pages read only; an approved day is locked (no void, reversal, correction or adjustment of that day; refund only, a
 * reversal only together with its refund, Q5 = A); while a count waits, money actions of that day pause (Q2 = A).
 */
class DailyCloseLockTest extends BillingTestCase
{
    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::now('Asia/Kuala_Lumpur')->subDay()->setTime(10, 0);
    }

    public function test_the_branch_manager_opens_the_close_pages_read_only_and_approves_but_cannot_count(): void
    {
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $this->get(route('cash-closings.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('cashClosing.can.count', false));
        $this->get(route('cash-closings.month'))->assertOk();
        $this->post(route('cash-closings.store'), [])->assertForbidden();
        $this->post(route('cash-closings.start-date'), [])->assertForbidden();

        // K1: Approve was refused (403) for an approve-only role while the route sat in the counting group.
        $f = $this->paidFixture();
        $closing = $this->countDay($f['ca']);
        $this->travelBack();
        $this->selectBranch($manager);
        $this->post(route('cash-closings.decide', [$closing->public_id, 'approved']), $this->decideInput())->assertSessionHasNoErrors();
        $this->assertTrue(app(BranchDayGate::class)->locked($this->branch, $this->day->toDateString()));
        $this->assertSame(1, DB::table('cash_closing_locks')->count());
    }

    public function test_money_actions_of_a_counted_day_pause_until_a_decision_and_a_return_lifts_the_pause(): void
    {
        $this->travelTo(CarbonImmutable::now('Asia/Kuala_Lumpur')->setTime(20, 0));
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->pay($ca, $visit, $invoice, '1000');
        $today = CarbonImmutable::now('Asia/Kuala_Lumpur')->startOfDay();
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput($today, '10.00'))->assertSessionHasNoErrors();

        $this->post(route('billing.payment', [$visit, $invoice]), $this->paymentInput($visit, $invoice, '500'))
            ->assertSessionHasErrors(['payment' => BranchDayGate::PAUSED]);
        $this->assertSame(1, Payment::query()->count());
        // The database refuses a direct receipt on that day too.
        $this->assertDatabaseRefuses(fn () => (new Payment)->forceFill($this->rawPayment($ca, $visit))->save(), 'waiting for approval');
        // Registration and consultation are not money actions.
        [, , $another] = $this->servingFixture();
        $this->assertSame(Visit::STATUS_REGISTERED, $another->refresh()->status);

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('cash-closings.decide', [CashClosing::query()->sole()->public_id, 'returned']), [...$this->decideInput($today), 'reason' => 'Synthetic recount'])->assertSessionHasNoErrors();
        $this->pay($ca, $visit, $invoice, '500');
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_the_next_day_is_open_while_yesterdays_count_waits(): void
    {
        $f = $this->paidFixture('1000', '4000');
        $this->countDay($f['ca']);
        $this->travelBack();
        // Today is a new day (Q2 = A): a new receipt for today is accepted although yesterday's count waits.
        $this->pay($f['ca'], $f['visit'], $f['invoice'], '500');
        $this->assertSame(2, Payment::query()->count());
        // A change to yesterday's receipt is still paused.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.reverse', [$f['visit'], $f['invoice'], $f['receipt']]), $this->reverseInput($f))
            ->assertSessionHasErrors(['correction' => BranchDayGate::PAUSED_EARLIER]);
    }

    public function test_a_locked_days_receipt_cannot_be_reversed_alone_but_can_be_reversed_and_refunded_together(): void
    {
        $f = $this->paidFixture();
        $closing = $this->countDay($f['ca']);
        $this->travelBack();
        $this->approve($closing);
        $finance = $this->actor('finance_officer');
        $finance->givePermissionTo('payments.refunds.record.branch');
        $this->selectBranch($finance);

        $this->post(route('billing.reverse', [$f['visit'], $f['invoice'], $f['receipt']]), $this->reverseInput($f))
            ->assertSessionHasErrors(['correction' => BranchDayGate::LOCKED]);
        $this->assertSame('posted', $f['receipt']->refresh()->status);
        $this->post(route('billing.void', [$f['visit'], $f['invoice']]), ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $f['invoice']->refresh()->lock_version, 'reason' => 'Synthetic'])
            ->assertSessionHasErrors(['correction' => BranchDayGate::LOCKED]);

        $expected = CashClosing::query()->sole()->expected_cash_sen;
        $this->post(route('billing.refund-with-reversal', [$f['visit'], $f['invoice'], $f['receipt']]), [...$this->reverseInput($f), ...$this->refundInput($f)])
            ->assertSessionHasNoErrors();
        $this->assertSame('reversed', $f['receipt']->refresh()->status);
        $refund = PaymentRefund::query()->sole();
        // The locked day's figure is unchanged: a reversed receipt with an open refund still counts on its day.
        $this->assertSame($expected, app(CashClosingService::class)->expected($this->branch, $this->day->startOfDay())['sen']);

        // R2: the refund can be approved but not rejected, since a rejection would change the locked day.
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'rejected']), ['expected_branch_id' => $f['visit']->branch_id, 'reason_code' => array_key_first(PaymentRefund::REJECT_REASONS), 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('refund');
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'approved']), ['expected_branch_id' => $f['visit']->branch_id, 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
    }

    public function test_the_database_refuses_a_reversal_alone_of_a_locked_days_receipt_at_commit(): void
    {
        $f = $this->paidFixture();
        $closing = $this->countDay($f['ca']);
        $this->travelBack();
        $this->approve($closing);
        $finance = $this->actor('finance_officer');

        $this->assertDatabaseRefuses(function () use ($f, $finance): void {
            DB::table('payment_reversals')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['visit']->organisation_id, 'branch_id' => $f['visit']->branch_id,
                'payment_id' => $f['receipt']->id, 'invoice_id' => $f['invoice']->id, 'amount_sen' => 1000, 'reason' => 'Synthetic', 'approved_by_user_id' => $finance->id,
                'reversed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::statement('SET CONSTRAINTS payment_reversals_branch_day_lock IMMEDIATE'); // the commit-time check, run now inside the test transaction
        }, 'only be reversed together with its refund');
        $this->assertDatabaseRefuses(fn () => DB::table('invoices')->where('id', $f['invoice']->id)->update(['status' => 'voided', 'voided_at' => now(), 'voided_by_user_id' => $finance->id, 'void_reason' => 'Synthetic', 'current_visit_guard' => null]), 'closed and locked');
    }

    public function test_a_payment_on_a_later_day_for_a_locked_days_invoice_is_accepted(): void
    {
        $f = $this->paidFixture('1000', '4000');
        $closing = $this->countDay($f['ca']);
        $this->travelBack();
        $this->approve($closing);
        $this->pay($f['ca'], $f['visit'], $f['invoice'], '500');
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_the_close_page_shows_the_pause_and_the_lock(): void
    {
        $f = $this->paidFixture();
        $closing = $this->countDay($f['ca']);
        $this->travelBack();
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.status', 'waiting')->where('cashClosing.locked', false));
        $this->approve($closing);
        $this->selectBranch($manager);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.status', 'approved')->where('cashClosing.locked', true));
    }

    public function test_a_paused_day_refuses_adjustments_and_panel_or_pay_later_and_the_page_turns_money_off(): void
    {
        $this->travelTo(CarbonImmutable::now('Asia/Kuala_Lumpur')->setTime(20, 0));
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput(CarbonImmutable::now('Asia/Kuala_Lumpur')->startOfDay(), '0.00'))->assertSessionHasNoErrors();
        $director = $this->actor('director');
        $this->selectBranch($director);

        $this->post(route('billing.adjustment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'threshold_version' => 0,
            'direction' => 'deduct', 'input_type' => 'fixed', 'input_value' => '100', 'reason' => 'Synthetic', 'idempotency_key' => (string) Str::uuid(), 'confirm_above_limit' => true])
            ->assertSessionHasErrors(['adjustment' => BranchDayGate::PAUSED]);
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'deferment']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => 500, 'reason' => 'Synthetic', 'due_date' => now()->addWeek()->toDateString()])
            ->assertSessionHasErrors(['responsibility' => BranchDayGate::PAUSED]);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page
            ->where('billing.can.pay', false)->where('billing.can.deferPropose', false)->where('billing.dayClose.paused', true));
        // The database refuses a direct pay-later or Panel proposal on that day too.
        $this->assertDatabaseRefuses(fn () => DB::table('patient_receivables')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id,
            'invoice_id' => $invoice->id, 'current_invoice_guard' => $invoice->id, 'amount_sen' => 100, 'remaining_sen' => 100, 'status' => 'proposed', 'lock_version' => 1,
            'expected_invoice_version' => $invoice->lock_version, 'requested_by_user_id' => $ca->id, 'reason' => 'Synthetic', 'due_date' => now()->addWeek()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]), 'waiting for approval');
    }

    public function test_a_locked_day_turns_void_and_reverse_off_on_the_page_and_refuses_a_direct_adjustment(): void
    {
        $f = $this->paidFixture('1000', '4000');
        $closing = $this->countDay($f['ca']);
        $this->travelBack();
        $this->approve($closing);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page
            ->where('billing.can.void', false)
            ->where('billing.dayClose.lockedReceipts.0.publicId', $f['receipt']->public_id)
            ->where('billing.dayClose.message', BranchDayGate::LOCKED));
    }

    public function test_an_adjustment_on_an_unpaid_invoice_of_a_locked_day_is_refused(): void
    {
        // An adjustment is already refused once money is recorded, so the lock matters for an unpaid finalised invoice.
        $this->travelTo($this->day);
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $this->cashMethod($visit);
        $closing = $this->countDay($ca, '0.00');
        $this->travelBack();
        $this->approve($closing);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('billing.adjustment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'threshold_version' => 0,
            'direction' => 'deduct', 'input_type' => 'fixed', 'input_value' => '100', 'reason' => 'Synthetic', 'idempotency_key' => (string) Str::uuid(), 'confirm_above_limit' => true])
            ->assertSessionHasErrors(['adjustment' => BranchDayGate::LOCKED]);
        $this->assertSame(0, DB::table('invoice_adjustments')->where('invoice_id', $invoice->id)->count());
    }

    public function test_reverse_and_refund_together_is_refused_on_a_day_that_is_not_locked(): void
    {
        $f = $this->paidFixture();
        $this->travelBack();
        $finance = $this->actor('finance_officer');
        $finance->givePermissionTo('payments.refunds.record.branch');
        $this->selectBranch($finance);
        $this->post(route('billing.refund-with-reversal', [$f['visit'], $f['invoice'], $f['receipt']]), [...$this->reverseInput($f), ...$this->refundInput($f)])
            ->assertSessionHasErrors('refund');
        $this->assertSame('posted', $f['receipt']->refresh()->status);
    }

    public function test_a_refund_on_a_counted_day_is_accepted_and_the_approval_then_asks_for_a_recount(): void
    {
        $f = $this->paidFixture();
        $finance = $this->actor('finance_officer');
        $finance->givePermissionTo('payments.refunds.record.branch');
        // Reverse before the count (allowed), count, then record the refund on the counted day: refunds are not paused.
        $this->selectBranch($finance);
        $this->post(route('billing.reverse', [$f['visit'], $f['invoice'], $f['receipt']]), $this->reverseInput($f))->assertSessionHasNoErrors();
        $closing = $this->countDay($f['ca'], '0.00'); // the reversed receipt has no refund yet, so nothing is expected
        // The money goes back by card: the reversed cash receipt now counts as received on its day, with no cash paid out.
        $card = (new PaymentMethod)->forceFill(['organisation_id' => $f['visit']->organisation_id, 'code' => 'card', 'name' => 'Synthetic card', 'classification' => 'non_cash']);
        $card->save();
        $this->selectBranch($finance);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), ['expected_branch_id' => $f['visit']->branch_id, ...$this->refundInput($f), 'payment_method_id' => $card->id])->assertSessionHasNoErrors();
        $this->travelBack();
        $director = $this->actor('director');
        $this->selectBranch($director);
        $refund = PaymentRefund::query()->sole();
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'approved']), ['expected_branch_id' => $f['visit']->branch_id, 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('cash-closings.decide', [$closing->public_id, 'approved']), $this->decideInput())->assertSessionHasErrors('cash_closing');
        $this->assertFalse(app(BranchDayGate::class)->locked($this->branch, $this->day->toDateString()));
    }

    public function test_the_day_follows_kuala_lumpur_midnight_not_utc(): void
    {
        // 23:59 local belongs to the counted day; 00:01 the next local day does not (yet both are the same UTC date).
        $this->travelTo(CarbonImmutable::parse('2026-11-10 23:59:00', 'Asia/Kuala_Lumpur'));
        [, $ca, $visit, $invoice] = $this->finalizedFixture(4000);
        $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput(CarbonImmutable::parse('2026-11-10', 'Asia/Kuala_Lumpur'), '0.00'))->assertSessionHasNoErrors();
        $this->post(route('billing.payment', [$visit, $invoice]), $this->paymentInput($visit, $invoice, '500'))->assertSessionHasErrors(['payment' => BranchDayGate::PAUSED]);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 00:01:00', 'Asia/Kuala_Lumpur'));
        $this->pay($ca, $visit, $invoice, '500');
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame('2026-11-10', CarbonImmutable::parse('2026-11-11 00:01:00', 'Asia/Kuala_Lumpur')->utc()->toDateString());
    }

    public function test_the_branch_manager_sees_daily_cash_closing_in_clinic_management_but_not_terminal_reconciliation(): void
    {
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $this->get(route('cash-closings.index'))->assertOk();
        $this->get(route('payment-reconciliations.index'))->assertForbidden();
        $this->assertFalse($manager->can('payments.reconcile.branch'));
    }

    /** @return array{ca: User, visit: Visit, invoice: Invoice, receipt: Payment} */
    private function paidFixture(string $paid = '1000', string $price = '1000'): array
    {
        $this->travelTo($this->day);
        [, $ca, $visit, $invoice] = $this->finalizedFixture((int) $price);
        $this->cashMethod($visit);
        $this->pay($ca, $visit, $invoice, $paid);

        return ['ca' => $ca, 'visit' => $visit->refresh(), 'invoice' => $invoice->refresh(), 'receipt' => Payment::query()->sole()];
    }

    private function countDay(User $ca, string $counted = '10.00'): CashClosing
    {
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput($this->day->startOfDay(), $counted))->assertSessionHasNoErrors();

        return CashClosing::query()->latest('id')->firstOrFail();
    }

    private function approve(CashClosing $closing): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('cash-closings.decide', [$closing->public_id, 'approved']), $this->decideInput())->assertSessionHasNoErrors();
    }

    private function pay(User $actor, Visit $visit, Invoice $invoice, string $sen): void
    {
        $this->selectBranch($actor);
        $this->post(route('billing.payment', [$visit, $invoice]), $this->paymentInput($visit, $invoice, $sen))->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function paymentInput(Visit $visit, Invoice $invoice, string $sen): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => $sen, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    }

    private function cashMethod(Visit $visit): void
    {
        if (! PaymentMethod::query()->where('organisation_id', $visit->organisation_id)->where('code', 'cash')->exists()) {
            (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
        }
    }

    /** @return array<string, mixed> */
    private function countInput(CarbonImmutable $day, string $counted): array
    {
        return ['expected_branch_id' => $this->branch->id, 'business_date' => $day->toDateString(), 'counted_cash' => $counted, 'variance_reason' => null, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function decideInput(?CarbonImmutable $day = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'business_date' => ($day ?? $this->day)->toDateString(), 'reason' => null, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function reverseInput(array $f): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $f['invoice']->refresh()->lock_version, 'payment_lock_version' => $f['receipt']->refresh()->lock_version,
            'recording_error_only' => '1', 'reason' => 'Synthetic wrong amount'];
    }

    /** @return array<string, mixed> */
    private function refundInput(array $f): array
    {
        return ['payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'reason_code' => array_key_first(PaymentRefund::REASONS), 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function rawPayment(User $actor, Visit $visit): array
    {
        return ['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $visit->branch_id, 'patient_id' => $visit->patient_id,
            'payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'), 'receipt_number' => 'SYN-'.Str::upper(Str::random(10)), 'amount_sen' => 100,
            'currency' => 'MYR', 'method_snapshot' => 'Synthetic cash', 'status' => 'posted', 'reference' => null, 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('0', 64), 'received_at' => now(), 'recorded_by_user_id' => $actor->id, 'lock_version' => 1];
    }

    private function assertDatabaseRefuses(callable $statement, string $message): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail('PostgreSQL accepted a statement it must refuse.');
        } catch (QueryException $exception) {
            $this->assertSame('P0001', $exception->errorInfo[0] ?? null, $exception->getMessage());
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
