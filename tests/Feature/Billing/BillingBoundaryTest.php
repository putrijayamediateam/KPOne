<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Services\CheckoutReopenEligibility;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

class BillingBoundaryTest extends BillingTestCase
{
    public function test_panel_summary_does_not_disclose_deferment_details_without_outstanding_authority(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $debt = app(ResponsibilityService::class)->propose($ca, $visit, $invoice, 'deferment', [
            'expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version,
            'amount_sen' => 4000, 'due_date' => now()->addDay()->toDateString(), 'reason' => 'Synthetic private pay-later reason',
        ]);
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.deferment.reason', $debt->reason));
        $panel = $this->actor('panel_officer');
        $this->assertFalse($panel->can('outstanding.view.branch'));
        $this->selectBranch($panel, $visit->branch);
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('billing.deferment', null)->where('billing.oldOutstanding', [])
            ->where('billing.invoice.lines', [])->where('billing.can.deferApprove', false));
    }

    public function test_branch_price_override_is_explicit_and_future_draft_version_rejects(): void
    {
        [, $ca, $visit, , $charge] = $this->billingFixture();
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'scope_key' => 'branch:'.$visit->branch_id, 'name' => 'Synthetic branch override', 'currency' => 'MYR'])->save();
        $price = new PriceEntry;
        $price->forceFill(['organisation_id' => $visit->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => 1234, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $ca->id])->save();
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $this->assertSame(1234, $invoice->total_sen);
        $this->expectException(ValidationException::class);
        $builder->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => 999]);
    }

    public function test_financial_permission_does_not_grant_staff_administration(): void
    {
        $finance = $this->actor('finance_officer');
        $this->assertTrue($finance->can('prices.publish.organisation'));
        $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority('prices.publish.organisation'));
        $this->assertFalse($finance->can('visits.complete.branch'));
        $this->assertFalse($finance->can('billing.view.branch'));
    }

    public function test_wrong_branch_cross_organisation_and_non_financial_roles_cannot_probe(): void
    {
        [, , $visit] = $this->finalizedFixture();
        foreach (['technical_admin', 'resident_doctor', 'hr_manager', 'marketing', 'business_development'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $visit->branch);
            $this->get(route('billing.show', $visit))->assertForbidden();
        }
        $otherBranch = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '<>', $visit->branch_id)->firstOrFail();
        $other = $this->actor('ca', $otherBranch);
        $this->selectBranch($other, $otherBranch);
        $this->get(route('billing.show', $visit))->assertNotFound();
        $org = new Organisation;
        $org->forceFill(['code' => 'UAT_FOREIGN', 'name' => 'Synthetic foreign organisation', 'is_active' => true])->save();
        $foreignBranch = new Branch;
        $foreignBranch->forceFill(['organisation_id' => $org->id, 'code' => 'UAT_FOREIGN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = $this->actor('ca', $foreignBranch);
        $this->selectBranch($foreign, $foreignBranch);
        $this->get(route('billing.show', $visit))->assertNotFound();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $foreignBranch->id])->assertNotFound();
        $this->assertSame('registered', $visit->refresh()->status);
    }

    public function test_finalized_invoice_closes_reopen_hint_and_command(): void
    {
        [$doctor, , $visit] = $this->finalizedFixture();
        $this->selectBranch($doctor, $visit->branch);
        $checkout = ConsultationCheckout::query()->sole();
        $this->assertFalse(app(CheckoutReopenEligibility::class)->allows($doctor, $visit));
        $this->expectException(ValidationException::class);
        app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => $checkout->lock_version]);
    }

    public function test_old_debt_settlement_and_receipt_print_preserve_completion_history(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        $service = app(ResponsibilityService::class);
        $debt = $service->propose($ca, $visit, $invoice, 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'due_date' => now()->addDay()->toDateString(), 'reason' => 'Synthetic deferred responsibility']);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $visit->branch);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $supervisor->id, 'capability' => 'deferment', 'limit_sen' => 4000]);
        $service->approve($supervisor, $visit, $invoice, 'deferment', $debt->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $debt->lock_version]);
        $this->selectBranch($ca, $visit->branch);
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'lock_version' => $invoice->refresh()->lock_version]);
        $history = $visit->refresh()->attributesToArray();
        $next = $this->register($ca, $visit->patient, ['visit_type' => 'consultation', 'visit_reason' => 'Synthetic next visit', 'assigned_doctor_user_id' => $doctor->id, 'confirm_repeat' => true]);
        $this->get(route('billing.show', $next))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.invoice', null)->has('billing.oldOutstanding', 1)->where('billing.oldOutstanding.0.amountSen', 4000));
        $this->assertDatabaseCount('invoices', 1);
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => 4000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame($history, $visit->fresh()->attributesToArray());
        $this->assertSame(0, $debt->refresh()->remaining_sen);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id, 'patient_receivable_id' => $debt->id, 'deferment_applied_sen' => 4000]);
        $before = [$invoice->refresh()->attributesToArray(), $payment->refresh()->attributesToArray(), DB::table('audit_logs')->count()];
        $this->get(route('billing.receipt', [$visit, $invoice, $payment]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('receipt.amountSen', 4000)->where('billing.can.complete', false));
        $this->assertSame($before, [$invoice->fresh()->attributesToArray(), $payment->fresh()->attributesToArray(), DB::table('audit_logs')->count()]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_money_rejects_non_integer_or_unbounded_input(): void
    {
        foreach ([-1, 1.5, '1e3', '01', '100.00', '', '1000000000000', null, []] as $value) {
            try {
                ExactMoney::sen($value);
                $this->fail('Invalid money accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, ExactMoney::sen('0'));
        $this->assertSame(4550, ExactMoney::sen('4550'));
    }
}
