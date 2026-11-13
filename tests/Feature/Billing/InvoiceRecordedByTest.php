<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-19 (owner choice 2026-11-10): the completed-visit Invoice page shows who recorded each receipt and when it was
 * received, by the same staff-visibility rule as the Billing page (BIL-2). Prints never carry the name, and reading
 * the page changes no financial record.
 */
class InvoiceRecordedByTest extends BillingTestCase
{
    public function test_the_invoice_page_shows_who_recorded_each_receipt_and_when(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->cash($visit);
        $ca->forceFill(['name' => 'Synthetic Counter Assistant'])->save();
        $finance = $this->actor('finance_officer');
        $finance->forceFill(['name' => 'Synthetic Finance Officer'])->save();
        $this->pay($ca, $visit, $invoice, '1500');
        $this->pay($finance, $visit, $invoice, '2500');
        $this->complete($ca, $visit, $invoice);
        $received = Payment::query()->orderBy('id')->first()->received_at->setTimezone($this->branch->timezone)->format('j M Y, g:i A');
        $before = $this->financialRows();

        foreach ([$ca, $this->actor('ca_supervisor'), $this->actor('director')] as $viewer) {
            $this->selectBranch($viewer);
            $response = $this->get(route('visits.invoice', $visit))->assertOk();
            $invoicePayload = json_encode($response->viewData('page')['props']['invoice'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($ca->email, $invoicePayload);
            $this->assertStringNotContainsString($finance->email, $invoicePayload);
            $response->assertInertia(fn (Assert $page) => $page
                ->where('invoice.financial.payments.0.recordedBy', 'Synthetic Counter Assistant')
                ->where('invoice.financial.payments.0.receivedAt', $received)
                ->where('invoice.financial.payments.1.recordedBy', 'Synthetic Finance Officer'));
        }
        // A doctor's Invoice page carries no financial block, so no receipt or name.
        $this->selectBranch($this->actor('resident_doctor'));
        $this->get(route('visits.invoice', $visit))->assertOk()->assertDontSee('Synthetic Counter Assistant')
            ->assertInertia(fn (Assert $page) => $page->where('invoice.financial', null));
        $this->assertSame($before, $this->financialRows());
    }

    public function test_a_viewer_who_cannot_see_the_recorder_reads_staff_member_and_prints_never_carry_the_name(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        $this->cash($visit);
        $ca->forceFill(['name' => 'Synthetic Former Branch Assistant'])->save();
        $this->pay($ca, $visit, $invoice, '4000');
        $this->complete($ca, $visit, $invoice);
        $payment = Payment::query()->sole();

        // The recorder's assignment at this branch has ended (synthetic fixture write, dates well clear of midnight).
        $today = now($this->branch->timezone)->startOfDay();
        DB::table('staff_branch_assignments')->where('staff_profile_id', $ca->staffProfile->id)
            ->update(['valid_from' => $today->copy()->subDays(10)->toDateString(), 'valid_until' => $today->copy()->subDays(3)->toDateString()]);
        $this->selectBranch($this->actor('ca')); // Branch-level staff view only.
        $this->get(route('visits.invoice', $visit))->assertOk()->assertDontSee('Synthetic Former Branch Assistant')
            ->assertInertia(fn (Assert $page) => $page->where('invoice.financial.payments.0.recordedBy', 'Staff member'));
        $this->selectBranch($this->actor('director')); // Organisation-wide staff view still sees the name.
        $this->get(route('visits.invoice', $visit))->assertInertia(fn (Assert $page) => $page
            ->where('invoice.financial.payments.0.recordedBy', 'Synthetic Former Branch Assistant'));

        $this->selectBranch($this->actor('ca_supervisor'));
        $this->issuedReceiptPage($visit, $invoice, $payment)->assertOk()->assertDontSee('Synthetic Former Branch Assistant')
            ->assertDontSee('Recorded by');
    }

    private function cash(Visit $visit): void
    {
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
    }

    private function pay($actor, $visit, $invoice, string $sen): void
    {
        $this->selectBranch($actor);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'amount_sen' => (string) ExactMoney::sen($sen), 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
    }

    private function complete($actor, $visit, $invoice): void
    {
        $this->selectBranch($actor);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'visit_lock_version' => $visit->refresh()->lock_version])->assertSessionHasNoErrors();
    }

    /** @return array<string, string> */
    private function financialRows(): array
    {
        return collect(['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
