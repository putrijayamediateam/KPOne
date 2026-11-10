<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-17: display-only fixes on the Billing page and the pay-later collection list. No money, permission or
 * stored-data change.
 */
class BillingDisplayFixesTest extends BillingTestCase
{
    public function test_a_voided_invoice_stays_visible_with_its_receipts_but_never_its_reason(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($ca);
        $this->pay($visit, $invoice, '4000');
        $payment = Payment::query()->sole();
        $this->selectBranch($finance);
        $this->post(route('billing.reverse', [$visit, $invoice, $payment]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $payment->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic recorded on the wrong invoice'])->assertSessionHasNoErrors();
        $this->post(route('billing.void', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'reason' => 'SYNTHETIC-PRIVATE-VOID-REASON'])->assertSessionHasNoErrors();

        $this->selectBranch($ca);
        $this->get(route('billing.show', $visit))->assertOk()->assertDontSee('SYNTHETIC-PRIVATE-VOID-REASON')->assertInertia(fn (Assert $page) => $page
            ->where('billing.invoice', null)
            ->where('billing.voidedInvoices.0.number', $invoice->refresh()->invoice_number)
            ->where('billing.voidedInvoices.0.totalSen', 4000)
            ->where('billing.voidedInvoices.0.voidedBy', $finance->name)
            ->where('billing.voidedInvoices.0.receipts', [['number' => $payment->receipt_number, 'amountSen' => 4000, 'method' => 'Synthetic cash', 'status' => 'reversed']]));

        // A summary-only viewer (Panel Officer) gets exactly these fields, never the reason.
        $this->selectBranch($this->actor('panel_officer'));
        $this->get(route('billing.show', $visit))->assertOk()->assertDontSee('SYNTHETIC-PRIVATE-VOID-REASON')->assertInertia(fn (Assert $page) => $page
            ->where('billing.voidedInvoices.0', fn ($row) => array_keys(collect($row)->all()) === ['number', 'totalSen', 'voidedAt', 'voidedBy', 'receipts']));
    }

    public function test_a_visit_without_a_void_lists_none_and_a_settled_pay_later_reads_as_settled(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->payTenAndDeferThirty($ca, $visit, $invoice);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.voidedInvoices', [])
            ->where('billing.deferment.amountSen', 3000)->where('billing.deferment.remainingSen', 3000));
        $this->pay($visit, $invoice, '1000');
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.deferment.remainingSen', 2000));
        $this->pay($visit, $invoice, '2000');
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.deferment.status', 'approved')->where('billing.deferment.remainingSen', 0));
    }

    public function test_the_pay_later_list_notes_money_due_after_a_correction_without_changing_its_rows(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->payTenAndDeferThirty($ca, $visit, $invoice);
        $this->get(route('billing.pay-later-collections'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('collections.dueNow', []));

        $first = Payment::query()->sole();
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('billing.receipt.correct', [$visit, $invoice, $first]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $first->lock_version, 'reason' => 'Synthetic: RM 5.00 really received', 'recording_error_only' => '1', 'received_sen' => '500',
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->get(route('billing.pay-later-collections'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.dueNow', [$visit->visit_number => 500])
            ->where('collections.data.0.remainingSen', 3000));
    }

    private function payTenAndDeferThirty(User $ca, Visit $visit, Invoice $invoice): PatientReceivable
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($this->actor('director'));
        $this->post(route('billing-approval-limits.store'), ['expected_branch_id' => $this->branch->id, 'user_id' => $supervisor->id, 'capability' => 'deferment', 'limit_sen' => 5000])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->pay($visit, $invoice, '1000');
        $this->post(route('billing.propose', [$visit, $invoice, 'deferment']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => '3000', 'due_date' => now($this->branch->timezone)->addDays(7)->toDateString(), 'reason' => 'Synthetic pay later reason'])->assertSessionHasNoErrors();
        $debt = PatientReceivable::query()->sole();
        $this->selectBranch($supervisor);
        $this->post(route('billing.approve', [$visit, $invoice, 'deferment', $debt->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $debt->lock_version])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();

        return $debt;
    }

    private function pay(Visit $visit, Invoice $invoice, string $sen): void
    {
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => $sen, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
    }

    private function cashMethod(Visit $visit): void
    {
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash', 'classification' => 'cash'])->save();
    }
}
