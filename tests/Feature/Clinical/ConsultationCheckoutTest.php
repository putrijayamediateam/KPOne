<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Models\ClinicalEncounter;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\ConsultationCheckout;
use App\Domain\Clinical\Models\ServiceDelivery;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Clinical\Services\CompleteConsultationService;
use App\Domain\Clinical\Services\ReopenConsultationCheckoutService;
use App\Domain\Queue\Models\QueueEntry;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Models\Visit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ConsultationCheckoutTest extends ClinicalTestCase
{
    public function test_no_medicine_checkout_removes_queue_without_fake_plan_case_or_stock(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $checkout = app(CompleteConsultationService::class)->complete($doctor, $visit, $this->checkoutPayload($visit, $queue, $encounter));
        $this->assertSame('billing', $checkout->route);
        $this->assertSame('registered', $visit->refresh()->status);
        $this->assertSame('removed', $queue->refresh()->status);
        $this->assertSame('sent_to_billing', $queue->removal_reason);
        foreach (['treatment_plans', 'dispensary_cases', 'stock_movements'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_service_checkout_requires_explicit_performance_and_retains_exact_source(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $plan = TreatmentPlan::factory()->create(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'clinical_encounter_id' => $encounter->id, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id]);
        $catalogue = ClinicalServiceCatalogueItem::factory()->create(['organisation_id' => $visit->organisation_id]);
        $order = TreatmentPlanServiceOrder::factory()->create(['clinical_service_catalogue_item_id' => $catalogue->id, 'organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'treatment_plan_id' => $plan->id, 'quantity_ordered' => '2.000', 'recorded_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id]);
        $payload = [...$this->checkoutPayload($visit, $queue, $encounter), 'lock_version' => $plan->lock_version];
        try {
            app(CompleteConsultationService::class)->complete($doctor, $visit, $payload);
            $this->fail('Unconfirmed service must reject checkout.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('service_deliveries', $e->errors());
        }
        $this->assertDatabaseCount('consultation_checkouts', 0);
        $payload['service_deliveries'] = [['order_public_id' => $order->public_id, 'quantity_performed' => '1.500', 'disposition' => 'performed']];
        app(CompleteConsultationService::class)->complete($doctor, $visit, $payload);
        $this->assertSame('1.500', ServiceDelivery::query()->sole()->quantity_performed);
        $this->assertSame($plan->lock_version, ServiceDelivery::query()->sole()->source_plan_version);
        $this->assertSame('in_progress', $plan->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stale_encounter_and_non_doctor_cannot_checkout(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $payload = $this->checkoutPayload($visit, $queue, $encounter);
        try {
            app(CompleteConsultationService::class)->complete($doctor, $visit, [...$payload, 'encounter_lock_version' => 999]);
            $this->fail('Stale version accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('consultation_checkouts', 0);
        }
        $this->selectBranch($ca, $visit->branch);
        $this->expectException(AuthorizationException::class);
        app(CompleteConsultationService::class)->complete($ca, $visit, $payload);
    }

    public function test_exact_money_rejects_float_and_rounds_only_valid_line_arithmetic(): void
    {
        $this->assertSame(152, ExactMoney::line(1500, 101));
        $this->assertSame(500, ExactMoney::quantity('0.5'));
        $this->assertSame('0.500', ExactMoney::decimal(500));
        $this->expectException(ValidationException::class);
        ExactMoney::sen(1.5);
    }

    public function test_doctor_can_reopen_only_current_no_medicine_checkout(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $checkout = app(CompleteConsultationService::class)->complete($doctor, $visit, $this->checkoutPayload($visit, $queue, $encounter));
        app(ReopenConsultationCheckoutService::class)->reopen($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'checkout_lock_version' => $checkout->lock_version]);
        $this->assertSame('serving', $queue->refresh()->status);
        $this->assertSame('superseded', $checkout->refresh()->status);
        $this->assertNull($checkout->current_visit_guard);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->post(route('encounters.checkout', $visit), $this->checkoutPayload($visit, $queue, $encounter))->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, ConsultationCheckout::query()->where('status', 'current')->count());
        $this->assertDatabaseCount('consultation_checkouts', 2);
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(Visit $visit, QueueEntry $queue, ClinicalEncounter $encounter): array
    {
        return ['expected_branch_id' => $visit->branch_id, 'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version, 'encounter_lock_version' => $encounter->lock_version, 'lock_version' => null, 'service_deliveries' => []];
    }
}
