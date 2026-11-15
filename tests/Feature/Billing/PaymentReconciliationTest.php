<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\PaymentReconciliationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Clinical\ClinicalTestCase;

class PaymentReconciliationTest extends ClinicalTestCase
{
    public function test_access_is_granted_only_to_the_approved_roles_and_is_branch_scoped(): void
    {
        foreach (['director', 'finance_officer', 'ca', 'ca_supervisor'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('payment-reconciliations.index'))->assertOk()
                ->assertInertia(fn ($page) => $page->component('PaymentReconciliations/Index'));
        }

        foreach (['technical_admin', 'resident_doctor', 'panel_officer', 'marketing'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('payment-reconciliations.index'))->assertForbidden();
            $this->post(route('payment-reconciliations.store'), [])->assertForbidden();
        }
    }

    public function test_daily_close_compares_only_posted_payments_in_the_branch_local_day_and_records_immutable_revisions(): void
    {
        $actor = $this->actor('ca');
        $this->selectBranch($actor);
        $method = $this->method($actor);
        $patient = $this->patient($actor);
        $localDate = CarbonImmutable::parse('2026-10-01', $this->branch->timezone);
        $start = $localDate->startOfDay()->utc();
        $end = $localDate->addDay()->startOfDay()->utc();
        $this->recordPayment($actor, $patient->id, $method, 1000, $start->addSecond());
        $this->recordPayment($actor, $patient->id, $method, 2050, $end->subSecond());
        $this->recordPayment($actor, $patient->id, $method, 5000, $start->subSecond());
        $this->recordPayment($actor, $patient->id, $method, 7000, $end);
        $this->recordPayment($actor, $patient->id, $method, 9000, $start->addMinute(), 'reversed');
        $otherBranch = Branch::query()->where('organisation_id', $actor->organisation_id)->where('code', 'PUCHONG')->firstOrFail();
        $this->recordPayment($actor, $patient->id, $method, 11000, $start->addMinute(), 'posted', $otherBranch->id);

        $attributes = $this->closeAttributes($this->branch->id, $method->id, [
            'business_date' => '2026-10-01',
            'terminal_sales_count' => 2,
            'terminal_sales_total' => '30.50',
            'terminal_refunds_count' => 1,
            'terminal_refunds_total' => '3.00',
            'terminal_voids_count' => 1,
            'terminal_voids_total' => '5.00',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $first = app(PaymentReconciliationService::class)->reconcile($actor, $attributes);
        $retry = app(PaymentReconciliationService::class)->reconcile($actor, $attributes);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $first->kpone_payment_count);
        $this->assertSame(3050, $first->kpone_payment_total_sen);
        $this->assertSame(0, $first->payment_count_variance);
        $this->assertSame(0, $first->payment_total_variance_sen);
        $this->assertDatabaseCount('payments', 6);
        $this->assertSame(1, $first->terminal_refunds_count);
        $this->assertSame(300, $first->terminal_refunds_sen);
        $this->assertSame(1, $first->terminal_voids_count);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'billing.payment_reconciled',
            'subject_id' => $first->id,
            'actor_user_id' => $actor->id,
            'branch_id' => $this->branch->id,
        ]);

        $attributes['idempotency_key'] = (string) Str::uuid();
        $attributes['terminal_sales_count'] = 3;
        $attributes['terminal_sales_total'] = '31.00';
        $attributes['variance_reason'] = 'Synthetic later receipt was entered after the first close.';
        $second = app(PaymentReconciliationService::class)->reconcile($actor, $attributes);
        $this->assertSame(2, $second->revision);
        $this->assertSame(1, $second->payment_count_variance);
        $this->assertSame(50, $second->payment_total_variance_sen);
        $this->assertSame(1, $first->refresh()->revision);
        $this->assertDatabaseCount('payment_reconciliations', 2);
        $this->assertDatabaseCount('payments', 6);
    }

    public function test_reconciliations_are_append_only_and_same_key_cannot_change_payload(): void
    {
        $actor = $this->actor('ca');
        $this->selectBranch($actor);
        $method = $this->method($actor);
        $attributes = $this->closeAttributes($this->branch->id, $method->id, [
            'terminal_sales_count' => 1,
            'terminal_sales_total' => '1.00',
            'variance_reason' => 'Synthetic mismatch recorded.',
        ]);
        $first = app(PaymentReconciliationService::class)->reconcile($actor, $attributes);
        $changed = $attributes;
        $changed['terminal_sales_total'] = '2.00';
        try {
            app(PaymentReconciliationService::class)->reconcile($actor, $changed);
            $this->fail('Reusing an idempotency key with changed content was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reconciliation', $exception->errors());
        }
        $this->assertSame(1, $first->refresh()->revision);
        $this->assertDatabaseCount('payment_reconciliations', 1);
        $this->expectException(LogicException::class);
        $first->forceFill(['notes' => 'Evidence should not change.'])->save();
    }

    public function test_mismatch_requires_a_reason_and_http_flow_displays_the_close_history(): void
    {
        $actor = $this->actor('finance_officer');
        $this->selectBranch($actor);
        $method = $this->method($actor);
        $attributes = $this->closeAttributes($this->branch->id, $method->id, [
            'business_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
            'terminal_sales_count' => 1,
            'terminal_sales_total' => '10.00',
        ]);
        $this->from(route('payment-reconciliations.index', ['date' => $attributes['business_date']]))
            ->post(route('payment-reconciliations.store'), $attributes)
            ->assertRedirect(route('payment-reconciliations.index', ['date' => $attributes['business_date']]))
            ->assertSessionHasErrors('variance_reason');
        $this->assertDatabaseCount('payment_reconciliations', 0);

        $attributes['variance_reason'] = 'Synthetic terminal total has no matching KPOne receipt.';
        $this->post(route('payment-reconciliations.store'), $attributes)
            ->assertRedirect(route('payment-reconciliations.index', ['date' => $attributes['business_date']]));
        $this->get(route('payment-reconciliations.index', ['date' => $attributes['business_date']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PaymentReconciliations/Index')
                ->has('reconciliations', 1)
                ->where('reconciliations.0.varianceReason', $attributes['variance_reason'])
                ->where('reconciliations.0.paymentTotalVarianceSen', 1000));
    }

    public function test_foreign_payment_method_and_future_business_date_are_rejected(): void
    {
        $actor = $this->actor('ca');
        $this->selectBranch($actor);
        $attributes = $this->closeAttributes($this->branch->id, 999999);

        try {
            app(PaymentReconciliationService::class)->reconcile($actor, $attributes);
            $this->fail('A payment method from another organisation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment_method_id', $exception->errors());
        }

        $method = $this->method($actor, 'LOCAL_METHOD');
        $attributes['payment_method_id'] = $method->id;
        $attributes['business_date'] = now()->setTimezone($this->branch->timezone)->addDay()->toDateString();
        try {
            app(PaymentReconciliationService::class)->reconcile($actor, $attributes);
            $this->fail('A future business date was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('business_date', $exception->errors());
        }
        $this->assertDatabaseCount('payment_reconciliations', 0);
    }

    private function method(User $actor, string $code = 'SYNTHETIC_TERMINAL'): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill([
            'organisation_id' => $actor->organisation_id,
            'code' => $code,
            'name' => 'Synthetic terminal card',
            'is_active' => true,
        ])->save();

        return $method;
    }

    private function recordPayment(User $actor, int $patientId, PaymentMethod $method, int $amount, CarbonImmutable $receivedAt, string $status = 'posted', ?int $branchId = null): Payment
    {
        $payment = new Payment;
        $payment->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $actor->organisation_id,
            'branch_id' => $branchId ?? $this->branch->id,
            'patient_id' => $patientId,
            'payment_method_id' => $method->id,
            'receipt_number' => 'SYN-'.Str::upper(Str::random(12)),
            'amount_sen' => $amount,
            'currency' => 'MYR',
            'method_snapshot' => $method->name,
            'status' => $status,
            'reference' => null,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()),
            'received_at' => $receivedAt,
            'recorded_by_user_id' => $actor->id,
            'lock_version' => 1,
        ])->save();

        return $payment;
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function closeAttributes(int $branchId, int $methodId, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $branchId,
            'payment_method_id' => $methodId,
            'business_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
            'terminal_sales_count' => 0,
            'terminal_sales_total' => '0.00',
            'terminal_refunds_count' => 0,
            'terminal_refunds_total' => '0.00',
            'terminal_voids_count' => 0,
            'terminal_voids_total' => '0.00',
            'terminal_batch_reference' => null,
            'variance_reason' => null,
            'notes' => null,
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ];
    }
}
