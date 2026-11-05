<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PaymentRefund;
use App\Domain\Visit\Billing\Models\PaymentRefundDecision;
use App\Domain\Visit\Billing\Services\CashClosingService;
use App\Domain\Visit\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * BIL-6 (owner decisions 2026-11-05): a refund records money handed back for a reversed receipt, for its full
 * amount. CA Supervisor or Finance Officer records; Finance Officer or Director approves, never the recorder; no
 * threshold. It never changes the invoice or receipt. Cash closing counts a refunded reversed receipt as received
 * and the cash refund as paid out on its payout day; a day cannot be approved while one of its cash refunds waits.
 */
class RefundTest extends BillingTestCase
{
    private CarbonImmutable $receiptDay;

    private CarbonImmutable $refundDay;

    protected function setUp(): void
    {
        parent::setUp();
        $today = CarbonImmutable::now('Asia/Kuala_Lumpur')->startOfDay();
        $this->receiptDay = $today->subDays(2)->setTime(10, 0);
        $this->refundDay = $today->subDay()->setTime(10, 0);
    }

    public static function routes(): array
    {
        return [['ca', 403, 403], ['ca_supervisor', 302, 403], ['finance_officer', 302, 302], ['director', 403, 302], ['resident_doctor', 403, 403], ['panel_officer', 403, 403], ['administrator', 403, 403]];
    }

    #[DataProvider('routes')]
    public function test_only_supervisors_and_finance_record_and_only_finance_and_directors_decide(string $role, int $record, int $decide): void
    {
        [, , $visit] = $this->finalizedFixture();
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $this->post(route('billing.refund', [$visit, (string) Str::uuid()]), [])->assertStatus($record);
        $this->post(route('billing.refund.decide', [$visit, (string) Str::uuid(), 'approved']), [])->assertStatus($decide);
    }

    public function test_a_refund_of_a_reversed_receipt_is_recorded_once_then_approved_by_someone_else_without_touching_the_invoice(): void
    {
        $f = $this->reversedFixture();
        $before = $this->financialRows();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);

        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page
            ->where('billing.refunds.can', ['record' => true, 'approve' => false])
            ->where('billing.refunds.receipts.0', fn ($r) => $r['publicId'] === $f['receipt']->public_id && $r['amountSen'] === 4000 && $r['canRecord'] === true && $r['refunds'] === []));
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->refundInput($f, null))->assertSessionHasErrors('reason_code');
        $input = $this->refundInput($f, 'invoice_corrected');
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $input)->assertRedirect(route('billing.show', $f['visit']))->assertSessionHasNoErrors();
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $input)->assertSessionHasNoErrors(); // Same-key retry.
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->refundInput($f, 'overpaid'))->assertSessionHasErrors('refund'); // Second refund.
        $refund = PaymentRefund::query()->sole();
        $this->assertSame([4000, 'invoice_corrected', $supervisor->id, $f['cash']->id], [$refund->amount_sen, $refund->reason_code, $refund->recorded_by_user_id, $refund->payment_method_id]);
        $this->assertSame(['amount_sen' => 4000, 'payment_method_id' => $f['cash']->id], json_decode((string) DB::table('audit_logs')->where('event', 'billing.refund_recorded')->sole()->metadata, true));
        $this->assertSame($before, $this->financialRows());

        // The Finance Officer who reversed the receipt may approve the refund; the recorder may not.
        $finance = $f['finance'];
        $finance->givePermissionTo('payments.refunds.record.branch');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'approved']), $this->decideInput($f))->assertForbidden();
        $this->selectBranch($finance);
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page
            ->where('billing.refunds.receipts.0.canRecord', false)->where('billing.refunds.receipts.0.refunds.0.state', 'waiting')->where('billing.refunds.receipts.0.refunds.0.canDecide', true));
        $decide = $this->decideInput($f);
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'approved']), $decide)->assertSessionHasNoErrors();
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'approved']), $decide)->assertSessionHasNoErrors(); // Same-key retry.
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'rejected']), [...$this->decideInput($f), 'reason_code' => 'other'])->assertSessionHasErrors('refund');
        $this->assertSame([['approved', $finance->id]], PaymentRefundDecision::query()->get()->map(fn ($d) => [$d->decision, $d->decided_by_user_id])->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'billing.refund_approved', 'subject_id' => $refund->id, 'actor_user_id' => $finance->id]);
        $this->assertSame($before, $this->financialRows());

        // The evidence is append-only, in the model and in PostgreSQL.
        $this->assertThrows(fn () => $refund->forceFill(['amount_sen' => 1])->save(), LogicException::class);
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('payment_refunds')->where('id', $refund->id)->update(['amount_sen' => 1]));
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('payment_refund_decisions')->delete());
        $this->assertDatabaseRefuses('P0001', fn () => DB::statement('TRUNCATE payment_refunds CASCADE'));
    }

    public function test_a_rejected_refund_frees_the_receipt_and_postgres_refuses_what_the_service_refuses(): void
    {
        $f = $this->reversedFixture();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->refundInput($f, 'overpaid'))->assertSessionHasNoErrors();
        $refund = PaymentRefund::query()->sole();
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_refund_decisions')->insert($this->rawDecision($refund, $supervisor->id)), 'cannot decide');
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_refunds')->insert($this->rawRefund($refund)), 'already has a refund');

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'rejected']), $this->decideInput($f))->assertSessionHasErrors('reason_code');
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'rejected']), [...$this->decideInput($f), 'reason_code' => 'not_paid_out'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'billing.refund_rejected', 'subject_id' => $refund->id]);

        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->refundInput($f, 'overpaid'))->assertSessionHasNoErrors();
        $this->assertSame(2, PaymentRefund::query()->count());
        $this->assertDatabaseRefuses('23514', fn () => DB::table('payment_refunds')->insert([...$this->rawRefund($refund), 'amount_sen' => 3999]), 'full amount');
    }

    public function test_only_a_reversed_receipt_of_this_visit_can_be_refunded(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $posted = Payment::query()->sole();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$visit, $posted->public_id]), ['expected_branch_id' => $visit->branch_id, 'payment_method_id' => $cash->id, 'reason_code' => 'overpaid', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('refund');
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.refunds', null));
        $this->post(route('billing.refund', [$visit, (string) Str::uuid()]), ['expected_branch_id' => $visit->branch_id, 'payment_method_id' => $cash->id, 'reason_code' => 'overpaid', 'idempotency_key' => (string) Str::uuid()])
            ->assertNotFound();
        $this->assertSame(0, PaymentRefund::query()->count());
    }

    public function test_cash_closing_counts_a_refunded_receipt_as_received_and_the_refund_as_paid_out_and_waits_for_its_approval(): void
    {
        $f = $this->reversedFixture();
        $closing = app(CashClosingService::class);
        // Reversed and not refunded: the money never came in.
        $this->assertSame(['count' => 0, 'sen' => 0], $closing->expected($f['visit']->branch, $this->receiptDay->startOfDay()));

        $this->travelTo($this->refundDay);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->refundInput($f, 'invoice_corrected'))->assertSessionHasNoErrors();
        // Refunded: the receipt counts on its own day, and the cash went out on the refund day.
        $this->assertSame(['count' => 1, 'sen' => 4000], $closing->expected($f['visit']->branch, $this->receiptDay->startOfDay()));
        $this->assertSame(['count' => 0, 'sen' => -4000], $closing->expected($f['visit']->branch, $this->refundDay->startOfDay()));

        // The refund day is counted with a minus amount; its approval waits for the refund's own approval.
        $this->selectBranch($f['ca']);
        $this->post(route('cash-closings.store'), ['expected_branch_id' => $f['visit']->branch_id, 'business_date' => $this->refundDay->toDateString(), 'counted_cash' => '-40.00', 'variance_reason' => null, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();
        $day = CashClosing::query()->sole();
        $this->assertSame([-4000, -4000, 0], [$day->expected_cash_sen, $day->counted_cash_sen, $day->variance_sen]);
        $this->get(route('cash-closings.index', ['date' => $this->refundDay->toDateString()]))->assertInertia(fn (Assert $page) => $page->where('cashClosing.refunds', ['count' => 1, 'sen' => 4000, 'waiting' => 1]));

        $this->travelTo($this->refundDay->addDay());
        $approver = $this->actor('finance_officer');
        $this->selectBranch($approver);
        $closingInput = ['expected_branch_id' => $f['visit']->branch_id, 'business_date' => $this->refundDay->toDateString(), 'reason' => null, 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('cash-closings.decide', [$day->public_id, 'approved']), $closingInput)->assertSessionHasErrors('cash_closing');
        $this->post(route('billing.refund.decide', [$f['visit'], PaymentRefund::query()->sole()->public_id, 'approved']), $this->decideInput($f))->assertSessionHasNoErrors();
        $this->post(route('cash-closings.decide', [$day->public_id, 'approved']), [...$closingInput, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame('approved', $day->decision()->sole()->decision);
    }

    public function test_expected_cash_follows_the_refund_method_and_a_rejection_and_the_receipt_day_waits_for_a_later_refund(): void
    {
        $f = $this->reversedFixture();
        $closing = app(CashClosingService::class);
        $card = new PaymentMethod;
        $card->forceFill(['organisation_id' => $f['visit']->organisation_id, 'code' => 'card', 'name' => 'Synthetic card', 'classification' => 'non_cash'])->save();
        $receiptDay = $this->receiptDay->startOfDay();
        $refundDay = $this->refundDay->startOfDay();

        // A cash receipt counted for the receipt day, then a refund on the next day, still waiting.
        $this->selectBranch($f['ca']);
        $this->post(route('cash-closings.store'), ['expected_branch_id' => $f['visit']->branch_id, 'business_date' => $receiptDay->toDateString(), 'counted_cash' => '0.00', 'variance_reason' => null, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->travelTo($this->refundDay);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $key = (string) Str::uuid();
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), [...$this->refundInput($f, 'overpaid'), 'payment_method_id' => $card->id, 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), [...$this->refundInput($f, 'other'), 'payment_method_id' => $card->id, 'idempotency_key' => $key])->assertSessionHasErrors('refund'); // Changed payload.
        // A cash receipt refunded by card: the cash stayed in the drawer on the receipt day; no cash went out later.
        $this->assertSame(['count' => 1, 'sen' => 4000], $closing->expected($f['visit']->branch, $receiptDay));
        $this->assertSame(['count' => 0, 'sen' => 0], $closing->expected($f['visit']->branch, $refundDay));

        // The receipt day cannot be approved while its receipt's refund waits, even though it was paid out later.
        $this->travelTo($this->refundDay->addDay());
        $approver = $this->actor('finance_officer');
        $this->selectBranch($approver);
        $this->post(route('cash-closings.decide', [CashClosing::query()->sole()->public_id, 'approved']), ['expected_branch_id' => $f['visit']->branch_id, 'business_date' => $receiptDay->toDateString(), 'reason' => null, 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('cash_closing');
        $this->assertSame(0, DB::table('cash_closing_decisions')->count());

        // Rejected: the refund counts for nothing and the receipt is "never came in" again.
        $refund = PaymentRefund::query()->sole();
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'rejected']), [...$this->decideInput($f), 'reason_code' => 'amount_wrong'])->assertSessionHasNoErrors();
        $this->assertSame(['count' => 0, 'sen' => 0], $closing->expected($f['visit']->branch, $receiptDay));
        $this->assertSame(['amount_sen' => 4000, 'reason_code' => 'amount_wrong'], json_decode((string) DB::table('audit_logs')->where('event', 'billing.refund_rejected')->sole()->metadata, true));
    }

    public function test_refunds_are_refused_after_completion_with_an_inactive_method_and_through_another_visit(): void
    {
        $f = $this->reversedFixture();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $inactive = new PaymentMethod;
        $inactive->forceFill(['organisation_id' => $f['visit']->organisation_id, 'code' => 'old', 'name' => 'Synthetic retired', 'is_active' => false, 'classification' => 'cash'])->save();
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), [...$this->refundInput($f, 'overpaid'), 'payment_method_id' => $inactive->id])->assertSessionHasErrors('payment_method_id');

        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->refundInput($f, 'overpaid'))->assertSessionHasNoErrors();
        $refund = PaymentRefund::query()->sole();
        [, , $other] = $this->servingFixture(); // Another visit of the same branch, with no refundable receipt.
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('billing.refund.decide', [$other, $refund->public_id, 'approved']), $this->decideInput($f))->assertNotFound();
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$other, $f['receipt']->public_id]), $this->refundInput($f, 'overpaid'))->assertNotFound();

        // A completed visit: no new refund (owner decision: v1 is before completion only).
        $this->selectBranch($director);
        $this->post(route('billing.refund.decide', [$f['visit'], $refund->public_id, 'rejected']), [...$this->decideInput($f), 'reason_code' => 'duplicate'])->assertSessionHasNoErrors();
        $this->selectBranch($f['ca']);
        $this->post(route('billing.payment', [$f['visit'], $f['invoice']]), ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $f['invoice']->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $f['visit']), ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $f['invoice']->refresh()->lock_version, 'visit_lock_version' => $f['visit']->refresh()->lock_version])->assertSessionHasNoErrors();
        $this->assertSame(Visit::STATUS_COMPLETED, $f['visit']->refresh()->status);
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$f['visit'], $f['receipt']->public_id]), $this->refundInput($f, 'overpaid'))->assertSessionHasErrors('refund');
        $this->get(route('billing.show', $f['visit']))->assertInertia(fn (Assert $page) => $page->where('billing.refunds.visitCompleted', true)->where('billing.refunds.receipts.0.canRecord', false));
        $this->assertSame(1, PaymentRefund::query()->count());
    }

    /** @return array<string, mixed> A RM 40.00 cash receipt paid on the receipt day and reversed by Finance the same day. */
    private function reversedFixture(): array
    {
        $this->travelTo($this->receiptDay);
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '4000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $receipt = Payment::query()->sole();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.reverse', [$visit, $invoice, $receipt]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $receipt->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic wrong invoice'])->assertSessionHasNoErrors();
        $this->assertSame('reversed', $receipt->refresh()->status);

        return ['ca' => $ca, 'visit' => $visit->refresh(), 'invoice' => $invoice->refresh(), 'receipt' => $receipt, 'cash' => $cash, 'finance' => $finance];
    }

    private function cashMethod(Visit $visit): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();

        return $method;
    }

    /** @return array<string, mixed> */
    private function refundInput(array $f, ?string $reason): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'payment_method_id' => $f['cash']->id, 'reason_code' => $reason, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function decideInput(array $f): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'reason_code' => null, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function rawRefund(PaymentRefund $refund): array
    {
        return collect($refund->getAttributes())->except(['id', 'public_id', 'idempotency_key'])->all() + ['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function rawDecision(PaymentRefund $refund, int $userId): array
    {
        return ['organisation_id' => $refund->organisation_id, 'payment_refund_id' => $refund->id, 'decision' => 'approved', 'reason_code' => null,
            'decided_by_user_id' => $userId, 'decided_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('0', 64)];
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

    /** @return array<string, string> */
    private function financialRows(): array
    {
        return collect(['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
