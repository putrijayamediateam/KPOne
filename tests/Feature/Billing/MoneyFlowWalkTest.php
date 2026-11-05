<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\InvoiceAdjustment;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PatientReceivable;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-1: end-to-end walks of the money flows the synthetic rehearsals did not cover. Every action goes
 * through its real route as the role that performs it at the counter; every amount is exact sen; and the
 * financial evidence the action must not touch is compared row for row before and after.
 */
class MoneyFlowWalkTest extends BillingTestCase
{
    private const FINANCIAL_TABLES = ['invoices', 'invoice_lines', 'payment_allocations', 'payment_reversals', 'coverage_allocations',
        'patient_receivables', 'invoice_adjustments', 'invoice_adjustment_reversals'];

    public function test_invoice_void_and_reissue_keeps_the_original_evidence_and_bills_the_replacement_exactly(): void
    {
        [, $ca, $visit, $original] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->cashMethod($visit);
        $finance = $this->actor('finance_officer');
        $other = $this->otherPatientInvoice($ca);
        $untouched = $this->evidence(['invoice_id' => [$other->id]]);

        // The CA records the RM40.00 cash receipt for the wrong invoice.
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $original]), $this->paymentInput($visit, $original, '4000'))->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $payment = Payment::query()->sole();
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], $this->state($original));

        // Void and reversal are Finance Officer actions: the CA is refused by permission.
        $this->post(route('billing.void', [$visit, $original]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $original->refresh()->lock_version, 'reason' => 'Synthetic CA void'])->assertForbidden();
        $this->post(route('billing.reverse', [$visit, $original, $payment]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $original->lock_version,
            'payment_lock_version' => $payment->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic CA reversal'])->assertForbidden();

        // Void is refused while a posted receipt is still on the invoice; nothing changes.
        $this->selectBranch($finance);
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.can.void', true)->where('billing.can.reverse', true)->where('billing.invoice.state.self_pay', 4000));
        $before = $this->evidence(['invoice_id' => [$original->id]]);
        $this->post(route('billing.void', [$visit, $original]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $original->refresh()->lock_version, 'reason' => 'Synthetic wrong invoice'])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors('correction');
        $this->assertSame($before, $this->evidence(['invoice_id' => [$original->id]]));

        // Finance reverses the recording error (not a refund), then voids.
        $this->post(route('billing.reverse', [$visit, $original, $payment]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $original->refresh()->lock_version,
            'payment_lock_version' => $payment->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic recorded on the wrong invoice'])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->assertSame('reversed', $payment->refresh()->status);
        $this->assertSame(ExactMoney::sen('4000'), (int) DB::table('payment_reversals')->where('payment_id', $payment->id)->value('amount_sen'));
        $this->assertSame(['total' => 4000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 4000], $this->state($original));
        $this->post(route('billing.void', [$visit, $original]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $original->refresh()->lock_version, 'reason' => 'Synthetic wrong invoice'])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $original->refresh();
        $this->assertSame('voided', $original->status);
        $this->assertNull($original->current_visit_guard);
        $this->assertNotNull($original->invoice_number); // The voided invoice keeps its number.
        $voided = $this->evidence(['invoice_id' => [$original->id]]);
        $receipt = Payment::query()->whereKey($payment->id)->get()->toJson();

        // A voided invoice can no longer take money.
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $original]), $this->paymentInput($visit, $original, '100'))->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors('invoice');
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.invoice', null)->where('billing.can.build', true)->where('billing.can.complete', false));

        // The CA builds and finalises the replacement, takes RM40.00 and completes the visit.
        $this->post(route('billing.build', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => null])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $replacement = Invoice::query()->where('current_visit_guard', $visit->id)->sole();
        $this->assertSame($original->public_id, $replacement->replaces_public_id);
        $this->post(route('billing.finalize', [$visit, $replacement]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $replacement->lock_version])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $replacement->refresh();
        // A new number is issued after the voided one and the other patient's; the voided number is never reused.
        $this->assertSame([$original->invoice_number, $other->invoice_number, $replacement->invoice_number],
            collect([$original->invoice_number, $other->invoice_number, $replacement->invoice_number])->sort()->values()->all());
        $this->assertCount(3, array_unique([$original->invoice_number, $other->invoice_number, $replacement->invoice_number]));
        $this->assertSame(ExactMoney::line(1000, ExactMoney::sen('4000')), $replacement->total_sen);
        $this->assertSame(['total' => 4000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 4000], $this->state($replacement));
        $this->post(route('billing.payment', [$visit, $replacement]), $this->paymentInput($visit, $replacement, '4000'))->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], $this->state($replacement));
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $replacement->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->refresh()->status);
        $this->assertSame($replacement->public_id, $visit->completion_evidence['invoice_public_id']);

        // The voided invoice, its lines, the original receipt and its reversal are untouched by the reissue.
        $this->assertSame($voided, $this->evidence(['invoice_id' => [$original->id]]));
        $this->assertSame($receipt, Payment::query()->whereKey($payment->id)->get()->toJson());
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(ExactMoney::sen('4000'), (int) Payment::query()->where('status', 'posted')->sum('amount_sen'));
        $this->assertSame($untouched, $this->evidence(['invoice_id' => [$other->id]]));
        $this->assertSame(['billing.payment_recorded', 'billing.payment_recording_reversed', 'billing.invoice_voided'], DB::table('audit_logs')
            ->where('subject_type', $original->getMorphClass())->where('subject_id', $original->id)
            ->whereIn('event', ['billing.payment_recorded', 'billing.payment_recording_reversed', 'billing.invoice_voided'])->orderBy('id')->pluck('event')->all());
    }

    public function test_a_finance_officer_cannot_reverse_their_own_receipt(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->cashMethod($visit);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.payment', [$visit, $invoice]), $this->paymentInput($visit, $invoice, '4000'))->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $payment = Payment::query()->sole();
        $before = $this->evidence(['invoice_id' => [$invoice->id]]);
        $this->post(route('billing.reverse', [$visit, $invoice, $payment]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $payment->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic self reversal'])->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors('correction');
        $this->assertSame('posted', $payment->refresh()->status);
        $this->assertSame($before, $this->evidence(['invoice_id' => [$invoice->id]]));
    }

    public function test_adjustment_reversal_restores_the_original_total_and_a_new_adjustment_is_billed_exactly(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->cashMethod($visit);
        $other = $this->otherPatientInvoice($ca);
        $untouched = $this->evidence(['invoice_id' => [$other->id]]);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('billing-approval-limits.adjustment-threshold'), ['expected_branch_id' => $visit->branch_id, 'lock_version' => 0, 'limit_sen' => ExactMoney::sen('1000'), 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('billing-approval-limits.index'))->assertSessionHasNoErrors();
        $this->assertSame(['configured' => true, 'limitSen' => 1000, 'lockVersion' => 1], InvoiceAdjustmentService::threshold($visit->branch_id));

        // The CA gives a 10% discount (RM4.00), within the RM10.00 branch threshold.
        $this->selectBranch($ca);
        $this->post(route('billing.adjustment', [$visit, $invoice]), $this->adjustmentInput($visit, $invoice, ['input_type' => 'percentage', 'input_value' => '1000']))
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $first = InvoiceAdjustment::query()->sole();
        $this->assertSame(ExactMoney::sen('400'), $first->amount_sen);
        $this->assertSame(['total' => 3600, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 3600], $this->state($invoice));
        $adjustmentRow = InvoiceAdjustment::query()->whereKey($first->id)->get()->toJson();
        $invoiceTotals = [$invoice->refresh()->subtotal_sen, $invoice->total_sen];

        // The CA has no reversal permission (403); an independent CA Supervisor reverses.
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, $first->public_id]), $this->reversalInput($visit, $invoice))->assertForbidden();
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.invoice.adjustmentControls.reverse', false));
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page
            ->where('billing.invoice.adjustmentControls.reverse', true)->where('billing.invoice.adjustment.adjustmentSen', -400)->where('billing.invoice.state.total', 3600));
        $reversal = $this->reversalInput($visit, $invoice);
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, $first->public_id]), $reversal)->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, $first->public_id]), $reversal)->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors(); // Same-key retry is a no-op.
        $this->assertSame(1, DB::table('invoice_adjustment_reversals')->count());
        $this->assertSame(ExactMoney::sen('400'), (int) DB::table('invoice_adjustment_reversals')->value('amount_sen'));
        $this->assertSame(['total' => 4000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 4000], $this->state($invoice));
        $this->assertNull(InvoiceAdjustmentService::active($invoice));
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, $first->public_id]), $this->reversalInput($visit, $invoice))->assertSessionHasErrors(['adjustment' => 'An independent authorised staff member must reverse the active adjustment.']);

        // A fresh RM5.00 surcharge by the CA, who then takes RM45.00 and completes.
        $this->selectBranch($ca);
        $this->post(route('billing.adjustment', [$visit, $invoice]), $this->adjustmentInput($visit, $invoice, ['direction' => 'add', 'input_value' => '500']))
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4500, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 4500], $this->state($invoice));
        $this->post(route('billing.payment', [$visit, $invoice]), $this->paymentInput($visit, $invoice, '4500'))->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();

        // Once money is recorded the active adjustment can no longer be reversed, even by an independent supervisor.
        $this->selectBranch($supervisor);
        $second = InvoiceAdjustmentService::active($invoice);
        $afterPayment = $this->evidence(['invoice_id' => [$invoice->id]]);
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, $second->public_id]), $this->reversalInput($visit, $invoice))->assertSessionHasErrors(['adjustment' => 'Adjustments require the current finalised Invoice before any payment, Panel proposal or pay-later history and before Visit completion.']);
        $this->assertSame($afterPayment, $this->evidence(['invoice_id' => [$invoice->id]]));
        $this->selectBranch($ca);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $visit->refresh();
        $this->assertSame(Visit::STATUS_COMPLETED, $visit->status);
        $this->assertSame(['subtotalSen' => 4000, 'adjustmentSen' => 500, 'activeId' => $second->public_id], $visit->completion_evidence['adjustment']);

        // The original invoice amounts and the first adjustment row are never rewritten.
        $this->assertSame($invoiceTotals, [$invoice->refresh()->subtotal_sen, $invoice->total_sen]);
        $this->assertSame($adjustmentRow, InvoiceAdjustment::query()->whereKey($first->id)->get()->toJson());
        $this->assertSame(2, InvoiceAdjustment::query()->count());
        $this->assertSame($untouched, $this->evidence(['invoice_id' => [$other->id]]));
        $this->assertStringNotContainsString('Synthetic private', (string) DB::table('audit_logs')->whereLike('event', 'billing.adjustment%')->pluck('metadata')->implode(' '));
    }

    public function test_the_supervisor_who_added_an_adjustment_cannot_reverse_it_but_another_supervisor_can(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $adder = $this->actor('ca_supervisor');
        $this->selectBranch($adder);
        // No threshold configured (RM0): the supervisor uses override authority with explicit confirmation.
        $this->post(route('billing.adjustment', [$visit, $invoice]), $this->adjustmentInput($visit, $invoice, ['confirm_above_limit' => '1']))
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $adjustment = InvoiceAdjustment::query()->sole();
        $this->assertSame(['total' => 3000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 3000], $this->state($invoice));
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.invoice.adjustmentControls.reverse', false));
        $before = $this->evidence(['invoice_id' => [$invoice->id]]);
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, $adjustment->public_id]), $this->reversalInput($visit, $invoice))
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors(['adjustment' => 'An independent authorised staff member must reverse the active adjustment.']);
        $this->assertSame($before, $this->evidence(['invoice_id' => [$invoice->id]]));
        $this->selectBranch($this->actor('ca_supervisor'));
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, $adjustment->public_id]), $this->reversalInput($visit, $invoice))
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 4000], $this->state($invoice));
    }

    public function test_pay_later_debt_is_settled_later_from_a_return_visit_in_two_exact_instalments(): void
    {
        [$doctor, $ca, $first, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->cashMethod($first);
        $supervisor = $this->actor('ca_supervisor');
        $this->approvalLimit($supervisor, 'deferment', '5000');

        // Day one: RM10.00 cash now, RM30.00 pay later approved by the supervisor, visit completed.
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$first, $invoice]), $this->paymentInput($first, $invoice, '1000'))->assertRedirect(route('billing.show', $first))->assertSessionHasNoErrors();
        $this->post(route('billing.propose', [$first, $invoice, 'deferment']), ['expected_branch_id' => $first->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => '3000', 'due_date' => now($this->branch->timezone)->addDays(7)->toDateString(), 'reason' => 'Synthetic pay later reason'])->assertRedirect(route('billing.show', $first))->assertSessionHasNoErrors();
        $debt = PatientReceivable::query()->sole();
        $this->selectBranch($supervisor);
        $this->post(route('billing.approve', [$first, $invoice, 'deferment', $debt->public_id]), ['expected_branch_id' => $first->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $debt->lock_version])->assertRedirect(route('billing.show', $first))->assertSessionHasNoErrors();
        $this->assertSame(['total' => 4000, 'self_pay' => 1000, 'panel' => 0, 'deferred' => 3000, 'due_now' => 0], $this->state($invoice));
        $this->selectBranch($ca);
        $this->post(route('billing.complete', $first), ['expected_branch_id' => $first->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $first->refresh()->lock_version])->assertRedirect(route('billing.show', $first))->assertSessionHasNoErrors();
        $evidence = $first->refresh()->completion_evidence;
        $this->assertSame(Visit::STATUS_COMPLETED, $first->status);

        // Return visit: its billing page shows the old debt in the patient's Outstanding bill.
        [$second, $secondInvoice] = $this->returnVisit($doctor, $ca, $first);
        $returnEvidence = $this->evidence(['invoice_id' => [$secondInvoice->id]]);
        $this->get(route('billing.show', $second))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.outstanding.patient.count', 1)->where('billing.outstanding.patient.totalSen', 3000));
        $list = $this->getJson(route('billing.outstanding', [$second, 'patient']))->assertOk()->assertJsonPath('totalSen', 3000)->json('data');
        $this->assertSame([['invoiceNumber' => $invoice->invoice_number, 'amountSen' => 3000, 'reviewRequired' => false, 'url' => route('billing.show', $first)]],
            array_map(fn (array $row) => array_diff_key($row, ['visitDate' => true]), $list));

        // Confirm opens the first visit's billing page, where only the pay-later amount can be collected.
        $this->get($list[0]['url'])->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.visit.status', 'completed')->where('billing.can.pay', true)->where('billing.can.complete', false)
            ->where('billing.invoice.state.deferred', 3000)->where('billing.deferment.remainingSen', 3000));
        $this->post(route('billing.payment', [$first, $invoice]), $this->paymentInput($first, $invoice, '3001'))->assertSessionHasErrors('amount_sen');
        $firstInstalment = $this->paymentInput($first, $invoice, '2000');
        $this->post(route('billing.payment', [$first, $invoice]), $firstInstalment)->assertRedirect(route('billing.show', $first))->assertSessionHasNoErrors();
        $this->post(route('billing.payment', [$first, $invoice]), $firstInstalment)->assertRedirect(route('billing.show', $first))->assertSessionHasNoErrors(); // Retry returns the same receipt.
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(ExactMoney::sen('1000'), $debt->refresh()->remaining_sen);
        $this->assertNull($debt->settled_at);
        $this->assertSame(['total' => 4000, 'self_pay' => 3000, 'panel' => 0, 'deferred' => 1000, 'due_now' => 0], $this->state($invoice));
        $this->getJson(route('billing.outstanding', [$second, 'patient']))->assertJsonPath('totalSen', 1000);

        $this->post(route('billing.payment', [$first, $invoice]), [...$this->paymentInput($first, $invoice, '1000'), 'return_to' => 'payments'])->assertRedirect(route('billing.payments', $first))->assertSessionHasNoErrors();
        $debt->refresh();
        $this->assertSame(0, $debt->remaining_sen);
        $this->assertNotNull($debt->settled_at);
        $this->assertSame('approved', $debt->status);
        $this->assertSame(ExactMoney::sen('3000'), $debt->amount_sen);
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], $this->state($invoice));
        $this->assertSame([1000, 2000, 1000], Payment::query()->orderBy('id')->pluck('amount_sen')->all());
        $this->assertSame([0, 2000, 1000], DB::table('payment_allocations')->orderBy('id')->pluck('deferment_applied_sen')->map(fn ($v) => (int) $v)->all());
        $this->get(route('billing.show', $first))->assertInertia(fn (Assert $page) => $page->where('billing.can.pay', false));
        $this->post(route('billing.payment', [$first, $invoice]), $this->paymentInput($first, $invoice, '1'))->assertSessionHasErrors('amount_sen');
        $this->get(route('billing.show', $second))->assertInertia(fn (Assert $page) => $page->where('billing.outstanding.patient.count', 0)->where('billing.outstanding.patient.totalSen', 0));

        // Known limit: once the visit is completed, a receipt recorded in error cannot be reversed in KPOne.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $instalment = Payment::query()->orderByDesc('id')->first();
        $settledEvidence = $this->evidence(['invoice_id' => [$invoice->id]]);
        $this->post(route('billing.reverse', [$first, $invoice, $instalment]), ['expected_branch_id' => $first->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $instalment->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic late correction'])
            ->assertRedirect(route('billing.show', $first))->assertSessionHasErrors('correction');
        $this->assertSame($settledEvidence, $this->evidence(['invoice_id' => [$invoice->id]]));

        // The first visit's completion evidence and the return visit's own invoice are untouched.
        $this->assertSame($evidence, $first->refresh()->completion_evidence);
        $this->assertSame($returnEvidence, $this->evidence(['invoice_id' => [$secondInvoice->id]]));
        $this->assertSame(['total' => 4000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 4000], $this->state($secondInvoice));
    }

    public function test_rejected_then_partially_accepted_panel_decisions_never_change_the_invoice_or_what_the_patient_owes(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->cashMethod($visit);
        $panelOfficer = $this->actor('panel_officer');
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $this->approvalLimit($panelOfficer, 'panel', '4000');

        // At the counter: Panel covers RM30.00, the patient pays the RM10.00 balance, visit completed.
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '3000',
            'panel_id' => $panel->id, 'member_reference' => 'SYN-MEMBER-1', 'reason' => 'Synthetic Panel responsibility'])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $allocation = CoverageAllocation::query()->sole();
        $this->selectBranch($panelOfficer);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', $allocation->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        // Known limit: with an approved Panel share the invoice cannot be voided and reissued.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $approved = $this->evidence(['invoice_id' => [$invoice->id]]);
        $this->post(route('billing.void', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'reason' => 'Synthetic void attempt'])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors('correction');
        $this->assertSame($approved, $this->evidence(['invoice_id' => [$invoice->id]]));
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), $this->paymentInput($visit, $invoice, '1000'))->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $settled = ['total' => 4000, 'self_pay' => 1000, 'panel' => 3000, 'deferred' => 0, 'due_now' => 0];
        $this->assertSame($settled, $this->state($invoice));
        $billing = $this->evidence(['invoice_id' => [$invoice->id]]);
        $receipts = Payment::query()->orderBy('id')->get()->toJson();
        $completion = $visit->refresh()->completion_evidence;
        [$return] = $this->returnVisit($doctor, $ca, $visit);

        // The Panel Officer files the claim; the Panel rejects it in full.
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.store', $allocation), ['expected_branch_id' => $visit->branch_id, 'allocation_lock_version' => $allocation->refresh()->lock_version,
            'invoice_lock_version' => $invoice->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => now()->setTimezone($this->branch->timezone)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $claim = PanelClaim::query()->sole();
        $this->assertSame(ExactMoney::sen('3000'), $claim->claim_amount_sen);
        $this->post(route('panel-claims.decision', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'decision' => 'rejected',
            'accepted_amount_sen' => '1', 'note' => 'Synthetic mismatch'])->assertSessionHasErrors('accepted_amount_sen');
        $this->post(route('panel-claims.decision', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'decision' => 'rejected',
            'accepted_amount_sen' => '0', 'note' => 'Synthetic rejection'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['rejected', 0], [$claim->refresh()->status, $claim->accepted_amount_sen]);
        $this->post(route('panel-claims.payment', $claim), ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => '100',
            'received_date' => now()->setTimezone($this->branch->timezone)->toDateString()])->assertSessionHasErrors('payment');
        $this->assertOwesNothing($ca, $visit, $return, $invoice, $settled);

        // Resubmitted, the Panel accepts RM20.00 of RM30.00 and remits it in full.
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.submit', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version])->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('panel-claims.decision', $claim->refresh()), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'decision' => 'partially_accepted',
            'accepted_amount_sen' => '3000', 'note' => 'Synthetic wrong partial'])->assertSessionHasErrors('accepted_amount_sen');
        $this->post(route('panel-claims.decision', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->lock_version, 'decision' => 'partially_accepted',
            'accepted_amount_sen' => '2000', 'note' => 'Synthetic partial acceptance'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['partially_accepted', 2000], [$claim->refresh()->status, $claim->accepted_amount_sen]);
        $remit = ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'received_date' => now()->setTimezone($this->branch->timezone)->toDateString()];
        $this->post(route('panel-claims.payment', $claim), [...$remit, 'amount_sen' => '2001'])->assertSessionHasErrors('amount_sen');
        $this->post(route('panel-claims.payment', $claim), [...$remit, 'amount_sen' => '2000'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['paid', 2000], [$claim->refresh()->status, (int) DB::table('panel_claim_payments')->sum('amount_sen')]);
        $this->assertSame(ExactMoney::sen('1000'), $claim->claim_amount_sen - $claim->accepted_amount_sen); // The RM10.00 shortfall lives only on the claim.
        $this->assertSame(['prepared', 'submitted', 'decision_rejected', 'submitted', 'decision_partially_accepted'],
            DB::table('panel_claim_events')->where('panel_claim_id', $claim->id)->orderBy('id')->pluck('event_type')->all());

        // Every decision and remittance leaves the invoice, receipts and completion evidence exactly as they were.
        $this->assertOwesNothing($ca, $visit, $return, $invoice, $settled);
        $this->assertSame($billing, $this->evidence(['invoice_id' => [$invoice->id]]));
        $this->assertSame($receipts, Payment::query()->orderBy('id')->get()->toJson());
        $this->assertSame($completion, $visit->refresh()->completion_evidence);
    }

    /**
     * Today KPOne keeps the Panel share on the invoice after a rejection; the shortfall is not moved to the
     * patient, so the patient owes nothing more inside KPOne. A further charge needs an owner decision.
     */
    private function assertOwesNothing(User $ca, Visit $visit, Visit $return, Invoice $invoice, array $settled): void
    {
        $this->assertSame($settled, $this->state($invoice));
        $this->selectBranch($ca);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.can.pay', false)->where('billing.invoice.state.due_now', 0));
        $this->post(route('billing.payment', [$visit, $invoice]), $this->paymentInput($visit, $invoice, '1000'))->assertSessionHasErrors('amount_sen');
        $this->get(route('billing.show', $return))->assertInertia(fn (Assert $page) => $page->where('billing.outstanding.patient.count', 0));
    }

    private function state(Invoice $invoice): array
    {
        return app(FinancialLedger::class)->state($invoice->refresh());
    }

    private function cashMethod(Visit $visit): void
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
    }

    private function approvalLimit(User $approver, string $capability, string $sen): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('billing-approval-limits.store'), ['expected_branch_id' => $this->branch->id, 'user_id' => $approver->id, 'capability' => $capability, 'limit_sen' => ExactMoney::sen($sen)])
            ->assertRedirect(route('billing-approval-limits.index'))->assertSessionHasNoErrors();
        $this->assertSame(ExactMoney::sen($sen), (int) DB::table('billing_approval_limits')->where('user_id', $approver->id)->where('capability', $capability)->value('limit_sen'));
    }

    private function paymentInput(Visit $visit, Invoice $invoice, string $sen): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => (string) ExactMoney::sen($sen), 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
    }

    private function adjustmentInput(Visit $visit, Invoice $invoice, array $extra = []): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'threshold_version' => InvoiceAdjustmentService::threshold($visit->branch_id)['lockVersion'],
            'direction' => 'deduct', 'input_type' => 'fixed', 'input_value' => '1000', 'reason' => 'Synthetic private adjustment reason', 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    private function reversalInput(Visit $visit, Invoice $invoice): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'reason' => 'Synthetic private reversal reason', 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array{Visit, Invoice} A finalised RM40.00 invoice on a new visit for the same patient. */
    private function returnVisit(User $doctor, User $ca, Visit $previous): array
    {
        $this->selectBranch($ca);
        $visit = $this->register($ca, $previous->patient, ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $doctor->id, 'visit_reason' => 'Synthetic return visit', 'confirm_repeat' => true]);

        return [$visit, $this->consultAndFinalize($doctor, $ca, $visit)];
    }

    private function otherPatientInvoice(User $ca): Invoice
    {
        $doctor = $this->doctor();
        $this->selectBranch($ca);
        $visit = $this->consultationVisit($ca, $doctor);
        $invoice = $this->consultAndFinalize($doctor, $ca, $visit);
        $this->selectBranch($ca);

        return $invoice;
    }

    private function consultAndFinalize(User $doctor, User $ca, Visit $visit): Invoice
    {
        $queue = $this->send($ca, $visit);
        $this->selectBranch($doctor);
        $queue = app(QueueEntryService::class)->call($doctor, $visit->refresh(), ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version]);
        $encounter = $this->startEncounter($doctor, $visit->refresh(), $queue);
        app(CompleteConsultationService::class)->complete($doctor, $visit, ['expected_branch_id' => $this->branch->id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version,
            'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []]);
        $this->selectBranch($ca);
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $this->branch->id, 'lock_version' => null]);

        return $builder->finalize($ca, $visit, $invoice, ['expected_branch_id' => $this->branch->id, 'lock_version' => $invoice->lock_version]);
    }

    /**
     * Row-for-row JSON of the financial tables, limited to the given invoices.
     *
     * @param  array{invoice_id: list<int>}  $scope
     * @return array<string, string>
     */
    private function evidence(array $scope): array
    {
        $rows = [];
        foreach (self::FINANCIAL_TABLES as $table) {
            $column = $table === 'invoices' ? 'id' : 'invoice_id';
            $rows[$table] = DB::table($table)->whereIn($column, $scope['invoice_id'])->orderBy('id')->get()->toJson();
        }
        $paymentIds = DB::table('payment_allocations')->whereIn('invoice_id', $scope['invoice_id'])->pluck('payment_id');
        $rows['payments'] = DB::table('payments')->whereIn('id', $paymentIds)->orderBy('id')->get()->toJson();

        return $rows;
    }
}
