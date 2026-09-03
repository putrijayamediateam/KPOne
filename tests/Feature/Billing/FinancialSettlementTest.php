<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceCorrectionService;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinancialSettlementTest extends BillingTestCase
{
    private function method(int $org): void
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $org, 'code' => 'cash', 'name' => 'Cash'])->save();
    }

    public function test_multiple_payments_and_idempotent_retry_do_not_overpay(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->method($visit->organisation_id);
        $a = ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => '2000', 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()];
        $one = app(PaymentService::class)->add($ca, $visit, $invoice, $a);
        $retry = app(PaymentService::class)->add($ca, $visit, $invoice, $a);
        $this->assertSame($one->id, $retry->id);
        $a['lock_version'] = $invoice->refresh()->lock_version;
        $a['idempotency_key'] = (string) Str::uuid();
        app(PaymentService::class)->add($ca, $visit, $invoice, $a);
        $this->assertSame(['total' => 4000, 'self_pay' => 4000, 'panel' => 0, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($invoice));
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('stock_movements', 0);
        $a['lock_version'] = $invoice->refresh()->lock_version;
        $a['idempotency_key'] = (string) Str::uuid();
        $this->expectException(ValidationException::class);
        app(PaymentService::class)->add($ca, $visit, $invoice, $a);
    }

    public function test_panel_acceptance_is_not_cash_and_missing_limit_fails_closed(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $service = app(ResponsibilityService::class);
        $proposal = $service->propose($ca, $visit, $invoice, 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'panel_id' => $panel->id, 'reason' => 'Synthetic verified responsibility']);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $visit->branch);
        $approval = ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $proposal->lock_version];
        try {
            $service->approve($supervisor, $visit, $invoice, 'panel', $proposal->public_id, $approval);
            $this->fail('Missing limit accepted.');
        } catch (ValidationException) {
            $this->assertSame('proposed', $proposal->refresh()->status);
        }
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $supervisor->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $service->approve($supervisor, $visit, $invoice, 'panel', $proposal->public_id, $approval);
        $state = app(FinancialLedger::class)->state($invoice);
        $this->assertSame(3000, $state['panel']);
        $this->assertSame(0, $state['self_pay']);
        $this->assertSame(1000, $state['due_now']);
        $this->assertDatabaseCount('payments', 0);
        $this->method($visit->organisation_id);
        $this->selectBranch($ca, $visit->branch);
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(['total' => 4000, 'self_pay' => 1000, 'panel' => 3000, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($invoice));
    }

    public function test_deferment_requires_independent_approval_and_payment_reduces_original_debt(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->method($visit->organisation_id);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $visit->branch);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $supervisor->id, 'capability' => 'deferment', 'limit_sen' => 5000]);
        $service = app(ResponsibilityService::class);
        $proposal = $service->propose($supervisor, $visit, $invoice, 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'due_date' => now()->addDay()->toDateString(), 'reason' => 'Synthetic pay later request']);
        $a = ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $proposal->lock_version];
        try {
            $service->approve($supervisor, $visit, $invoice, 'deferment', $proposal->public_id, $a);
            $this->fail('Self approval accepted.');
        } catch (ValidationException) {
            $this->assertSame('proposed', $proposal->refresh()->status);
        }
        $other = $this->actor('ca_supervisor');
        $this->selectBranch($other, $visit->branch);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $other->id, 'capability' => 'deferment', 'limit_sen' => 5000]);
        $service->approve($other, $visit, $invoice, 'deferment', $proposal->public_id, $a);
        $this->assertSame(0, app(FinancialLedger::class)->state($invoice)['due_now']);
        $this->selectBranch($ca, $visit->branch);
        app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => 1000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(3000, $proposal->refresh()->remaining_sen);
        $this->assertSame(1000, app(FinancialLedger::class)->state($invoice)['self_pay']);
    }

    public function test_panel_deactivated_after_proposal_cannot_be_accepted(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $service = app(ResponsibilityService::class);
        $proposal = $service->propose($ca, $visit, $invoice, 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'panel_id' => $panel->id, 'reason' => 'Synthetic verification']);
        $panel->forceFill(['is_active' => false])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $visit->branch);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $supervisor->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $this->expectException(ValidationException::class);
        $service->approve($supervisor, $visit, $invoice, 'panel', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
    }

    public function test_recording_error_reversal_retains_receipt_and_void_reissue_retains_number(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->method($visit->organisation_id);
        $payment = app(PaymentService::class)->add($ca, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $visit->branch);
        app(InvoiceCorrectionService::class)->reverse($finance, $visit, $invoice, $payment, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'payment_lock_version' => 1, 'recording_error_only' => true, 'reason' => 'Synthetic duplicate recording error']);
        $this->assertSame('reversed', $payment->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_reversals', 1);
        app(InvoiceCorrectionService::class)->void($finance, $visit, $invoice, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'reason' => 'Synthetic incorrect invoice']);
        $this->assertSame('KPI-00000001', $invoice->refresh()->invoice_number);
        $this->selectBranch($ca, $visit->branch);
        $replacement = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $this->assertSame($invoice->public_id, $replacement->replaces_public_id);
    }
}
