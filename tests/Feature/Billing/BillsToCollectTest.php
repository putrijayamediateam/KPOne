<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-23 (owner decisions 2026-11-11): "Bills to collect" lists every completed visit that still owes money, not only
 * approved pay-later. Money due now (after a receipt correction) counts from the visit day and is past due from the
 * next day; it uses the same amount as the Billing page. An open visit's unpaid bill is not listed. Read-only.
 */
class BillsToCollectTest extends BillingTestCase
{
    public function test_a_completed_visit_with_only_money_due_now_is_listed_from_its_visit_day(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->pay($ca, $visit, $invoice, '4000');
        $this->complete($ca, $visit, $invoice);
        $this->correct($visit, $invoice, '1500'); // RM 15.00 really received: RM 25.00 now due.
        $before = $this->financialRows();
        $visitDay = $visit->refresh()->registered_at->setTimezone($this->branch->timezone)->toImmutable();

        $this->selectBranch($ca);
        $this->travelTo($visitDay->setTime(23, 0)); // Still the visit day in the branch: due, not yet past due.
        $this->get(route('billing.pay-later-collections'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('collections.all', ['count' => 1, 'totalSen' => 2500, 'overdueCount' => 0, 'overdueSen' => 0])
            ->where('collections.dueNow', [$visit->visit_number => 2500])
            ->where('collections.data.0.visitNumber', $visit->visit_number)
            ->where('collections.data.0.approvedSen', 0)->where('collections.data.0.remainingSen', 0)
            ->where('collections.data.0.dueDate', $visitDay->format('j M Y'))->where('collections.data.0.daysOverdue', 0));
        // The same amount as the Billing page shows due now.
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.invoice.state.due_now', 2500));

        // From the day after the visit it is past due, counted from the visit day.
        $this->travelTo($visitDay->addDays(2)->setTime(10, 0));
        $this->get(route('billing.pay-later-collections', ['overdue' => 1]))->assertInertia(fn (Assert $page) => $page
            ->where('collections.all', ['count' => 1, 'totalSen' => 2500, 'overdueCount' => 1, 'overdueSen' => 2500])
            ->where('collections.shown.count', 1)->where('collections.data.0.daysOverdue', 2));
        $this->assertSame($before, $this->financialRows());
    }

    public function test_a_bill_with_pay_later_and_money_due_now_is_one_row_owing_both(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->payTenAndDeferThirty($ca, $visit, $invoice);
        $this->correct($visit, $invoice, '500'); // RM 5.00 of RM 10.00 really received: RM 5.00 now due.

        $this->selectBranch($ca);
        $this->get(route('billing.pay-later-collections'))->assertInertia(fn (Assert $page) => $page
            ->where('collections.all.count', 1)->where('collections.all.totalSen', 3500)
            ->where('collections.dueNow', [$visit->visit_number => 500])
            ->where('collections.data.0.approvedSen', 3000)->where('collections.data.0.remainingSen', 3000)
            // Money due now is due from the visit day, earlier than the pay-later date.
            ->where('collections.data.0.dueDate', $visit->refresh()->registered_at->setTimezone($this->branch->timezone)->format('j M Y')));
    }

    public function test_an_open_visit_still_paying_at_the_counter_is_not_listed(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->pay($ca, $visit, $invoice, '1000'); // Part paid, visit not completed: RM 30.00 due now at the counter.
        $this->selectBranch($ca);
        $this->get(route('billing.pay-later-collections'))->assertInertia(fn (Assert $page) => $page
            ->where('collections.all.count', 0)->where('collections.dueNow', [])->count('collections.data', 0));
    }

    public function test_the_visit_day_is_the_branch_calendar_day_not_the_utc_date(): void
    {
        // Registered at 16:30 UTC, which is 00:30 the next day in Kuala Lumpur (a synthetic fixture write).
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $registered = now('UTC')->subDays(4)->setTime(16, 30)->toImmutable();
        DB::table('visits')->where('id', $visit->id)->update(['registered_at' => $registered]);
        $branchDay = $registered->setTimezone('Asia/Kuala_Lumpur')->startOfDay();
        $this->assertSame($registered->toDateString(), $branchDay->subDay()->toDateString());
        $this->cashMethod($visit);
        $this->pay($ca, $visit, $invoice, '4000');
        $this->complete($ca, $visit, $invoice);
        $this->correct($visit, $invoice, '1500');

        $this->selectBranch($ca);
        $this->travelTo($branchDay->setTime(23, 0));
        $this->get(route('billing.pay-later-collections'))->assertInertia(fn (Assert $page) => $page
            ->where('collections.data.0.dueDate', $branchDay->format('j M Y'))->where('collections.data.0.daysOverdue', 0)
            ->where('collections.all.overdueCount', 0));
        $this->travelTo($branchDay->addDay()->setTime(9, 0));
        $this->get(route('billing.pay-later-collections'))->assertInertia(fn (Assert $page) => $page
            ->where('collections.data.0.daysOverdue', 1)->where('collections.all.overdueCount', 1));
    }

    public function test_a_panel_covered_bill_lists_only_the_patients_money_due_now(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => 2500, 'panel_id' => $panel->id, 'reason' => 'Synthetic Panel confirmation'])->assertSessionHasNoErrors();
        $proposal = DB::table('coverage_allocations')->sole();
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $ca->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', $proposal->public_id]), ['expected_branch_id' => $visit->branch_id,
            'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $proposal->lock_version])->assertSessionHasNoErrors();
        $this->pay($ca, $visit, $invoice, '1500');
        $this->complete($ca, $visit, $invoice);
        $this->correct($visit, $invoice, '1000'); // RM 10.00 of RM 15.00 really received: RM 5.00 due; the Panel part is not.

        $this->selectBranch($ca);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.invoice.state.due_now', 500));
        $this->get(route('billing.pay-later-collections'))->assertInertia(fn (Assert $page) => $page
            ->where('collections.all.totalSen', 500)->where('collections.dueNow', [$visit->visit_number => 500]));
    }

    public function test_a_refund_after_completion_leaves_the_amount_due_unchanged(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $cash = $this->cashMethod($visit);
        $this->pay($ca, $visit, $invoice, '4000');
        $this->complete($ca, $visit, $invoice);
        $this->correct($visit, $invoice, '3000'); // RM 10.00 due.
        $replacement = Payment::query()->where('status', 'posted')->sole();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('billing.refund', [$visit, $replacement->public_id]), ['expected_branch_id' => $visit->branch_id, 'payment_method_id' => $cash->id,
            'reason_code' => 'goodwill', 'amount_sen' => '500', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $refund = DB::table('payment_refunds')->sole();
        $this->selectBranch($this->actor('director'));
        $this->post(route('billing.refund.decide', [$visit, $refund->public_id, 'approved']), ['expected_branch_id' => $visit->branch_id, 'reason_code' => null,
            'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->selectBranch($ca);
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.invoice.state.due_now', 1000));
        $this->get(route('billing.pay-later-collections'))->assertInertia(fn (Assert $page) => $page
            ->where('collections.all.totalSen', 1000)->where('collections.dueNow', [$visit->visit_number => 1000]));
    }

    public function test_a_bill_with_both_kinds_of_money_keeps_its_agreed_pay_later_date_beside_the_row(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->cashMethod($visit);
        $this->payTenAndDeferThirty($ca, $visit, $invoice);
        $this->correct($visit, $invoice, '500');
        $agreed = DB::table('patient_receivables')->where('invoice_id', $invoice->id)->value('due_date');

        $this->selectBranch($ca);
        $this->get(route('billing.pay-later-collections'))->assertInertia(fn (Assert $page) => $page
            ->where('collections.payLaterDue', [$visit->visit_number => CarbonImmutable::parse($agreed)->format('j M Y')]));
    }

    private function payTenAndDeferThirty(User $ca, Visit $visit, Invoice $invoice): void
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($this->actor('director'));
        $this->post(route('billing-approval-limits.store'), ['expected_branch_id' => $this->branch->id, 'user_id' => $supervisor->id, 'capability' => 'deferment', 'limit_sen' => 5000])->assertSessionHasNoErrors();
        $this->pay($ca, $visit, $invoice, '1000');
        $this->post(route('billing.propose', [$visit, $invoice, 'deferment']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => '3000', 'due_date' => now($this->branch->timezone)->addDays(7)->toDateString(), 'reason' => 'Synthetic pay later reason'])->assertSessionHasNoErrors();
        $debt = DB::table('patient_receivables')->where('invoice_id', $invoice->id)->first();
        $this->selectBranch($supervisor);
        $this->post(route('billing.approve', [$visit, $invoice, 'deferment', $debt->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $debt->lock_version])->assertSessionHasNoErrors();
        $this->complete($ca, $visit, $invoice);
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

    /** @return array<string, string> */
    private function financialRows(): array
    {
        return collect(['invoices', 'payments', 'payment_allocations', 'payment_reversals', 'patient_receivables', 'coverage_allocations'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
