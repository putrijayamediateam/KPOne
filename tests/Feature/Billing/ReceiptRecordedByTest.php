<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-2 (owner decision 2026-11-05): each receipt on the Billing page and Manage payments shows who recorded it,
 * by name, using the same staff-visibility rule as the invoice activity log. Printed invoices and receipts never
 * carry the name, and reading the page changes no financial record.
 */
class ReceiptRecordedByTest extends BillingTestCase
{
    public function test_billing_and_manage_payments_show_who_recorded_each_receipt_by_name_only(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $ca->forceFill(['name' => 'Synthetic Counter Assistant'])->save();
        $finance = $this->actor('finance_officer');
        $finance->forceFill(['name' => 'Synthetic Finance Officer'])->save();
        $this->pay($ca, $visit, $invoice, '1500');
        $this->pay($finance, $visit, $invoice, '2500');
        $viewers = [$ca, $finance, $this->actor('ca_supervisor'), $this->actor('director'), $this->actor('panel_officer')];
        $before = $this->financialRows();

        foreach ($viewers as $viewer) {
            $this->selectBranch($viewer);
            foreach (['billing.show', 'billing.payments'] as $route) {
                $response = $this->get(route($route, $visit))->assertOk();
                // Names only: no staff email reaches the billing payload (the viewer's own sign-in data is separate).
                $billing = json_encode($response->viewData('page')['props']['billing'], JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString($ca->email, $billing);
                $this->assertStringNotContainsString($finance->email, $billing);
                $response->assertInertia(fn (Assert $page) => $page
                    ->where('billing.payments.0.recordedBy', 'Synthetic Counter Assistant')->where('billing.payments.0.amountSen', 1500)
                    ->where('billing.payments.1.recordedBy', 'Synthetic Finance Officer')->where('billing.payments.1.amountSen', 2500));
            }
        }
        $this->assertSame($before, $this->financialRows());
    }

    public function test_a_viewer_who_cannot_see_the_recorder_reads_staff_member_and_a_reversal_keeps_the_original_recorder(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $ca->forceFill(['name' => 'Synthetic Former Branch Assistant'])->save();
        $this->pay($ca, $visit, $invoice, '4000');
        $payment = Payment::query()->sole();

        // A Finance Officer (organisation-wide staff view) reverses the recording error; the recorder stays the CA.
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.reverse', [$visit, $invoice, $payment]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'payment_lock_version' => $payment->lock_version, 'recording_error_only' => '1', 'reason' => 'Synthetic recording error'])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page
            ->where('billing.payments.0.status', 'reversed')->where('billing.payments.0.recordedBy', 'Synthetic Former Branch Assistant'));

        // The recorder's assignment at this branch has ended (synthetic fixture write, dates well clear of midnight).
        $today = now($this->branch->timezone)->startOfDay();
        DB::table('staff_branch_assignments')->where('staff_profile_id', $ca->staffProfile->id)
            ->update(['valid_from' => $today->copy()->subDays(10)->toDateString(), 'valid_until' => $today->copy()->subDays(3)->toDateString()]);
        $viewer = $this->actor('ca'); // Branch-level staff view only.
        $this->selectBranch($viewer);
        $this->get(route('billing.show', $visit))->assertOk()->assertDontSee('Synthetic Former Branch Assistant')
            ->assertInertia(fn (Assert $page) => $page->where('billing.payments.0.recordedBy', 'Staff member'));
        $this->selectBranch($finance); // Organisation-wide staff view still sees the name.
        $this->get(route('billing.payments', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.payments.0.recordedBy', 'Synthetic Former Branch Assistant'));
    }

    public function test_printed_invoices_and_receipts_never_carry_the_name(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $ca->forceFill(['name' => 'Synthetic Print Sentinel Name'])->save();
        $this->pay($ca, $visit, $invoice, '4000');
        $payment = Payment::query()->sole();
        // A different person prints, so the recorder's name can only come from the receipt payload.
        $this->selectBranch($this->actor('ca_supervisor'));

        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertDontSee('Synthetic Print Sentinel Name')
            ->assertInertia(fn (Assert $page) => $page->missing('billing.payments.0.recordedBy'));
        $this->get(route('billing.receipt', [$visit, $invoice, $payment]))->assertOk()->assertDontSee('Synthetic Print Sentinel Name')
            ->assertInertia(fn (Assert $page) => $page->missing('receipt.recordedBy')->missing('billing.payments.0.recordedBy'));
        // The screen still shows it.
        $this->get(route('billing.show', $visit))->assertInertia(fn (Assert $page) => $page->where('billing.payments.0.recordedBy', 'Synthetic Print Sentinel Name'));
    }

    private function pay($actor, $visit, $invoice, string $sen): void
    {
        $this->selectBranch($actor);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => (string) ExactMoney::sen($sen), 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
    }

    /** @return array<string, string> */
    private function financialRows(): array
    {
        return collect(['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'audit_logs'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
