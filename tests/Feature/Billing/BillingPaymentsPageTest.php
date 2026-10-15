<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class BillingPaymentsPageTest extends BillingTestCase
{
    public function test_manage_uses_existing_billing_permissions_and_branch_scope_without_clinical_content(): void
    {
        [, , $visit] = $this->finalizedFixture();
        foreach (['ca', 'ca_supervisor', 'finance_officer', 'panel_officer', 'director'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('billing.payments', $visit))->assertOk()->assertHeaderContains('Cache-Control', 'no-store')
                ->assertInertia(fn (Assert $p) => $p->component('Billing/Payments')
                    ->where('billing.visit.visitNumber', $visit->visit_number)
                    ->missing('billing.consultation')->missing('billing.clinicalNote')->missing('billing.vitals')->missing('billing.diagnoses')->missing('billing.patient.identifiers')
                    ->where('billing.can.pay', in_array($role, ['ca', 'ca_supervisor', 'finance_officer'], true)));
        }
        foreach (['resident_doctor', 'administrator', 'technical_admin', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('billing.payments', $visit))->assertForbidden();
        }
        $branch = Branch::query()->where('organisation_id', $visit->organisation_id)->where('id', '<>', $visit->branch_id)->firstOrFail();
        $this->selectBranch($this->actor('finance_officer', $branch), $branch);
        $this->get(route('billing.payments', $visit))->assertNotFound();
    }

    public function test_payment_returns_to_manage_on_failure_success_and_idempotent_retry(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        $url = route('billing.payment', [$visit, $invoice]);
        $payload = ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'payments'];
        $this->get(route('billing.payments', $visit))->assertOk();
        $this->post($url, [...$payload, 'amount_sen' => 5000])->assertRedirect(route('billing.payments', $visit))->assertSessionHasErrors();
        $this->assertDatabaseCount('payments', 0);
        $this->post($url, $payload)->assertRedirect(route('billing.payments', $visit));
        $this->post($url, $payload)->assertRedirect(route('billing.payments', $visit));
        $this->assertDatabaseCount('payments', 1);
        $this->post($url, [...$payload, 'amount_sen' => 2000])->assertRedirect(route('billing.payments', $visit))->assertSessionHasErrors();
        $this->get(route('billing.payments', $visit))->assertInertia(fn (Assert $p) => $p->where('billing.invoice.state.self_pay', 1000)->where('billing.invoice.state.due_now', 3000)->has('billing.payments', 1));
        $this->assertSame('registered', $visit->refresh()->status);
        $this->assertSame($ca->id, Payment::query()->sole()->recorded_by_user_id);
    }

    public function test_return_destination_is_a_fixed_choice_not_a_referrer_or_arbitrary_url(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $url = route('billing.payment', [$visit, $invoice]);
        $this->withHeader('Referer', 'https://outside.test/untrusted')->post($url, ['return_to' => 'https://outside.test/untrusted'])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors('return_to');
        $this->post($url, [])->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors();
        $this->assertDatabaseCount('payments', 0);
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'visit_lock_version' => $visit->lock_version, 'return_to' => 'payments'])->assertRedirect(route('billing.show', $visit))->assertSessionHasErrors();
    }

    public function test_panel_proposal_and_approval_return_to_manage_without_becoming_cash(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'panel_id' => $panel->id, 'reason' => 'Synthetic Panel confirmation', 'return_to' => 'payments'])->assertRedirect(route('billing.payments', $visit));
        $proposal = DB::table('coverage_allocations')->sole();
        $approval = route('billing.approve', [$visit, $invoice, 'panel', $proposal->public_id]);
        $payload = ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $proposal->lock_version, 'return_to' => 'payments'];
        $this->post($approval, $payload)->assertRedirect(route('billing.payments', $visit))->assertSessionHasErrors();
        $this->assertDatabaseHas('coverage_allocations', ['id' => $proposal->id, 'status' => 'proposed']);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $ca->id, 'capability' => 'panel', 'limit_sen' => 3000]);
        $this->post($approval, $payload)->assertRedirect(route('billing.payments', $visit));
        $this->get(route('billing.payments', $visit))->assertInertia(fn (Assert $p) => $p->where('billing.invoice.state.panel', 3000)->where('billing.invoice.state.self_pay', 0)->where('billing.invoice.state.due_now', 1000)->has('billing.payments', 0));
    }

    public function test_completed_debt_collection_from_manage_keeps_the_completed_visit_immutable(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $ca->assignRole('ca_supervisor');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $ca->id, 'capability' => 'deferment', 'limit_sen' => 4000]);
        $service = app(ResponsibilityService::class);
        $proposal = $service->propose($ca, $visit, $invoice, 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'reason' => 'Synthetic deferred settlement', 'due_date' => now()->addDay()->toDateString()]);
        $service->approve($ca, $visit, $invoice->refresh(), 'deferment', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
        app(CompleteVisitationService::class)->complete($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->lock_version]);
        $before = $visit->fresh()->attributesToArray();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        $this->get(route('billing.payments', $visit))->assertInertia(fn (Assert $p) => $p->where('billing.can.pay', true)->where('billing.can.complete', false));
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid(), 'return_to' => 'payments'])->assertRedirect(route('billing.payments', $visit));
        $this->assertSame($before, $visit->fresh()->attributesToArray());
        $this->get(route('billing.payments', $visit))->assertInertia(fn (Assert $p) => $p->where('billing.can.pay', false)->where('billing.invoice.state.deferred', 0)->where('billing.invoice.state.self_pay', 4000));
        $this->get(route('visits.invoice', $visit))->assertInertia(fn (Assert $p) => $p->where('invoice.links.payments', route('billing.payments', $visit)));
    }

    public function test_manage_reversal_retains_receipt_and_requires_independent_authority(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Cash'])->save();
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $receipt = Payment::query()->sole();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $url = route('billing.reverse', [$visit, $invoice, $receipt]);
        $payload = ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'payment_lock_version' => $receipt->lock_version, 'reason' => 'Synthetic recording error', 'return_to' => 'payments'];
        $this->post($url, $payload)->assertRedirect(route('billing.payments', $visit))->assertSessionHasErrors();
        $this->assertSame('posted', $receipt->refresh()->status);
        $this->post($url, [...$payload, 'recording_error_only' => true])->assertRedirect(route('billing.payments', $visit));
        $this->assertSame('reversed', $receipt->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_reversals', 1);
        $this->get(route('billing.payments', $visit))->assertInertia(fn (Assert $p) => $p->where('billing.payments.0.status', 'reversed')->where('billing.invoice.state.self_pay', 0));
    }
}
