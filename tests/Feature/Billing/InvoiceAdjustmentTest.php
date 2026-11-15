<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\BillingDirectoryService;
use App\Domain\Visit\Billing\Services\CompleteVisitationService;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentLimitService;
use App\Domain\Visit\Billing\Services\InvoiceAdjustmentService;
use App\Domain\Visit\Billing\Services\OutstandingBillService;
use App\Domain\Visit\Billing\Services\PanelClaimEligibility;
use App\Domain\Visit\Billing\Services\PaymentService;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class InvoiceAdjustmentTest extends BillingTestCase
{
    private function input($visit, $invoice, array $extra = []): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'threshold_version' => InvoiceAdjustmentService::threshold($visit->branch_id)['lockVersion'],
            'direction' => 'deduct', 'input_type' => 'fixed', 'input_value' => '1000', 'reason' => 'Synthetic private adjustment sentinel', 'idempotency_key' => (string) Str::uuid(), 'confirm_above_limit' => true, ...$extra];
    }

    private function threshold(?int $sen): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(InvoiceAdjustmentLimitService::class)->save($director, ['expected_branch_id' => $this->branch->id, 'lock_version' => InvoiceAdjustmentService::threshold($this->branch->id)['lockVersion'], 'limit_sen' => $sen, 'idempotency_key' => (string) Str::uuid()]);
    }

    private function payment($ca, $visit, $invoice, int $amount)
    {
        $method = PaymentMethod::query()->where('organisation_id', $visit->organisation_id)->where('code', 'cash')->first() ?? new PaymentMethod;
        $method->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $this->selectBranch($ca);

        return app(PaymentService::class)->add($ca, $visit, $invoice->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => $amount, 'method' => 'cash', 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_discount_keeps_original_invoice_immutable_and_payment_completion_use_adjusted_payable(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->threshold(1000);
        $this->selectBranch($ca);
        $a = $this->input($visit, $invoice);
        $row = app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $a);
        $retry = app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $a);
        $this->assertSame($row->id, $retry->id);
        $this->assertSame(4000, $invoice->refresh()->total_sen);
        $this->assertSame(4000, $invoice->subtotal_sen);
        $this->assertSame(['total' => 3000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 0, 'due_now' => 3000], app(FinancialLedger::class)->state($invoice));
        $data = app(BillingDirectoryService::class)->detail($ca, $visit);
        $this->assertSame(-1000, $data['invoice']['adjustment']['adjustmentSen']);
        $this->assertStringNotContainsString($a['reason'], json_encode($data));
        $this->assertStringNotContainsString($a['reason'], DB::table('audit_logs')->where('event', 'billing.adjustment_added')->value('metadata'));
        $this->payment($ca, $visit, $invoice, 3000);
        app(CompleteVisitationService::class)->complete($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->lock_version]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame('completed', $visit->refresh()->status);
        $this->assertSame(-1000, $visit->completion_evidence['adjustment']['adjustmentSen']);
        $this->actingAs($ca)->get(route('visits.invoice', $visit))->assertOk()->assertDontSee($a['reason']);
    }

    public function test_missing_zero_and_unconfigured_revisions_are_distinct_and_retry_safe(): void
    {
        $this->assertSame(['configured' => false, 'limitSen' => null, 'lockVersion' => 0], InvoiceAdjustmentService::threshold($this->branch->id));
        $this->threshold(0);
        $this->assertSame(['configured' => true, 'limitSen' => 0, 'lockVersion' => 1], InvoiceAdjustmentService::threshold($this->branch->id));
        $this->threshold(null);
        $this->assertSame(['configured' => false, 'limitSen' => null, 'lockVersion' => 2], InvoiceAdjustmentService::threshold($this->branch->id));
        $director = $this->actor('director');
        $this->selectBranch($director);
        $a = ['expected_branch_id' => $this->branch->id, 'lock_version' => 2, 'limit_sen' => 1000, 'idempotency_key' => (string) Str::uuid()];
        app(InvoiceAdjustmentLimitService::class)->save($director, $a);
        app(InvoiceAdjustmentLimitService::class)->save($director, $a);
        $this->assertDatabaseCount('invoice_adjustment_limits', 3);
        $this->expectException(ValidationException::class);
        app(InvoiceAdjustmentLimitService::class)->save($director, [...$a, 'limit_sen' => 2000]);
    }

    public function test_ca_cannot_use_missing_threshold_and_override_needs_explicit_confirmation(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->assertFalse(InvoiceAdjustmentService::controls($ca, $visit, $invoice)['add']);
        $this->actingAs($ca)->post(route('billing.adjustment', [$visit, $invoice]), $this->input($visit, $invoice))->assertForbidden();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $a = $this->input($visit, $invoice, ['confirm_above_limit' => false]);
        $this->post(route('billing.adjustment', [$visit, $invoice]), $a)->assertSessionHasErrors('confirm_above_limit');
        $row = app(InvoiceAdjustmentService::class)->add($supervisor, $visit, $invoice, [...$a, 'confirm_above_limit' => true]);
        $this->assertNull($row->threshold_sen);
        $this->assertTrue($row->limit_overridden);
        $this->assertSame(0, $row->threshold_version);
    }

    public static function excludedRoles(): array
    {
        return array_map(fn ($r) => [$r], ['resident_doctor', 'panel_officer', 'administrator', 'technical_admin', 'hr_manager', 'marketing', 'business_development']);
    }

    #[DataProvider('excludedRoles')]
    public function test_excluded_roles_have_no_actions_or_mutation_access(string $role): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $this->assertFalse($actor->can('billing.adjustments.add.branch'));
        $this->assertFalse($actor->can('billing.adjustments.override_limit.branch'));
        $this->assertFalse($actor->can('billing.adjustments.reverse.branch'));
        $this->post(route('billing.adjustment', [$visit, $invoice]), $this->input($visit, $invoice))->assertForbidden();
        $this->post(route('billing.adjustment.reverse', [$visit, $invoice, (string) Str::uuid()]), ['reason' => 'Synthetic'])->assertForbidden();
    }

    public static function calculations(): array
    {
        return [['deduct', 'percentage', 1250, 10004, 1251, 8753], ['add', 'percentage', 1250, 10004, 1251, 11255], ['deduct', 'percentage', 10000, 4000, 4000, 0], ['add', 'fixed', 4000, 4000, 4000, 8000]];
    }

    #[DataProvider('calculations')]
    public function test_exact_fixed_and_percentage_arithmetic(string $direction, string $type, int $value, int $base, int $amount, int $total): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture($base);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $row = app(InvoiceAdjustmentService::class)->add($director, $visit, $invoice, $this->input($visit, $invoice, ['direction' => $direction, 'input_type' => $type, 'input_value' => $value]));
        $this->assertSame($amount, $row->amount_sen);
        $this->assertSame($total, app(FinancialLedger::class)->state($invoice)['total']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public static function invalidCalculations(): array
    {
        return [['fixed', 0, 4000], ['fixed', 4001, 4000], ['percentage', 10001, 4000], ['percentage', 1, 1], ['fixed', 1, 0], ['fixed', 999999999999, 999999999999]];
    }

    #[DataProvider('invalidCalculations')]
    public function test_zero_over_subtotal_and_overflow_are_rejected(string $type, int $value, int $base): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture($base);
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->expectException(ValidationException::class);
        app(InvoiceAdjustmentService::class)->add($director, $visit, $invoice, $this->input($visit, $invoice, ['direction' => 'add', 'input_type' => $type, 'input_value' => $value]));
    }

    public function test_independent_reversal_restores_payable_and_keeps_history_then_new_adjustment_is_allowed(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->threshold(1000);
        $this->selectBranch($ca);
        $service = app(InvoiceAdjustmentService::class);
        $one = $service->add($ca, $visit, $invoice, $this->input($visit, $invoice));
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $a = $this->input($visit, $invoice);
        $reversal = $service->reverse($finance, $visit, $invoice, $one->public_id, $a);
        $this->assertSame($reversal->id, $service->reverse($finance, $visit, $invoice, $one->public_id, $a)->id);
        $this->assertSame(4000, app(FinancialLedger::class)->state($invoice->refresh())['total']);
        $service->add($finance, $visit, $invoice, $this->input($visit, $invoice, ['direction' => 'add', 'input_value' => 500]));
        $this->assertSame(4500, app(FinancialLedger::class)->state($invoice->refresh())['total']);
        $this->assertDatabaseCount('invoice_adjustments', 2);
        $this->assertDatabaseCount('invoice_adjustment_reversals', 1);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_same_actor_cannot_reverse_and_second_active_adjustment_is_refused(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $service = app(InvoiceAdjustmentService::class);
        $one = $service->add($finance, $visit, $invoice, $this->input($visit, $invoice));
        foreach (['reverse', 'add'] as $action) {
            try {
                $a = $this->input($visit, $invoice);
                $action === 'reverse' ? $service->reverse($finance, $visit, $invoice, $one->public_id, $a) : $service->add($finance, $visit, $invoice, $a);
                $this->fail('Invalid repeated adjustment accepted.');
            } catch (ValidationException) {
                $this->assertSame(3000, app(FinancialLedger::class)->state($invoice)['total']);
            }
        }
    }

    public function test_adjusted_payment_ceiling_and_history_prevent_further_adjustment_or_reversal(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $one = app(InvoiceAdjustmentService::class)->add($finance, $visit, $invoice, $this->input($visit, $invoice));
        try {
            $this->payment($ca, $visit, $invoice, 3001);
            $this->fail('Overpayment accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 0);
        }
        $this->payment($ca, $visit, $invoice, 1000);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->expectException(ValidationException::class);
        app(InvoiceAdjustmentService::class)->reverse($supervisor, $visit, $invoice, $one->public_id, $this->input($visit, $invoice));
    }

    public function test_stale_threshold_stale_invoice_and_changed_retry_intent_are_refused(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $a = $this->input($visit, $invoice);
        $this->threshold(1000);
        $this->selectBranch($ca);
        try {
            app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $a);
            $this->fail('Stale threshold accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoice_adjustments', 0);
        }
        try {
            app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $this->input($visit, $invoice, ['lock_version' => 999]));
            $this->fail('Stale invoice accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('invoice_adjustments', 0);
        }
        $a = $this->input($visit, $invoice);
        app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $a);
        $this->expectException(ValidationException::class);
        app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, [...$a, 'input_value' => 500]);
    }

    public function test_panel_and_pay_later_proposals_cannot_exceed_adjusted_due_and_even_pending_history_blocks_adjustments(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->threshold(1000);
        $this->selectBranch($ca);
        app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $this->input($visit, $invoice));
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $service = app(ResponsibilityService::class);
        foreach (['panel', 'deferment'] as $kind) {
            try {
                $service->propose($ca, $visit, $invoice->refresh(), $kind, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3001, 'reason' => 'Synthetic', 'panel_id' => $panel->id, 'due_date' => now()->addDay()->toDateString()]);
                $this->fail('Over-proposal accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount($kind === 'panel' ? 'coverage_allocations' : 'patient_receivables', 0);
            }
        }
        $service->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'reason' => 'Synthetic', 'panel_id' => $panel->id]);
        $this->assertTrue(InvoiceAdjustmentService::hasHistory($invoice));
        $this->assertFalse(InvoiceAdjustmentService::controls($ca, $visit, $invoice)['reverse']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_activity_and_readonly_panel_view_never_contain_private_adjustment_reason(): void
    {
        [$doctor, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->threshold(1000);
        $this->selectBranch($ca);
        $a = $this->input($visit, $invoice);
        app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $a);
        $panel = $this->actor('panel_officer');
        $this->selectBranch($panel);
        $data = app(BillingDirectoryService::class)->detail($panel, $visit);
        $this->assertSame(3000, $data['invoice']['state']['total']);
        $this->assertFalse($data['invoice']['adjustmentControls']['add']);
        $this->assertStringNotContainsString($a['reason'], json_encode($data));
        $this->getJson(route('billing.activity', [$visit, $invoice]).'?expected_branch_id='.$visit->branch_id)->assertOk()->assertJsonFragment(['message' => 'Discount added', 'amountSen' => 1000])->assertDontSee($a['reason']);
        $this->selectBranch($doctor);
        $this->getJson(route('billing.activity', [$visit, $invoice]).'?expected_branch_id='.$visit->branch_id)->assertForbidden();
    }

    public function test_full_discount_completes_without_fabricated_receipts(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $director = $this->actor('director');
        $this->selectBranch($director);
        app(InvoiceAdjustmentService::class)->add($director, $visit, $invoice, $this->input($visit, $invoice, ['input_value' => 4000]));
        $this->selectBranch($ca);
        app(CompleteVisitationService::class)->complete($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->lock_version]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame('completed', $visit->refresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('invoice_adjustments', 1);
    }

    public function test_explicit_zero_still_denies_ca_and_stale_branch_is_refused(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->threshold(0);
        $this->selectBranch($ca);
        $this->assertFalse(InvoiceAdjustmentService::controls($ca, $visit, $invoice)['add']);
        $this->post(route('billing.adjustment', [$visit, $invoice]), $this->input($visit, $invoice))->assertForbidden();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->post(route('billing.adjustment', [$visit, $invoice]), $this->input($visit, $invoice, ['expected_branch_id' => $visit->branch_id + 9999]))->assertSessionHasErrors('expected_branch_id');
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '!=', $this->branch->id)->firstOrFail();
        $outsider = $this->actor('finance_officer', $other);
        $this->selectBranch($outsider, $other);
        $this->post(route('billing.adjustment', [$visit, $invoice]), $this->input($visit, $invoice, ['expected_branch_id' => $other->id]))->assertNotFound();
        $this->assertDatabaseCount('invoice_adjustments', 0);
    }

    public function test_hold_and_stale_source_block_adjustments(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $invoice->forceFill(['correction_hold' => true])->save();
        $this->expectException(ValidationException::class);
        app(InvoiceAdjustmentService::class)->add($finance, $visit, $invoice, $this->input($visit, $invoice));
    }

    public function test_adjusted_pay_later_approval_and_outstanding_are_not_cash_and_completed_settlement_still_works(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->threshold(1000);
        $this->selectBranch($ca);
        app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $this->input($visit, $invoice));
        $debt = app(ResponsibilityService::class)->propose($ca, $visit, $invoice->refresh(), 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'reason' => 'Synthetic deferment', 'due_date' => now()->addDay()->toDateString()]);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $supervisor->id, 'capability' => 'deferment', 'limit_sen' => 3000]);
        app(ResponsibilityService::class)->approve($supervisor, $visit, $invoice->refresh(), 'deferment', $debt->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $debt->lock_version]);
        $this->assertSame(['total' => 3000, 'self_pay' => 0, 'panel' => 0, 'deferred' => 3000, 'due_now' => 0], app(FinancialLedger::class)->state($invoice));
        $this->selectBranch($ca);
        app(CompleteVisitationService::class)->complete($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->lock_version]);
        $next = Visit::factory()->create(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'patient_id' => $visit->patient_id]);
        $summary = app(OutstandingBillService::class)->summary($ca, $next);
        $this->assertSame(3000, $summary['patient']['totalSen']);
        $this->assertSame(1, $summary['patient']['count']);
        // Completion and subsequent debt settlement are distinct committed requests.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->payment($ca, $visit->refresh(), $invoice, 1000);
        $this->assertSame(2000, app(FinancialLedger::class)->state($invoice->refresh())['deferred']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_adjusted_panel_approval_eligibility_and_completion_use_payable_ceiling(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $this->threshold(1000);
        $this->selectBranch($ca);
        app(InvoiceAdjustmentService::class)->add($ca, $visit, $invoice, $this->input($visit, $invoice, ['direction' => 'add']));
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $proposal = app(ResponsibilityService::class)->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 5000, 'reason' => 'Synthetic coverage', 'panel_id' => $panel->id]);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $supervisor->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        app(ResponsibilityService::class)->approve($supervisor, $visit, $invoice->refresh(), 'panel', $proposal->public_id, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'proposal_lock_version' => $proposal->lock_version]);
        $this->assertSame(['total' => 5000, 'self_pay' => 0, 'panel' => 5000, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($invoice));
        $this->assertTrue(app(PanelClaimEligibility::class)->query($visit->organisation_id, $visit->branch_id)->where('c.id', $proposal->id)->exists());
        $this->selectBranch($ca);
        app(CompleteVisitationService::class)->complete($ca, $visit->refresh(), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->lock_version]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_permission_migration_is_additive_and_repeatable(): void
    {
        $migration = require database_path('migrations/2026_10_16_000200_grant_invoice_adjustment_permissions.php');
        $migration->up();
        $migration->up();
        foreach (['ca', 'ca_supervisor', 'finance_officer', 'director'] as $role) {
            $actor = $this->actor($role);
            $this->assertTrue($actor->can('billing.adjustments.add.branch'));
            $this->assertSame($role !== 'ca', $actor->can('billing.adjustments.override_limit.branch'));
            $this->assertSame($role !== 'ca', $actor->can('billing.adjustments.reverse.branch'));
        }
        $this->assertContains('billing.adjustments.add.branch', PermissionCatalogue::all());
    }
}
