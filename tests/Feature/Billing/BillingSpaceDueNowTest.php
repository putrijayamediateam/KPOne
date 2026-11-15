<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-23 follow-up: "Bills to collect" now also lists money due now. The Billing department space reads the same page,
 * so a bill that owes only money due now must show its real amount in the space row (not RM 0.00) and agree with the
 * headline total.
 */
class BillingSpaceDueNowTest extends BillingTestCase
{
    public function test_a_bill_with_only_money_due_now_shows_its_real_amount_in_the_billing_space(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->pay($ca, $visit, $invoice, '4000');
        $this->complete($ca, $visit, $invoice);
        $this->correct($visit, $invoice, '1500'); // RM 15.00 really received: RM 25.00 now due.

        $this->selectBranch($this->actor('finance_officer'));
        $this->get('/spaces/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.payLater.totalText', 'RM 25.00')
            ->where('billing.payLater.rows.0.amountText', 'RM 25.00')
            ->where('billing.payLater.rows.0.remainingSen', 2500));
    }

    private function pay(User $actor, Visit $visit, Invoice $invoice, string $sen): void
    {
        $this->selectBranch($actor);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => $sen, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function complete(User $actor, Visit $visit, Invoice $invoice): void
    {
        $this->selectBranch($actor);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
    }

    private function correct(Visit $visit, Invoice $invoice, string $receivedSen): void
    {
        $first = Payment::query()->where('status', 'posted')->orderBy('id')->firstOrFail();
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.receipt.correct', [$visit, $invoice, $first]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $first->lock_version, 'reason' => 'Synthetic: less was really received', 'recording_error_only' => '1', 'received_sen' => $receivedSen,
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function cashMethod(Visit $visit): PaymentMethod
    {
        $method = (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash']);
        $method->save();

        return $method;
    }
}
