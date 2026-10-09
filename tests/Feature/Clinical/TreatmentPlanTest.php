<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Models\DispensaryItemException;
use App\Domain\Clinical\Dispensary\Models\DispensaryServiceLine;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\DoctorDispensaryAttentionService;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\ConsultationHold;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlan;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryAvailabilityService;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueEntryService;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\InvoiceLine;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\BillingBuilderService;
use App\Domain\Visit\Models\Visit;
use App\Domain\Visit\Services\VisitDirectoryService;
use App\Domain\Visit\Services\VisitReasonService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TreatmentPlanTest extends ClinicalTestCase
{
    public function test_legacy_handoff_http_route_requires_exact_checkout_permission(): void
    {
        [$doctor, $visit, $queue, $plan, $payload] = $this->legacyCheckoutFixture();
        $doctor->roles()->sole()->revokePermissionTo('consultations.complete.own');
        $this->assertTrue($doctor->fresh()->can('treatment_plans.send_to_dispensary.own'));
        $this->post(route('encounters.treatment-plan.send-to-dispensary', $visit), $payload)->assertForbidden();
        $this->assertDatabaseCount('consultation_checkouts', 0);
        $this->assertDatabaseCount('dispensary_handoffs', 0);
        $this->assertSame('serving', $queue->refresh()->status);
        $this->assertSame('in_progress', $plan->refresh()->status);
    }

    public function test_internal_handoff_cannot_create_checkout_after_completion_permission_loss(): void
    {
        [$doctor, $visit, , , $payload] = $this->legacyCheckoutFixture();
        $doctor->roles()->sole()->revokePermissionTo('consultations.complete.own');
        try {
            app(DispensaryHandoffService::class)->send($doctor, $visit, $payload);
            $this->fail('Checkout completion permission was bypassed.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('consultation_checkouts', 0);
            $this->assertDatabaseCount('dispensary_handoffs', 0);
        }
    }

    public function test_legacy_handoff_http_route_requires_current_clinical_versions(): void
    {
        [, $visit, $queue, $plan, $payload] = $this->legacyCheckoutFixture();
        foreach (['visit_lock_version', 'queue_lock_version', 'encounter_lock_version'] as $field) {
            $this->post(route('encounters.treatment-plan.send-to-dispensary', $visit), [...$payload, $field => 999])
                ->assertSessionHasErrors('checkout');
            $this->assertDatabaseCount('consultation_checkouts', 0);
            $this->assertDatabaseCount('dispensary_handoffs', 0);
        }
        $this->post(route('encounters.treatment-plan.send-to-dispensary', $visit), [
            'expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version,
        ])->assertSessionHasErrors(['visit_lock_version', 'queue_lock_version', 'encounter_lock_version', 'service_deliveries']);
        $this->assertSame('serving', $queue->refresh()->status);
        $this->assertSame('in_progress', $plan->refresh()->status);
    }

    public function test_legacy_handoff_http_route_preserves_explicit_service_confirmation_and_current_checkout(): void
    {
        [, $visit, $queue, $plan, $payload] = $this->legacyCheckoutFixture(withService: true);
        $this->post(route('encounters.treatment-plan.send-to-dispensary', $visit), $payload)->assertSessionHasErrors('service_deliveries');
        $this->assertDatabaseCount('consultation_checkouts', 0);
        $order = $plan->serviceOrders()->sole();
        $payload['service_deliveries'] = [['order_public_id' => $order->public_id, 'quantity_performed' => '1.000', 'disposition' => 'performed']];
        $this->post(route('encounters.treatment-plan.send-to-dispensary', $visit), $payload)
            ->assertRedirect(route('queue.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('consultation_checkouts', 1);
        $this->assertDatabaseCount('service_deliveries', 1);
        $this->assertDatabaseCount('dispensary_handoffs', 1);
        $this->assertSame('removed', $queue->refresh()->status);
        $this->assertSame('ready_for_dispensing', $plan->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function legacyCheckoutFixture(bool $withService = false): array
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null,
            [$this->medicinePayload($this->medicineCatalogue($doctor))],
            $withService ? [$this->servicePayload($this->serviceCatalogue($doctor))] : []));

        return [$doctor, $visit, $queue, $plan, [
            'expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version,
            'visit_lock_version' => $visit->lock_version, 'queue_lock_version' => $queue->lock_version,
            'encounter_lock_version' => $encounter->lock_version, 'service_deliveries' => [],
        ]];
    }

    public function test_composed_and_custom_medicine_text_survives_reload_without_duplicate_orders(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $row = [...$this->medicinePayload($medicine), 'dosage' => '1 tablet', 'frequency' => 'Once daily', 'duration' => '5 days'];
        $url = route('encounters.treatment-plan.save', $visit);
        $this->put($url, $this->payload(null, [$row]))->assertSessionHasNoErrors()->assertRedirect();
        $plan = TreatmentPlan::query()->sole();
        $order = $plan->medicineOrders()->sole();
        $this->assertSame(1, $plan->lock_version);
        $this->get(route('encounters.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('clinical.treatmentPlan.lockVersion', 1)
            ->has('clinical.treatmentPlan.medicines', 1)
            ->where('clinical.treatmentPlan.medicines.0.dosage', '1 tablet')
            ->where('clinical.treatmentPlan.medicines.0.duration', '5 days'));

        $row = [...$row, 'public_id' => $order->public_id, 'catalogue_public_id' => null, 'dosage' => 'as directed', 'duration' => 'custom duration'];
        $this->put($url, $this->payload(1, [$row]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, $plan->refresh()->lock_version);
        $this->assertDatabaseCount('treatment_plan_medicine_orders', 1);
        $this->assertSame($order->id, $plan->medicineOrders()->sole()->id);
        $this->assertSame('as directed', $order->refresh()->dosage);
        $this->assertSame('custom duration', $order->duration);
        $this->putJson($url, $this->payload(2, [[...$row, 'dosage' => '']]))->assertUnprocessable()->assertJsonValidationErrors('medicines.0.dosage');
        $this->putJson($url, $this->payload(2, [[...$row, 'quantity_ordered' => '-1']]))->assertUnprocessable();
        $this->assertSame(2, $plan->refresh()->lock_version);
        $this->assertDatabaseCount('treatment_plan_medicine_orders', 1);
        $this->put($url, $this->payload(2, [[...$row, 'duration' => null]]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(3, $plan->refresh()->lock_version);
        $this->assertNull($order->refresh()->duration);
    }

    public function test_removed_queue_advertises_existing_clinical_entry_only_for_pending_attending_doctor_attention(): void
    {
        [$doctor, $ca, $visit, , $case, $item, $exception] = $this->patientDeclinedFixture();
        $snapshot = $this->postJson(route('queue.search'), ['status' => 'removed'])->assertOk();
        $snapshot->assertJsonPath('removed.0.canOpenEncounter', true);
        $row = $snapshot->json('removed.0');
        foreach (['medicineName', 'quantityOrdered', 'proposedQuantity', 'exceptionPublicId', 'clinicalNote', 'dispensaryAttention'] as $key) {
            $this->assertArrayNotHasKey($key, $row);
        }
        $this->assertStringNotContainsString('Synthetic medicine', $snapshot->getContent());
        $this->get(route('encounters.show', $row['visitNumber']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('clinical.dispensaryAttention.0.exceptionPublicId', $exception->public_id));

        foreach ([$ca, $this->actor('director')] as $other) {
            $this->selectBranch($other, $visit->branch);
            $this->postJson(route('queue.search'), ['status' => 'removed'])->assertOk()->assertJsonPath('removed.0.canOpenEncounter', false);
        }
        $unrelated = $this->doctor();
        $this->selectBranch($unrelated, $visit->branch);
        $this->postJson(route('queue.search'), ['status' => 'removed', 'scope' => 'branch'])->assertOk()->assertJsonCount(0, 'removed');
        $this->get(route('encounters.show', $visit))->assertNotFound();
        $this->selectBranch($this->actor('technical_admin'), $visit->branch);
        $this->postJson(route('queue.search'), ['status' => 'removed'])->assertForbidden();
        $this->get(route('encounters.show', $visit))->assertForbidden();

        $this->selectBranch($doctor, $visit->branch);
        app(DispensaryService::class)->acknowledge($doctor, $exception, [
            'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version,
        ]);
        $this->postJson(route('queue.search'), ['status' => 'removed'])->assertOk()->assertJsonPath('removed.0.canOpenEncounter', false);
    }

    public function test_pending_attention_navigation_requires_exact_permission_and_effective_branch(): void
    {
        [$doctor, , $visit] = $this->patientDeclinedFixture();
        $attention = app(DoctorDispensaryAttentionService::class);
        $this->assertTrue($attention->hasPendingForVisit($doctor, $visit));
        $role = $doctor->roles()->where('name', 'resident_doctor')->firstOrFail();
        $role->revokePermissionTo('dispensary.acknowledge_partial.own');
        $this->assertFalse($attention->hasPendingForVisit($doctor->fresh(), $visit));
        $role->givePermissionTo('dispensary.acknowledge_partial.own');
        $this->assertTrue($attention->hasPendingForVisit($doctor->fresh(), $visit));
        $doctor->forceFill(['is_active' => false])->save();
        $this->assertFalse($attention->hasPendingForVisit($doctor->fresh(), $visit));
        $doctor->forceFill(['is_active' => true])->save();
        $doctor->staffProfile->branchAssignments()->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->assertFalse($attention->hasPendingForVisit($doctor->fresh(), $visit));
    }

    public function test_treatment_plan_badge_uses_authoritative_lifecycle_and_removed_entry_uses_get(): void
    {
        $panel = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/Clinical/Partials/TreatmentPlanPanel.vue')));
        $this->assertStringContainsString("plan.status === 'ready_for_dispensing' ? 'Sent to Dispensary' : 'In progress'", $panel);
        $queue = file_get_contents(resource_path('js/pages/Queue/Index.vue'));
        $this->assertStringContainsString('openConsultation: row.canOpenEncounter,', $queue);
        $this->assertStringContainsString("if (entry.status === 'removed')", $queue);
        $this->assertStringContainsString('router.get(`/visits/${encodeURIComponent(row.visitNumber)}/encounter`)', $queue);
    }

    public function test_single_medicine_label_uses_saved_actual_snapshot_and_is_non_mutating(): void
    {
        $fixture = $this->stockedPartialFixture(true);
        $before = $this->fulfilmentSnapshot();
        $url = route('dispensary.items.label', [$fixture['case'], $fixture['item']->public_id]);
        $response = $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/Labels')
            ->where('labels.patient.name', $fixture['visit']->patient->full_name)
            ->where('labels.branch', $fixture['visit']->branch->name)
            ->has('labels.items', 1)
            ->where('labels.items.0.quantity', '0.500')
            ->where('labels.items.0.dosage', 'Synthetic dosage')
            ->missing('labels.items.0.publicId')
            ->missing('labels.items.0.lockVersion')
            ->missing('labels.items.0.allocations'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get($url)->assertOk();
        $this->assertSame($before, $this->fulfilmentSnapshot());
    }

    public function test_all_medicine_labels_exclude_services_and_do_not_fabricate_pending_actual_quantity(): void
    {
        $fixture = $this->stockedPartialFixture(true);
        $before = $this->fulfilmentSnapshot();
        $this->get(route('dispensary.labels', $fixture['case']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/Labels')
            ->has('labels.items', 2)
            ->where('labels.items.0.quantity', '0.500')
            ->where('labels.items.1.name', 'Synthetic second medicine')
            ->where('labels.items.1.quantity', null));
        $this->assertDatabaseCount('treatment_plan_service_orders', 1);
        $this->assertSame($before, $this->fulfilmentSnapshot());
        $labels = app(DispensaryDirectoryService::class)->labels($fixture['ca'], $fixture['case']->refresh());
        $this->assertSame(['clinic', 'branch', 'patient', 'date', 'items'], array_keys($labels));
        $this->assertSame(['name', 'strength', 'quantity', 'unit', 'dosage', 'frequency', 'duration', 'instruction'], array_keys($labels['items'][0]));
    }

    public function test_labels_exclude_non_dispensed_items_and_scope_item_identity_to_current_handoff(): void
    {
        [, $ca, $visit, , $case, $item] = $this->patientDeclinedFixture(true);
        $this->selectBranch($ca, $visit->branch);
        $this->get(route('dispensary.labels', $case))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('labels.items', 1)->where('labels.items.0.name', 'Synthetic second medicine'));
        $this->get(route('dispensary.items.label', [$case, $item->public_id]))->assertNotFound();
        $this->get(route('dispensary.items.label', [$case, (string) Str::uuid()]))->assertNotFound();
        $other = $this->stockedPartialFixture();
        $this->get(route('dispensary.items.label', [$other['case'], $item->public_id]))->assertNotFound();
        app(DispensaryService::class)->returnToDoctor($other['ca'], $other['case']->refresh(), [
            'expected_branch_id' => $other['visit']->branch_id,
            'case_lock_version' => $other['case']->refresh()->lock_version,
        ]);
        $this->get(route('dispensary.labels', $other['case']))->assertNotFound();
        $this->get(route('dispensary.show', $other['case']))->assertNotFound();
    }

    public function test_labels_preserve_role_branch_and_organisation_privacy(): void
    {
        $fixture = $this->stockedPartialFixture();
        $urls = [route('dispensary.labels', $fixture['case']), route('dispensary.items.label', [$fixture['case'], $fixture['item']->public_id])];
        foreach (['resident_doctor', 'director', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            foreach ($urls as $url) {
                $this->get($url)->assertForbidden();
            }
        }
        $this->assertPrivateCaseUrls($urls);
    }

    public function test_completed_dispensary_revisit_is_minimal_read_only_and_private(): void
    {
        $fixture = $this->stockedPartialFixture();
        app(DispensaryService::class)->complete($fixture['ca'], $fixture['case']->refresh(), [
            'expected_branch_id' => $fixture['visit']->branch_id,
            'case_lock_version' => $fixture['case']->refresh()->lock_version,
        ]);
        $this->assertSame('9.500', $fixture['balance']->refresh()->quantity);
        $before = $this->fulfilmentSnapshot();
        $url = route('dispensary.show', $fixture['case']);
        $response = $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dispensary/Completed')
            ->where('dispensary.status', 'completed')
            ->where('dispensary.visit.visitNumber', $fixture['visit']->visit_number)
            ->missing('dispensary.items')->missing('dispensary.can')->missing('dispensary.lockVersion'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(['status', 'patient', 'visit', 'completedAt'], array_keys(app(DispensaryDirectoryService::class)->detail($fixture['ca'], $fixture['case']->refresh())));
        $this->get(route('dispensary.labels', $fixture['case']))->assertNotFound();
        $this->assertSame($before, $this->fulfilmentSnapshot());
        $this->assertPrivateCaseUrls([$url]);
        foreach (['resident_doctor', 'director', 'technical_admin'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get($url)->assertForbidden();
        }
    }

    public function test_only_eligible_current_branch_dispensary_stock_is_selectable_and_store_information_grants_no_authority(): void
    {
        $fixture = $this->stockedPartialFixture();
        $locations = [];
        foreach ([InventoryLocation::TYPE_BRANCH_STORE, InventoryLocation::TYPE_MEDICAL_STOCK] as $type) {
            $location = $fixture['location']->replicate();
            $location->forceFill(['public_id' => (string) Str::uuid(), 'code' => 'SYN-'.Str::random(8), 'type' => $type, 'branch_id' => $type === InventoryLocation::TYPE_MEDICAL_STOCK ? null : $fixture['visit']->branch_id])->save();
            // Synthetic initial inventory state only, not a runtime balance-edit path.
            DB::table('inventory_stock_balances')->insert(collect($fixture['balance']->getAttributes())->except('id')->merge(['inventory_location_id' => $location->id])->all());
            $locations[] = $location;
        }
        foreach (['expired', InventoryBatch::STATUS_QUARANTINED, InventoryBatch::STATUS_DAMAGED, 'empty'] as $exclusion) {
            $batch = $fixture['batch']->replicate();
            $batch->forceFill([
                'public_id' => (string) Str::uuid(), 'batch_number' => 'SYN-'.Str::random(8),
                'status' => in_array($exclusion, ['expired', 'empty'], true) ? InventoryBatch::STATUS_AVAILABLE : $exclusion,
                'expiry_date' => $exclusion === 'expired' ? now()->subDay()->toDateString() : $fixture['batch']->expiry_date,
            ])->save();
            DB::table('inventory_stock_balances')->insert(collect($fixture['balance']->getAttributes())->except('id')->merge(['inventory_batch_id' => $batch->id, 'quantity' => $exclusion === 'empty' ? '0.000' : '10.000'])->all());
        }
        $detail = app(DispensaryDirectoryService::class)->detail($fixture['ca'], $fixture['case']->refresh());
        $this->assertCount(1, $detail['items'][0]['availability']);
        $this->assertSame($fixture['location']->public_id, $detail['items'][0]['availability'][0]['locationPublicId']);
        $this->assertSame($fixture['batch']->public_id, $detail['items'][0]['availability'][0]['batchPublicId']);
        $informational = app(InventoryAvailabilityService::class)->forSku($fixture['ca'], $fixture['sku']->id, $fixture['visit']->branch_id);
        $this->assertCount(3, $informational);
        $before = $this->fulfilmentSnapshot();
        foreach ($locations as $location) {
            $this->patchJson(route('dispensary.items.update', [$fixture['case'], $fixture['item']]), [
                'expected_branch_id' => $fixture['visit']->branch_id,
                'case_lock_version' => $fixture['case']->refresh()->lock_version,
                'item_lock_version' => $fixture['item']->refresh()->lock_version,
                'status' => 'partial', 'quantity_dispensed' => '0.500', 'reason' => 'patient_declined',
                'allocations' => [['location_public_id' => $location->public_id, 'sku_public_id' => $fixture['sku']->public_id, 'batch_public_id' => $fixture['batch']->public_id, 'quantity' => '0.500']],
            ])->assertNotFound();
        }
        $this->assertSame($before, $this->fulfilmentSnapshot());
    }

    public function test_a_refused_dispensary_save_returns_to_the_case_not_the_last_full_page(): void
    {
        $fixture = $this->stockedPartialFixture();
        $url = route('dispensary.items.update', [$fixture['case'], $fixture['item']]);
        $payload = [
            'expected_branch_id' => $fixture['visit']->branch_id,
            'case_lock_version' => $fixture['case']->refresh()->lock_version,
            'item_lock_version' => $fixture['item']->refresh()->lock_version,
        ];

        // NAV-02: the session still points at the patient's QR status page, as it does when the
        // same browser also hosted the patient flow. A failed request-level check (no status) and a
        // failed domain rule (dispensed without an actual quantity) must both stay on the Dispensary case.
        foreach ([[], ['status' => 'dispensed', 'allocations' => []]] as $extra) {
            $this->withSession(['_previous' => ['url' => 'http://localhost/check-in/status']])
                ->patch($url, $payload + $extra)
                ->assertRedirect(route('dispensary.show', $fixture['case']))
                ->assertSessionHasErrors();
        }
    }

    public function test_dispensary_print_and_consultation_copy_have_explicit_non_mutating_ui_contracts(): void
    {
        $panel = preg_replace('/\s+/', ' ', file_get_contents(resource_path('js/pages/Clinical/Partials/TreatmentPlanPanel.vue')));
        $this->assertStringContainsString('Complete Consultation', $panel);
        $this->assertStringContainsString('Complete consultation?', $panel);
        $this->assertStringContainsString('This will send the current Treatment Plan to Dispensary', $panel);
        $this->assertStringNotContainsString('Send to Dispensary', $panel);
        $this->assertStringContainsString('Dispensary fulfilment follows Complete Consultation.', $panel);
        $this->assertStringNotContainsString('dispensing, stock and billing are handled later', $panel);
        $labels = file_get_contents(resource_path('js/pages/Dispensary/Labels.vue'));
        $this->assertStringContainsString('const printLabels = () => window.print();', $labels);
        $this->assertStringContainsString('@media print', $labels);
        $this->assertStringContainsString('Not yet recorded', $labels);
        foreach (['router.', 'fetch(', 'localStorage', 'sessionStorage', 'useForm'] as $mutation) {
            $this->assertStringNotContainsString($mutation, $labels);
        }
        $workspace = file_get_contents(resource_path('js/pages/Dispensary/Show.vue'));
        $this->assertStringContainsString('Print all labels', $workspace);
        $this->assertStringContainsString('Print Label', $workspace);
        $board = file_get_contents(resource_path('js/components/patient-board/PatientBoard.vue'));
        $this->assertStringContainsString('returnedFromDispensary', $board);
        $this->assertStringContainsString('Returned', $board);
    }

    /** @param list<string> $urls */
    private function assertPrivateCaseUrls(array $urls): void
    {
        $otherOrganisation = Organisation::query()->create(['code' => 'SYN-OTHER-'.Str::random(6), 'name' => 'Synthetic other organisation', 'is_active' => true]);
        foreach ([$this->organisation->id, $otherOrganisation->id] as $organisationId) {
            $branch = new Branch;
            $branch->forceFill(['organisation_id' => $organisationId, 'code' => 'SYN-'.Str::random(6), 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
            $this->selectBranch($this->actor('ca', $branch), $branch);
            foreach ($urls as $url) {
                $this->get($url)->assertNotFound();
            }
        }
    }

    private function fulfilmentSnapshot(): string
    {
        $snapshot = [];
        foreach (['treatment_plans', 'treatment_plan_medicine_orders', 'treatment_plan_service_orders', 'dispensary_cases', 'dispensary_handoffs', 'dispensary_items', 'dispensary_item_exceptions', 'dispensary_item_batch_allocations', 'inventory_stock_balances', 'stock_movements', 'audit_logs', 'patient_allergy_profiles', 'clinical_encounter_allergy_reviews', 'queue_entries'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return json_encode($snapshot, JSON_THROW_ON_ERROR);
    }

    public function test_attending_doctor_sees_only_own_pending_patient_declined_acknowledgement(): void
    {
        [$doctor, $ca, $visit, , $case, $item, $exception] = $this->patientDeclinedFixture();

        $attention = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['dispensaryAttention'];
        $this->assertCount(1, $attention);
        $this->assertSame($exception->public_id, $attention[0]['exceptionPublicId']);
        $this->assertSame('awaiting_acknowledgement', $attention[0]['status']);
        $this->assertSame($item->quantity_ordered, $attention[0]['quantityOrdered']);
        $this->assertArrayNotHasKey('allocations', $attention[0]);

        foreach ([$this->doctor(), $ca, $this->actor('director'), $this->actor('technical_admin')] as $other) {
            $this->selectBranch($other, $visit->branch);
            $this->assertSame([], app(DoctorDispensaryAttentionService::class)->forEncounter($other, $visit->clinicalEncounter));
        }

        $this->actingAs($ca)->post(route('dispensary.exceptions.acknowledge', $exception), [
            'case_lock_version' => $case->lock_version,
            'item_lock_version' => $item->lock_version,
        ])->assertForbidden();
    }

    public function test_acknowledgement_requires_exact_current_proposal_and_changes_no_clinical_or_stock_state(): void
    {
        [$doctor, , $visit, $plan, $case, $item, $exception] = $this->patientDeclinedFixture();
        $profile = PatientAllergyProfile::query()->where('patient_id', $visit->patient_id)->sole();
        $versions = [$plan->lock_version, $profile->lock_version, $item->allergy_profile_version_validated];

        $this->selectBranch($doctor, $visit->branch);
        $this->actingAs($doctor)->post(route('dispensary.exceptions.acknowledge', $exception), [
            'case_lock_version' => $case->lock_version + 1,
            'item_lock_version' => $item->lock_version,
        ])->assertSessionHasErrors('exception');
        $this->assertSame(DispensaryItemException::STATUS_AWAITING, $exception->refresh()->status);

        $this->actingAs($doctor)->post(route('dispensary.exceptions.acknowledge', $exception), [
            'case_lock_version' => $case->lock_version,
            'item_lock_version' => $item->lock_version,
        ])->assertRedirect();

        $this->assertSame(DispensaryItemException::STATUS_ACKNOWLEDGED, $exception->refresh()->status);
        $this->assertSame($doctor->id, $exception->acknowledged_by_user_id);
        $this->assertSame($versions, [$plan->refresh()->lock_version, $profile->refresh()->lock_version, $item->refresh()->allergy_profile_version_validated]);
        $this->assertDatabaseCount('stock_movements', 0);
        $audit = AuditLog::query()->where('event', 'dispensary.partial_acknowledged')->sole();
        $this->assertStringNotContainsString('Synthetic medicine', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('quantity', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_changed_proposal_supersedes_acknowledgement_and_requires_doctor_review_again(): void
    {
        [$doctor, $ca, $visit, , $case, $item, $exception] = $this->patientDeclinedFixture();
        app(DispensaryService::class)->acknowledge($doctor, $exception, [
            'case_lock_version' => $case->lock_version,
            'item_lock_version' => $item->lock_version,
        ]);

        $this->selectBranch($ca, $visit->branch);
        $updated = app(DispensaryService::class)->updateItem($ca, $case->refresh(), $item->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->refresh()->lock_version,
            'item_lock_version' => $item->refresh()->lock_version,
            'status' => 'partial',
            'quantity_dispensed' => '0.500',
            'reason' => 'patient_declined',
            'allocations' => [],
        ]);

        $this->assertSame(DispensaryItemException::STATUS_SUPERSEDED, $exception->refresh()->status);
        $current = $updated->exceptions->where('status', DispensaryItemException::STATUS_AWAITING)->last();
        $this->assertNotNull($current);
        $attention = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['dispensaryAttention'];
        $this->assertTrue($attention[0]['reviewAgain']);
        $this->assertSame('0.500', $attention[0]['proposedQuantity']);
        $this->assertSame(DispensaryItemException::STATUS_AWAITING, $attention[0]['status']);
    }

    public function test_acknowledged_patient_declined_non_fulfilment_allows_atomic_completion(): void
    {
        [$doctor, $ca, $visit, , $case, $item, $exception] = $this->patientDeclinedFixture();
        app(DispensaryService::class)->acknowledge($doctor, $exception, [
            'case_lock_version' => $case->lock_version,
            'item_lock_version' => $item->lock_version,
        ]);
        $this->selectBranch($ca, $visit->branch);
        $completed = app(DispensaryService::class)->complete($ca, $case->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->refresh()->lock_version,
        ]);

        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $completed->status);
        $this->assertSame(DispensaryHandoff::STATUS_COMPLETED, $completed->handoffs()->sole()->status);
        $this->assertSame(TreatmentPlan::STATUS_READY_FOR_DISPENSING, $completed->treatmentPlan->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_dispensary_detail_mutation_hints_require_current_case_handler(): void
    {
        [, $owner, $visit, , $case] = $this->patientDeclinedFixture();
        $otherCa = $this->actor('ca');
        $this->selectBranch($otherCa, $visit->branch);

        $otherView = app(DispensaryDirectoryService::class)->detail($otherCa, $case->refresh());
        $this->assertFalse($otherView['can']['update']);
        $this->assertFalse($otherView['can']['complete']);

        $this->selectBranch($owner, $visit->branch);
        $ownerView = app(DispensaryDirectoryService::class)->detail($owner, $case->refresh());
        $this->assertTrue($ownerView['can']['update']);
        $this->assertTrue($ownerView['can']['complete']);
    }

    public function test_complete_revalidates_current_location_sku_and_mapping_before_stock_deduction(): void
    {
        foreach (['location', 'sku', 'mapping'] as $reference) {
            $fixture = $this->stockedPartialFixture();
            $fixture[$reference]->forceFill(['is_active' => false])->save();

            try {
                app(DispensaryService::class)->complete($fixture['ca'], $fixture['case']->refresh(), [
                    'expected_branch_id' => $fixture['visit']->branch_id,
                    'case_lock_version' => $fixture['case']->refresh()->lock_version,
                ]);
                $this->fail('Completion accepted an inactive '.$reference.'.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('allocations', $exception->errors());
            }

            $this->assertSame(DispensaryCase::STATUS_DISPENSING, $fixture['case']->refresh()->status);
            $this->assertSame('10.000', (string) $fixture['balance']->refresh()->quantity);
            $this->assertDatabaseMissing('stock_movements', [
                'organisation_id' => $fixture['ca']->organisation_id,
                'movement_type' => 'dispense',
            ]);
        }
    }

    public function test_allocation_location_branch_is_enforced_by_the_database(): void
    {
        $fixture = $this->stockedPartialFixture();
        $otherBranch = new Branch;
        $otherBranch->forceFill(['organisation_id' => $fixture['ca']->organisation_id, 'code' => 'SYN-OTHER-'.Str::upper(Str::random(4)), 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $otherLocation = new InventoryLocation;
        $otherLocation->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $fixture['ca']->organisation_id, 'branch_id' => $otherBranch->id, 'code' => 'SYN-OTHER-DISP-'.Str::upper(Str::random(4)), 'name' => 'Synthetic Other Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();
        $allocation = DB::table('dispensary_item_batch_allocations')->where('dispensary_item_id', $fixture['item']->id)->sole();

        try {
            DB::transaction(fn () => DB::table('dispensary_item_batch_allocations')->where('id', $allocation->id)->update(['inventory_location_id' => $otherLocation->id]));
            $this->fail('The database accepted a cross-branch allocation location.');
        } catch (QueryException) {
        }
        $this->assertSame($fixture['location']->id, (int) DB::table('dispensary_item_batch_allocations')->where('id', $allocation->id)->value('inventory_location_id'));
    }

    public function test_complete_uses_branch_local_date_for_final_expiry_validation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 16:30:00', 'UTC'));
        try {
            $fixture = $this->stockedPartialFixture();
            $fixture['batch']->forceFill([
                'expiry_date' => now()->setTimezone($fixture['visit']->branch->timezone)->toDateString(),
            ])->save();

            try {
                app(DispensaryService::class)->complete($fixture['ca'], $fixture['case']->refresh(), [
                    'expected_branch_id' => $fixture['visit']->branch_id,
                    'case_lock_version' => $fixture['case']->refresh()->lock_version,
                ]);
                $this->fail('Completion accepted a batch expiring on the branch-local current date.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('allocations', $exception->errors());
            }

            $this->assertSame('10.000', (string) $fixture['balance']->refresh()->quantity);
            $this->assertDatabaseMissing('stock_movements', [
                'organisation_id' => $fixture['ca']->organisation_id,
                'movement_type' => 'dispense',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_send_to_dispensary_records_the_post_transition_plan_version_and_no_stock_movement(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [$this->medicinePayload($medicine)]));

        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $plan->lock_version,
        ]);

        $plan->refresh();
        $handoff = $case->handoffs()->sole();
        $this->assertSame(TreatmentPlan::STATUS_READY_FOR_DISPENSING, $plan->status);
        $this->assertSame(2, $plan->lock_version);
        $this->assertSame($plan->lock_version, $handoff->treatment_plan_lock_version_received);
        $this->assertSame(DispensaryCase::STATUS_PENDING, $case->status);
        $this->assertSame(DispensaryHandoff::STATUS_OPEN, $handoff->status);
        $this->assertSame(DispensaryItem::STATUS_PENDING, $handoff->items()->sole()->status);
        $this->assertSame('removed', $queue->refresh()->status);
        $this->assertSame('sent_to_dispensary', $queue->removal_reason);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'treatment_plan.sent_to_dispensary']);
    }

    public function test_send_requires_active_medicine_and_current_allergy_safety(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = $this->serviceCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, services: [$this->servicePayload($service)]));

        try {
            app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version]);
            $this->fail('A service-only Treatment Plan was sent to Dispensary.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }

        $this->assertDatabaseCount('dispensary_cases', 0);
        $this->assertSame(TreatmentPlan::STATUS_IN_PROGRESS, $plan->refresh()->status);
    }

    public function test_ready_plan_rejects_ordinary_treatment_plan_save(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [$this->medicinePayload($medicine)]));
        app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version]);

        $this->expectException(AuthorizationException::class);
        app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($plan->refresh()->lock_version, [$this->medicinePayload($medicine, $plan->medicineOrders()->sole()->public_id)]));
    }

    public function test_return_to_doctor_is_dedicated_stock_free_and_resend_creates_a_new_attempt(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $reason = app(VisitReasonService::class)->create($ca, 'Sakit tekak');
        $visit->forceFill(['visit_reason' => 'Sakit tekak'])->save();
        app(VisitReasonService::class)->replace($visit, collect([$reason]));
        $this->assertSame('Sakit tekak', app(VisitDirectoryService::class)->search($ca, [])['data'][0]['visitReasonExcerpt']);
        $this->assertSame('Sakit tekak', app(QueueDirectoryService::class)->snapshot($doctor)['serving'][0]['visitReasonExcerpt']);
        $this->assertFalse(app(QueueDirectoryService::class)->snapshot($doctor)['serving'][0]['returnedFromDispensary']);
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [$this->medicinePayload($medicine)]));
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version]);

        $this->assertFalse(app(QueueDirectoryService::class)->snapshot($doctor, ['status' => 'removed'])['removed'][0]['canOpenEncounter']);
        $this->assertSame('ready_for_dispensing', app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['treatmentPlan']['status']);
        $this->selectBranch($ca, $visit->branch);
        $case = app(DispensaryService::class)->start($ca, $case, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);
        $this->assertSame('Sakit tekak', app(DispensaryDirectoryService::class)->board($ca, [])['data'][0]['visitReasonExcerpt']);
        $case = app(DispensaryService::class)->returnToDoctor($ca, $case, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);

        $this->assertSame(DispensaryCase::STATUS_RETURNED, $case->status);
        $this->assertSame(DispensaryHandoff::STATUS_RETURNED, $case->handoffs()->first()->status);
        $this->assertSame('serving', $queue->refresh()->status);
        $this->assertNotNull($queue->returned_from_dispensary_at);
        $this->assertSame('Sakit tekak', $visit->refresh()->visit_reason);
        $this->assertSame('Sakit tekak', app(QueueDirectoryService::class)->snapshot($ca)['serving'][0]['visitReasonExcerpt']);
        $this->assertTrue(app(QueueDirectoryService::class)->snapshot($ca)['serving'][0]['returnedFromDispensary']);
        $this->assertSame(TreatmentPlan::STATUS_IN_PROGRESS, $plan->refresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);

        $this->selectBranch($doctor, $visit->branch);
        $this->assertSame('in_progress', app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['treatmentPlan']['status']);
        $resent = app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version]);
        $this->assertSame('ready_for_dispensing', app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['treatmentPlan']['status']);
        $this->assertSame([1, 2], $resent->handoffs()->orderBy('attempt_number')->pluck('attempt_number')->all());
        $this->assertSame(DispensaryHandoff::STATUS_RETURNED, $resent->handoffs()->orderBy('attempt_number')->first()->status);
        $this->assertSame(DispensaryHandoff::STATUS_OPEN, $resent->handoffs()->get()->last()->status);
        $this->assertFalse(app(QueueDirectoryService::class)->snapshot($doctor, ['status' => 'removed'])['removed'][0]['returnedFromDispensary']);
        $this->selectBranch($ca, $visit->branch);
        $this->assertSame('Sakit tekak', app(DispensaryDirectoryService::class)->board($ca, [])['data'][0]['visitReasonExcerpt']);
        $this->assertDatabaseCount('consultation_holds', 0);
    }

    public function test_held_consultation_cannot_save_plan_or_send_to_dispensary(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [$this->medicinePayload($medicine)]));
        $this->holdEncounter($doctor, $visit, $queue, $encounter);

        $this->assertFalse(app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit)['treatmentPlan']['canSendToDispensary']);
        foreach ([
            fn () => app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($plan->refresh()->lock_version, [$this->medicinePayload($medicine, $plan->medicineOrders()->sole()->public_id)])),
            fn () => app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A held consultation unexpectedly changed clinical state.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('encounter', $exception->errors());
            }
        }

        $this->assertDatabaseCount('dispensary_handoffs', 0);
        $this->assertSame('serving', $queue->refresh()->status);
    }

    public function test_dispensary_return_places_patient_on_hold_while_doctor_serves_another(): void
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [$this->medicinePayload($medicine)]));
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version]);

        $nextVisit = $this->consultationVisit($ca, $doctor);
        $nextQueue = $this->send($ca, $nextVisit);
        $this->selectBranch($doctor, $visit->branch);
        $nextQueue = app(QueueEntryService::class)->call($doctor, $nextVisit, [
            'expected_branch_id' => $nextVisit->branch_id,
            'visit_lock_version' => $nextVisit->lock_version,
            'queue_lock_version' => $nextQueue->lock_version,
        ]);

        $this->selectBranch($ca, $visit->branch);
        $case = app(DispensaryService::class)->start($ca, $case, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);
        app(DispensaryService::class)->returnToDoctor($ca, $case, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);

        $this->assertSame('serving', $queue->refresh()->status);
        $this->assertSame(1, ConsultationHold::query()->whereNull('resumed_at')->count());
        $this->assertTrue($queue->visit->clinicalEncounter->activeHold()->exists());
        $this->assertFalse($nextQueue->refresh()->visit->clinicalEncounter?->activeHold()->exists() ?? false);
        $this->assertSame(1, AuditLog::query()->where('event', 'consultation.held')->count());
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_authorized_http_first_save_accepts_explicit_null_plan_version(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = $this->serviceCatalogue($doctor);
        $this->selectBranch($doctor, $visit->branch);

        $this->actingAs($doctor)
            ->put(route('encounters.treatment-plan.save', $visit), $this->payload(
                null,
                services: [$this->servicePayload($service)],
            ))
            ->assertRedirect(route('encounters.show', $visit));

        $this->assertDatabaseHas('treatment_plans', [
            'clinical_encounter_id' => $visit->clinicalEncounter->id,
            'lock_version' => 1,
        ]);
        $this->assertDatabaseCount('treatment_plan_service_orders', 1);
    }

    public function test_service_only_save_lazily_creates_one_plan_without_allergy_or_operational_mutation(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $service = $this->serviceCatalogue($doctor);
        $visitVersion = $visit->lock_version;
        $queueVersion = $queue->lock_version;
        $encounterVersion = $encounter->lock_version;

        $this->assertNull(app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null)));
        $this->assertDatabaseCount('treatment_plans', 0);

        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, services: [[
            'public_id' => null,
            'catalogue_public_id' => $service->public_id,
            'quantity_ordered' => 1,
            'clinical_instruction' => 'Synthetic service instruction',
        ]]));

        $this->assertNotNull($plan);
        $this->assertSame(1, $plan->lock_version);
        $this->assertDatabaseCount('treatment_plans', 1);
        $this->assertDatabaseHas('treatment_plan_service_orders', [
            'treatment_plan_id' => $plan->id,
            'service_name_snapshot' => $service->display_name,
            'status' => TreatmentPlanServiceOrder::STATUS_ACTIVE,
        ]);
        $this->assertSame($visitVersion, $visit->refresh()->lock_version);
        $this->assertSame($queueVersion, $queue->refresh()->lock_version);
        $this->assertSame($encounterVersion, $encounter->refresh()->lock_version);
        $this->assertDatabaseCount('patient_allergy_profiles', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'treatment_plan.created']);
    }

    public function test_medicine_requires_exact_current_allergy_review_and_snapshots_catalogue(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $medicine = $this->medicineCatalogue($doctor);
        $attributes = $this->payload(null, medicines: [$this->medicinePayload($medicine)]);

        try {
            app(TreatmentPlanService::class)->save($doctor, $visit, $attributes);
            $this->fail('Medicine was ordered without a current Allergy review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allergy_review', $exception->errors());
        }
        $this->assertDatabaseCount('treatment_plans', 0);

        $profile = $this->reviewNoKnown($doctor, $visit);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $attributes);
        $order = $plan->medicineOrders()->sole();

        $this->assertSame($medicine->display_name, $order->medicine_name_snapshot);
        $this->assertSame($medicine->code, $order->medicine_code_snapshot);
        $this->assertSame($profile->lock_version, $order->allergy_profile_version_validated);
        $this->assertSame(TreatmentPlanMedicineOrder::STATUS_ACTIVE, $order->status);
    }

    public function test_inactive_catalogue_items_are_rejected_for_new_orders(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $medicine->forceFill(['is_active' => false])->save();
        $service = $this->serviceCatalogue($doctor);
        $service->forceFill(['is_active' => false])->save();

        foreach ([
            $this->payload(null, [$this->medicinePayload($medicine)]),
            $this->payload(null, services: [$this->servicePayload($service)]),
        ] as $payload) {
            try {
                app(TreatmentPlanService::class)->save($doctor, $visit, $payload);
                $this->fail('An inactive catalogue item was accepted for a new order.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }

        $this->assertDatabaseCount('treatment_plans', 0);
    }

    public function test_stale_review_rejects_medicine_mutation_but_does_not_block_service_only_change(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $profile = $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $service = $this->serviceCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [$this->medicinePayload($medicine)]));
        $order = $plan->medicineOrders()->sole();

        app(PatientAllergyService::class)->add($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $profile->lock_version,
            'allergen_text' => 'Synthetic newly recorded allergen',
            'category' => 'medication',
            'reaction_text' => null,
            'severity' => null,
        ]);

        $medicineEdit = $this->medicinePayload($medicine, $order->public_id);
        $medicineEdit['dosage'] = 'Changed synthetic dosage';
        try {
            app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($plan->lock_version, [$medicineEdit]));
            $this->fail('A stale Allergy review authorized a medicine change.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allergy_review', $exception->errors());
        }

        $servicePlan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($plan->lock_version, [$this->medicinePayload($medicine, $order->public_id)], [[
            'public_id' => null,
            'catalogue_public_id' => $service->public_id,
            'quantity_ordered' => 1,
            'clinical_instruction' => null,
        ]]));
        $this->assertSame(2, $servicePlan->lock_version);
        $this->assertDatabaseCount('treatment_plan_service_orders', 1);
        $this->assertSame($order->allergy_profile_version_validated, $order->refresh()->allergy_profile_version_validated);

        $profile = $profile->refresh();
        app(PatientAllergyService::class)->review($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $profile->lock_version,
        ]);
        $medicineEdit['dosage'] = 'Changed after current synthetic Allergy review';
        $updated = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(
            $servicePlan->lock_version,
            [$medicineEdit],
            [[
                'public_id' => $servicePlan->serviceOrders()->sole()->public_id,
                'catalogue_public_id' => null,
                'quantity_ordered' => 1,
                'clinical_instruction' => null,
            ]],
        ));

        $this->assertSame($profile->lock_version, $updated->medicineOrders()->sole()->allergy_profile_version_validated);
    }

    public function test_stale_allergy_review_does_not_block_medicine_reordering_or_withdrawal(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $profile = $this->reviewNoKnown($doctor, $visit);
        $medicineA = $this->medicineCatalogue($doctor);
        $medicineB = $this->medicineCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, [
            $this->medicinePayload($medicineA),
            $this->medicinePayload($medicineB),
        ]));
        $orderA = $plan->medicineOrders()->where('position', 1)->sole();
        $orderB = $plan->medicineOrders()->where('position', 2)->sole();
        $validatedVersions = [
            $orderA->id => $orderA->allergy_profile_version_validated,
            $orderB->id => $orderB->allergy_profile_version_validated,
        ];

        app(PatientAllergyService::class)->add($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $profile->lock_version,
            'allergen_text' => 'Synthetic profile mutation after medicine authorization',
            'category' => 'medication',
            'reaction_text' => null,
            'severity' => null,
        ]);

        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($plan->lock_version, [
            $this->medicinePayload($medicineB, $orderB->public_id),
            $this->medicinePayload($medicineA, $orderA->public_id),
        ]));
        $this->assertSame(2, $plan->lock_version);
        $this->assertSame(1, $orderB->refresh()->position);
        $this->assertSame(2, $orderA->refresh()->position);
        $this->assertSame($validatedVersions[$orderA->id], $orderA->allergy_profile_version_validated);
        $this->assertSame($validatedVersions[$orderB->id], $orderB->allergy_profile_version_validated);

        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($plan->lock_version, [
            $this->medicinePayload($medicineA, $orderA->public_id),
        ]));
        $this->assertSame(3, $plan->lock_version);
        $this->assertSame(TreatmentPlanMedicineOrder::STATUS_WITHDRAWN, $orderB->refresh()->status);
        $this->assertSame($validatedVersions[$orderB->id], $orderB->allergy_profile_version_validated);

        try {
            $orderB->forceFill(['dosage' => 'Forged post-withdrawal dosage'])->save();
            $this->fail('A withdrawn medicine order remained mutable.');
        } catch (LogicException) {
            $this->assertSame('Synthetic dosage', $orderB->refresh()->dosage);
        }
    }

    public function test_stale_and_future_plan_versions_cannot_overwrite_orders(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = $this->serviceCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, services: [$this->servicePayload($service)]));
        $order = $plan->serviceOrders()->sole();

        foreach ([null, 999] as $version) {
            $changed = $this->servicePayload($service, $order->public_id);
            $changed['quantity_ordered'] = 2;
            try {
                app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($version, services: [$changed]));
                $this->fail('A non-current Treatment Plan version was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('lock_version', $exception->errors());
            }
        }

        $this->assertSame('1.000', $order->refresh()->quantity_ordered);
        $this->assertSame(1, $plan->refresh()->lock_version);
    }

    public function test_repeated_identical_first_save_is_idempotent(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = $this->serviceCatalogue($doctor);
        $payload = $this->payload(null, services: [$this->servicePayload($service)]);

        $first = app(TreatmentPlanService::class)->save($doctor, $visit, $payload);
        $second = app(TreatmentPlanService::class)->save($doctor, $visit, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->lock_version);
        $this->assertDatabaseCount('treatment_plans', 1);
        $this->assertDatabaseCount('treatment_plan_service_orders', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'treatment_plan.created')->count());
    }

    public function test_removal_withdraws_order_and_catalogue_rename_does_not_rewrite_snapshot(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $service = $this->serviceCatalogue($doctor);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, services: [$this->servicePayload($service)]));
        $persisted = $plan->serviceOrders()->sole();
        $snapshot = $persisted->service_name_snapshot;

        try {
            $persisted->forceFill(['service_name_snapshot' => 'Forged rewritten snapshot'])->save();
            $this->fail('A persisted service snapshot was mutable.');
        } catch (LogicException) {
            $this->assertSame($snapshot, $persisted->refresh()->service_name_snapshot);
        }
        $service->forceFill(['display_name' => 'Renamed synthetic catalogue service', 'is_active' => false])->save();

        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload($plan->lock_version));
        $order = $plan->serviceOrders()->sole();

        $this->assertSame(TreatmentPlanServiceOrder::STATUS_WITHDRAWN, $order->status);
        $this->assertSame($snapshot, $order->service_name_snapshot);
        $this->assertNotNull($order->withdrawn_at);
        $this->assertSame($doctor->id, $order->withdrawn_by_user_id);
        $this->assertSame(0, $plan->serviceOrders()->where('status', 'active')->count());

        try {
            $order->forceFill(['status' => TreatmentPlanServiceOrder::STATUS_ACTIVE])->save();
            $this->fail('A withdrawn service order was reactivated.');
        } catch (LogicException) {
            $this->assertSame(TreatmentPlanServiceOrder::STATUS_WITHDRAWN, $order->refresh()->status);
        }

        try {
            $order->forceFill(['clinical_instruction' => 'Forged post-withdrawal instruction'])->save();
            $this->fail('A withdrawn service order remained mutable.');
        } catch (LogicException) {
            $this->assertNull($order->refresh()->clinical_instruction);
        }

        try {
            $order->delete();
            $this->fail('A persisted service order was physically deleted.');
        } catch (LogicException) {
            $this->assertDatabaseHas('treatment_plan_service_orders', ['id' => $order->id]);
        }
    }

    public function test_directory_is_explicit_and_audit_is_structural(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $encounter = $this->startEncounter($doctor, $visit, $queue);
        $service = $this->serviceCatalogue($doctor);
        app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, services: [$this->servicePayload($service)]));

        $detail = app(ClinicalEncounterDirectoryService::class)->detail($doctor, $visit);
        $encoded = json_encode($detail['treatmentPlan'], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($service->display_name, $encoded);
        foreach (['organisation_id', 'branch_id', 'clinical_encounter_id', 'created_by_user_id', 'updated_by_user_id', 'medicine_catalogue_item_id'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        $audit = AuditLog::query()->where('event', 'treatment_plan.created')->sole();
        $auditJson = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($service->display_name, $auditJson);
        $this->assertSame(['service_orders'], $audit->metadata['changed_sections']);
        $this->assertSame($encounter->id, $detail['treatmentPlan']['present'] ? $encounter->id : null);
    }

    public function test_non_clinical_roles_wrong_doctor_and_cross_branch_routes_are_denied(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $payload = $this->payload(null);

        foreach (['ca', 'ca_supervisor', 'director', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $visit->branch);
            $this->actingAs($actor)->put(route('encounters.treatment-plan.save', $visit), $payload)->assertForbidden();
        }

        $wrongDoctor = $this->doctor();
        $this->selectBranch($wrongDoctor, $visit->branch);
        $this->assertFalse(Gate::forUser($wrongDoctor)->allows('createTreatmentPlan', $visit->clinicalEncounter));
    }

    public function test_no_delete_finalize_dispense_stock_billing_or_completion_routes_exist(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $treatment = $routes->filter(fn ($route) => str_contains((string) $route->getName(), 'treatment-plan'));

        $this->assertFalse($treatment->contains(fn ($route) => in_array('DELETE', $route->methods(), true)));
        foreach (['finalize', 'complete', 'dispense', 'inventory', 'billing', 'payment'] as $term) {
            $this->assertFalse($treatment->contains(fn ($route) => str_contains($route->uri(), $term)));
        }
    }

    public function test_catalogue_search_is_bounded_tenant_scoped_wildcard_safe_and_minimized(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $literal = $this->medicineCatalogue($doctor);
        $literal->forceFill(['display_name' => 'Synthetic percent % medicine'])->save();
        for ($index = 0; $index < 25; $index++) {
            $item = $this->medicineCatalogue($doctor);
            $item->forceFill(['code' => 'SYN-MED-'.($index + 10), 'display_name' => 'Synthetic searchable medicine '.$index])->save();
        }
        $this->selectBranch($doctor, $visit->branch);

        $literalResponse = $this->postJson(route('encounters.treatment-plan.catalogue.medicines', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'query' => 'percent %',
        ])->assertOk();
        $this->assertCount(1, $literalResponse->json('data'));
        $this->assertSame($literal->public_id, $literalResponse->json('data.0.publicId'));

        $bounded = $this->postJson(route('encounters.treatment-plan.catalogue.medicines', $visit), [
            'expected_branch_id' => $visit->branch_id,
            'query' => 'searchable',
        ])->assertOk()->json('data');
        $this->assertCount(20, $bounded);
        $encoded = json_encode($bounded, JSON_THROW_ON_ERROR);
        foreach (['id', 'organisation_id', 'created_by_user_id', 'updated_by_user_id', 'price', 'stock'] as $forbidden) {
            $this->assertStringNotContainsString('"'.$forbidden.'"', $encoded);
        }
    }

    public function test_treatment_order_content_is_not_flashed_after_validation_failure(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $medicine = $this->medicineCatalogue($doctor);
        $this->selectBranch($doctor, $visit->branch);

        $payload = $this->payload(null, medicines: [$this->medicinePayload($medicine)]);
        $payload['medicines'][0]['quantity_ordered'] = 0;
        $payload['medicines'][0]['dosage'] = 'Synthetic private dosage';
        $payload['medicines'][0]['indication'] = 'Synthetic private indication';

        $this->actingAs($doctor)
            ->from(route('encounters.show', $visit))
            ->put(route('encounters.treatment-plan.save', $visit), $payload)
            ->assertSessionHasErrors('medicines.0.quantity_ordered')
            ->assertSessionMissing('_old_input.medicines')
            ->assertSessionMissing('_old_input.dosage')
            ->assertSessionMissing('_old_input.indication');
    }

    public function test_order_quantities_must_fit_the_exact_persisted_decimal_representation(): void
    {
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $medicine = $this->medicineCatalogue($doctor);
        $service = $this->serviceCatalogue($doctor);
        $this->selectBranch($doctor, $visit->branch);

        foreach (['0.0005', '1e9999', '1000000000'] as $quantity) {
            $medicineRow = $this->medicinePayload($medicine);
            $medicineRow['quantity_ordered'] = $quantity;
            $serviceRow = $this->servicePayload($service);
            $serviceRow['quantity_ordered'] = $quantity;

            $this->actingAs($doctor)
                ->from(route('encounters.show', $visit))
                ->put(route('encounters.treatment-plan.save', $visit), $this->payload(null, [$medicineRow], [$serviceRow]))
                ->assertSessionHasErrors([
                    'medicines.0.quantity_ordered',
                    'services.0.quantity_ordered',
                ]);
        }

        try {
            $invalidService = $this->servicePayload($service);
            $invalidService['quantity_ordered'] = '1e9999';
            app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, services: [$invalidService]));
            $this->fail('The domain service accepted an unrepresentable ordered quantity.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('services', $exception->errors());
        }

        $this->assertDatabaseCount('treatment_plans', 0);
    }

    public function test_billing_uses_actual_completed_medicine_once_and_never_moves_stock(): void
    {
        $f = $this->stockedPartialFixture();
        app(DispensaryService::class)->complete($f['ca'], $f['case'], ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version]);
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic billing prices', 'currency' => 'MYR'])->save();
        foreach (['consultation', 'medicine'] as $type) {
            $charge = new ChargeDefinition;
            $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => $type, 'code' => 'UAT-'.$type, 'display_name' => 'Synthetic '.$type,
                'source_key' => $type === 'consultation' ? 'consultation' : 'medicine:'.$f['item']->medicine_catalogue_item_id, 'medicine_catalogue_item_id' => $type === 'medicine' ? $f['item']->medicine_catalogue_item_id : null, 'unit' => $type === 'consultation' ? 'consultation' : $f['item']->unit_snapshot])->save();
            $price = new PriceEntry;
            $price->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $type === 'consultation' ? 4000 : 101, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
        }
        $before = [$f['balance']->refresh()->quantity, DB::table('stock_movements')->count(), $f['case']->treatmentPlan->attributesToArray()];
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);
        $invoice = $builder->finalize($f['ca'], $f['visit'], $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);
        $line = InvoiceLine::query()->where('invoice_id', $invoice->id)->where('line_type', 'medicine')->sole();
        $this->assertSame('0.500', $line->quantity);
        $this->assertSame(51, $line->line_total_sen);
        $this->assertSame(4051, $invoice->total_sen);
        $this->assertSame($before, [$f['balance']->refresh()->quantity, DB::table('stock_movements')->count(), $f['case']->treatmentPlan->fresh()->attributesToArray()]);
    }

    public function test_completed_zero_actual_medicine_is_not_billed_or_priced(): void
    {
        [$doctor, $ca, $visit, , $case, $item] = $this->patientDeclinedFixture();
        $item = app(DispensaryService::class)->updateItem($ca, $case->refresh(), $item->refresh(), ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version, 'status' => 'not_dispensed', 'quantity_dispensed' => '0', 'reason' => 'patient_declined', 'allocations' => []]);
        $this->selectBranch($doctor, $visit->branch);
        $exception = $item->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        app(DispensaryService::class)->acknowledge($doctor, $exception, ['case_lock_version' => $case->refresh()->lock_version, 'item_lock_version' => $item->lock_version]);
        $this->selectBranch($ca, $visit->branch);
        app(DispensaryService::class)->complete($ca, $case->refresh(), ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);
        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic prices', 'currency' => 'MYR'])->save();
        $charge = new ChargeDefinition;
        $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'type' => 'consultation', 'code' => 'UAT-CONSULT', 'display_name' => 'Synthetic consultation', 'source_key' => 'consultation', 'unit' => 'consultation'])->save();
        $price = new PriceEntry;
        $price->forceFill(['organisation_id' => $ca->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => 4000, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $ca->id])->save();
        $invoice = app(BillingBuilderService::class)->build($ca, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null]);
        $this->assertSame(4000, $invoice->total_sen);
        $this->assertSame('consultation', InvoiceLine::query()->sole()->line_type);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    private function reviewNoKnown(User $doctor, Visit $visit): PatientAllergyProfile
    {
        $service = app(PatientAllergyService::class);
        $profile = $service->declareNoKnown($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => null,
        ]);
        $service->review($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'profile_lock_version' => $profile->lock_version,
        ]);

        return $profile;
    }

    /** @return array{User, User, Visit, TreatmentPlan, DispensaryCase, DispensaryItem, DispensaryItemException} */
    private function patientDeclinedFixture(bool $multipleMedicines = false): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $this->reviewNoKnown($doctor, $visit);
        $medicine = $this->medicineCatalogue($doctor);
        $medicines = [$this->medicinePayload($medicine)];
        $services = [];
        if ($multipleMedicines) {
            $second = $this->medicineCatalogue($doctor);
            $second->forceFill(['display_name' => 'Synthetic second medicine'])->save();
            $medicines[] = $this->medicinePayload($second);
            $services[] = $this->servicePayload($this->serviceCatalogue($doctor));
        }
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, $this->payload(null, $medicines, $services));
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, [
            'expected_branch_id' => $visit->branch_id,
            'lock_version' => $plan->lock_version,
            'service_deliveries' => $plan->serviceOrders()->get()->map(fn ($order): array => ['order_public_id' => $order->public_id, 'disposition' => 'not_performed', 'quantity_performed' => '0'])->all(),
        ]);
        $this->selectBranch($ca, $visit->branch);
        $case = app(DispensaryService::class)->start($ca, $case, [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->lock_version,
        ]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->orderBy('id')->firstOrFail();
        $item = app(DispensaryService::class)->updateItem($ca, $case, $item, [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->lock_version,
            'item_lock_version' => $item->lock_version,
            'status' => 'not_dispensed',
            'quantity_dispensed' => '0.000',
            'reason' => 'patient_declined',
            'allocations' => [],
        ]);
        $case->refresh();
        $exception = $item->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        $this->selectBranch($doctor, $visit->branch);

        return [$doctor, $ca, $visit->refresh(), $plan->refresh(), $case, $item->refresh(), $exception];
    }

    /** @return array<string, mixed> */
    private function stockedPartialFixture(bool $multipleMedicines = false): array
    {
        [$doctor, $ca, $visit, , $case, $item] = $this->patientDeclinedFixture($multipleMedicines);
        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'code' => 'SYN-ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SYN-SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        $mapping = new MedicineCatalogueInventorySku;
        $mapping->forceFill(['organisation_id' => $ca->organisation_id, 'medicine_catalogue_item_id' => $item->medicine_catalogue_item_id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $doctor->id, 'approved_at' => now()->utc()])->save();
        $location = new InventoryLocation;
        $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'branch_id' => $visit->branch_id, 'code' => 'SYN-DISP-'.Str::upper(Str::random(5)), 'name' => 'Synthetic Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $ca->organisation_id, 'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-BATCH-'.Str::upper(Str::random(5)), 'expiry_date' => now()->setTimezone($visit->branch->timezone)->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $visit->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, [
            'expected_branch_id' => $visit->branch_id,
            'location_public_id' => $location->public_id,
            'sku_public_id' => $sku->public_id,
            'batch_public_id' => $batch->public_id,
            'quantity' => '10.000',
        ]);
        $this->selectBranch($ca, $visit->branch);
        $item = app(DispensaryService::class)->updateItem($ca, $case->refresh(), $item->refresh(), [
            'expected_branch_id' => $visit->branch_id,
            'case_lock_version' => $case->refresh()->lock_version,
            'item_lock_version' => $item->refresh()->lock_version,
            'status' => 'partial',
            'quantity_dispensed' => '0.500',
            'reason' => 'patient_declined',
            'allocations' => [[
                'location_public_id' => $location->public_id,
                'sku_public_id' => $sku->public_id,
                'batch_public_id' => $batch->public_id,
                'quantity' => '0.500',
            ]],
        ]);
        $case->refresh();
        $exception = $item->exceptions()->where('status', DispensaryItemException::STATUS_AWAITING)->sole();
        $this->selectBranch($doctor, $visit->branch);
        app(DispensaryService::class)->acknowledge($doctor, $exception, [
            'case_lock_version' => $case->lock_version,
            'item_lock_version' => $item->lock_version,
        ]);
        $this->selectBranch($ca, $visit->branch);
        $balance = InventoryStockBalance::query()->where('inventory_location_id', $location->id)->sole();

        return compact('doctor', 'ca', 'visit', 'case', 'item', 'location', 'sku', 'mapping', 'batch', 'balance');
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function editPayload(array $f, string $quantity, array $override = []): array
    {
        $item = $f['item']->refresh();

        return [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'item_lock_version' => $item->lock_version,
            'quantity_dispensed' => $quantity, 'dosage' => $item->dosage, 'frequency' => $item->frequency, 'duration' => $item->duration, 'route' => $item->route,
            'administration_instruction' => $item->administration_instruction, 'precaution' => $item->precaution,
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => $quantity]],
            ...$override,
        ];
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function stockedExtraMedicine(array $f, string $name = 'Synthetic extra medicine'): array
    {
        $medicine = $this->medicineCatalogue($f['doctor']);
        $medicine->forceFill(['display_name' => $name])->save();
        $organisation = $f['ca']->organisation_id;
        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'code' => 'SYN-ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic extra stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SYN-SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        (new MedicineCatalogueInventorySku)->forceFill(['organisation_id' => $organisation, 'medicine_catalogue_item_id' => $medicine->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $f['doctor']->id, 'approved_at' => now()->utc()])->save();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-BATCH-'.Str::upper(Str::random(5)), 'expiry_date' => now()->setTimezone($f['visit']->branch->timezone)->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $f['visit']->branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $f['visit']->branch_id, 'location_public_id' => $f['location']->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000']);
        $this->selectBranch($f['ca'], $f['visit']->branch);

        return compact('medicine', 'sku', 'batch');
    }

    public function test_a_ca_edits_a_doctor_line_and_the_doctors_order_is_kept(): void
    {
        $f = $this->stockedPartialFixture();
        $order = $f['item']->medicineOrder()->firstOrFail();
        $orderBefore = $order->attributesToArray();
        $payload = $this->editPayload($f, '2.000', ['dosage' => 'Two tablets', 'precaution' => 'Take with food']);

        $edited = app(DispensaryService::class)->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $payload);

        $this->assertSame('2.000', $edited->quantity_dispensed);
        $this->assertSame(DispensaryItem::STATUS_DISPENSED, $edited->status);
        $this->assertSame(DispensaryItem::CHANGE_EDITED, $edited->change_state);
        $this->assertSame('Two tablets', $edited->final_dosage);
        $this->assertSame('Take with food', $edited->final_precaution);
        $this->assertNull($edited->final_frequency, 'an unchanged field keeps the doctor\'s value');
        $this->assertSame('Synthetic dosage', $edited->dosage, 'the doctor\'s snapshot is never overwritten');
        $this->assertSame('Two tablets', $edited->effective('dosage'));
        $this->assertSame($f['ca']->id, $edited->edited_by_user_id);
        $this->assertSame($orderBefore, $order->fresh()->attributesToArray(), 'the treatment-plan order is untouched');
        $this->assertSame('2.000', (string) $edited->allocations()->sum('quantity') === '2' ? '2.000' : number_format((float) $edited->allocations()->sum('quantity'), 3, '.', ''));
        $this->assertSame(0, DispensaryItemException::query()->where('status', DispensaryItemException::STATUS_AWAITING)->count(), 'the earlier partial exception is superseded');

        $audit = AuditLog::query()->where('event', 'dispensary.item_edited')->sole();
        $this->assertSame('edited', $audit->metadata['change_state']);
        $this->assertStringNotContainsString('Two tablets', $audit->toJson(), 'the audit entry carries no medicine text');
    }

    public function test_an_edit_that_changes_nothing_stays_unchanged_and_the_doctors_quantity_stands(): void
    {
        $f = $this->stockedPartialFixture();

        $edited = app(DispensaryService::class)->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $this->editPayload($f, '1.000'));

        $this->assertSame(DispensaryItem::CHANGE_UNCHANGED, $edited->change_state);
        foreach (DispensaryItem::EDITABLE_TEXT as $final) {
            $this->assertNull($edited->{$final});
        }
        $this->assertSame('1.000', $edited->quantity_dispensed);
    }

    public function test_the_ca_cannot_save_a_line_without_batches_that_add_up_or_without_a_positive_quantity(): void
    {
        $f = $this->stockedPartialFixture();
        $service = app(DispensaryService::class);

        foreach ([
            ['allocations', $this->editPayload($f, '2.000', ['allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000']]])],
            ['quantity_dispensed', $this->editPayload($f, '0.000')],
            ['dosage', $this->editPayload($f, '1.000', ['dosage' => '   '])],
        ] as [$key, $payload]) {
            try {
                $service->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $payload);
                $this->fail("Accepted an invalid {$key}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }
        $stale = $this->editPayload($f, '1.000');
        $stale['item_lock_version'] = $f['item']->refresh()->lock_version + 5;
        $this->expectException(ValidationException::class);
        $service->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $stale);
    }

    public function test_only_the_ca_who_owns_the_case_can_edit_add_or_remove(): void
    {
        $f = $this->stockedPartialFixture();
        $other = $this->actor('ca');
        $this->selectBranch($other, $f['visit']->branch);

        foreach (['editItem' => $this->editPayload($f, '1.000'), 'removeItem' => ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'item_lock_version' => $f['item']->lock_version]] as $method => $payload) {
            try {
                app(DispensaryService::class)->{$method}($other, $f['case']->refresh(), $f['item']->refresh(), $payload);
                $this->fail("{$method} was allowed for a CA who does not own the case.");
            } catch (AuthorizationException) {
                $this->assertSame(DispensaryItem::STATUS_PARTIAL, $f['item']->fresh()->status);
            }
        }
    }

    public function test_the_ca_adds_a_medicine_the_doctor_did_not_order_and_cannot_add_it_twice(): void
    {
        $f = $this->stockedPartialFixture();
        $extra = $this->stockedExtraMedicine($f);
        $payload = [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'medicine_public_id' => $extra['medicine']->public_id,
            'quantity_dispensed' => '3.000', 'dosage' => 'One sachet', 'frequency' => 'Twice daily', 'duration' => '3 days',
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $extra['sku']->public_id, 'batch_public_id' => $extra['batch']->public_id, 'quantity' => '3.000']],
        ];

        $added = app(DispensaryService::class)->addItem($f['ca'], $f['case']->refresh(), $payload);

        $this->assertSame(DispensaryItem::SOURCE_CA, $added->source);
        $this->assertSame(DispensaryItem::CHANGE_ADDED, $added->change_state);
        $this->assertNull($added->treatment_plan_medicine_order_id);
        $this->assertSame('3.000', $added->quantity_dispensed);
        $this->assertSame('Synthetic extra medicine', $added->medicine_name_snapshot);
        $this->assertSame(1, $added->allocations()->count());
        $this->assertSame(2, $f['case']->refresh()->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.item_added')->count());

        $payload['case_lock_version'] = $f['case']->refresh()->lock_version;
        try {
            app(DispensaryService::class)->addItem($f['ca'], $f['case'], $payload);
            $this->fail('The same medicine was added twice.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('medicine_public_id', $exception->errors());
        }
        $payload['medicine_public_id'] = (string) Str::uuid();
        $payload['case_lock_version'] = $f['case']->refresh()->lock_version;
        $this->expectException(ValidationException::class);
        app(DispensaryService::class)->addItem($f['ca'], $f['case'], $payload);
    }

    public function test_completing_records_the_cas_verification_without_an_allergy_tick(): void
    {
        $f = $this->stockedPartialFixture();
        app(DispensaryService::class)->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $this->editPayload($f, '1.000'));
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'dispense')->count());

        app(DispensaryService::class)->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version]);

        $handoff = DispensaryHandoff::query()->where('dispensary_case_id', $f['case']->id)->sole();
        $this->assertNotNull($handoff->ca_verified_at);
        $this->assertSame($f['ca']->id, $handoff->completed_by_user_id);
        $this->assertTrue(AuditLog::query()->where('event', 'dispensary.completed')->sole()->metadata['ca_verified']);
    }

    public function test_a_removed_line_needs_no_doctor_acknowledgement_and_moves_no_stock(): void
    {
        $f = $this->stockedPartialFixture();
        $before = $f['balance']->refresh()->quantity;

        $removed = app(DispensaryService::class)->removeItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);

        $this->assertSame(DispensaryItem::CHANGE_REMOVED, $removed->change_state);
        $this->assertSame(DispensaryItem::STATUS_NOT_DISPENSED, $removed->status);
        $this->assertSame('0.000', $removed->quantity_dispensed);
        $this->assertSame(DispensaryItem::REASON_CA_REMOVED, $removed->reason);
        $this->assertSame(0, $removed->allocations()->count());
        $this->assertSame($f['item']->id, DispensaryItem::query()->whereKey($removed->id)->value('id'), 'the row stays so the record can show it');

        app(DispensaryService::class)->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version]);

        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $f['case']->refresh()->status);
        $this->assertSame($before, $f['balance']->refresh()->quantity);
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.completed')->sole()->metadata['removed_lines']);
    }

    public function test_billing_and_stock_use_the_cas_final_list(): void
    {
        $f = $this->stockedPartialFixture();
        $extra = $this->stockedExtraMedicine($f);
        $service = app(DispensaryService::class);
        $service->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $this->editPayload($f, '2.000'));
        $service->addItem($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version, 'medicine_public_id' => $extra['medicine']->public_id,
            'quantity_dispensed' => '3.000', 'dosage' => 'One', 'frequency' => 'Daily',
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $extra['sku']->public_id, 'batch_public_id' => $extra['batch']->public_id, 'quantity' => '3.000']],
        ]);
        $service->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version]);
        $this->assertSame('8.000', $f['balance']->refresh()->quantity, 'the doctor line was dispensed at the CA quantity');
        $this->assertSame(2, DB::table('stock_movements')->where('movement_type', 'dispense')->count());

        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic billing prices', 'currency' => 'MYR'])->save();
        $charges = [['consultation', 'consultation', null, 4000], ['medicine', 'medicine:'.$f['item']->medicine_catalogue_item_id, $f['item']->medicine_catalogue_item_id, 100], ['medicine', 'medicine:'.$extra['medicine']->id, $extra['medicine']->id, 200]];
        foreach ($charges as $index => [$type, $source, $medicineId, $price]) {
            $charge = new ChargeDefinition;
            $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => $type, 'code' => 'UAT-'.$type.$index, 'display_name' => 'Synthetic '.$type, 'source_key' => $source, 'medicine_catalogue_item_id' => $medicineId, 'unit' => $type === 'consultation' ? 'consultation' : 'unit'])->save();
            (new PriceEntry)->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $price, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
        }
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);

        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->where('line_type', 'medicine')->orderBy('id')->get();
        $this->assertSame(['2.000', '3.000'], $lines->pluck('quantity')->all());
        $this->assertSame(4000 + 2 * 100 + 3 * 200, $invoice->total_sen);
        $builder->finalize($f['ca'], $f['visit'], $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);
    }

    public function test_labels_print_the_cas_final_text_and_skip_removed_lines(): void
    {
        $f = $this->stockedPartialFixture();
        app(DispensaryService::class)->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $this->editPayload($f, '2.000', ['dosage' => 'Two tablets']));

        $labels = app(DispensaryDirectoryService::class)->labels($f['ca'], $f['case']->refresh());
        $this->assertSame('Two tablets', $labels['items'][0]['dosage']);
        $this->assertSame('2.000', $labels['items'][0]['quantity']);

        app(DispensaryService::class)->removeItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $f['item']->lock_version]);
        $this->expectException(NotFoundHttpException::class);
        app(DispensaryDirectoryService::class)->labels($f['ca'], $f['case']->refresh());
    }

    public function test_the_dispensary_detail_shows_the_cas_version_beside_the_doctors_original(): void
    {
        $f = $this->stockedPartialFixture();
        app(DispensaryService::class)->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $this->editPayload($f, '2.000', ['dosage' => 'Two tablets']));

        $detail = app(DispensaryDirectoryService::class)->detail($f['ca'], $f['case']->refresh());
        $line = $detail['items'][0];
        $this->assertSame('Two tablets', $line['dosage']);
        $this->assertSame('Synthetic dosage', $line['original']['dosage']);
        $this->assertSame('edited', $line['changeState']);
        $this->assertSame('doctor', $line['source']);
        $this->assertArrayHasKey('allergies', $detail['allergySafety']);
    }

    public function test_the_edit_routes_stay_on_the_case_when_refused_and_are_closed_to_other_roles(): void
    {
        $f = $this->stockedPartialFixture();
        $url = route('dispensary.items.edit', [$f['case'], $f['item']]);

        $this->actingAs($f['ca'])->withSession(['_previous' => ['url' => 'http://localhost/check-in/status']])
            ->put($url, [...$this->editPayload($f, '2.000'), 'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000']]])
            ->assertRedirect(route('dispensary.show', $f['case']))->assertSessionHasErrors('allocations');

        $this->actingAs($f['ca'])->put($url, $this->editPayload($f, '2.000'))
            ->assertRedirect(route('dispensary.show', $f['case']))->assertSessionHasNoErrors();
        $this->assertSame('2.000', $f['item']->fresh()->quantity_dispensed);

        $search = $this->actingAs($f['ca'])->postJson(route('dispensary.medicines.search', $f['case']), ['query' => 'Synthetic'])->assertOk();
        $this->assertIsArray($search->json('data'));

        $this->selectBranch($f['doctor'], $f['visit']->branch);
        $this->actingAs($f['doctor'])->put($url, $this->editPayload($f, '3.000'))->assertForbidden();
        $this->actingAs($f['doctor'])->postJson(route('dispensary.medicines.search', $f['case']), ['query' => 'Synthetic'])->assertForbidden();
    }

    public function test_the_database_rejects_inconsistent_edited_or_added_lines(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The line-state constraints exist on PostgreSQL only.');
        }
        $f = $this->stockedPartialFixture();
        $id = $f['item']->id;
        $bad = [
            'an unchanged line dispensed above the doctor\'s quantity' => ['status' => 'dispensed', 'quantity_dispensed' => '5.000', 'reason' => null, 'change_state' => 'unchanged'],
            'a doctor line marked as added' => ['status' => 'dispensed', 'quantity_dispensed' => '1.000', 'reason' => null, 'change_state' => 'added'],
            'a CA line that still points at a doctor order' => ['status' => 'dispensed', 'quantity_dispensed' => '1.000', 'reason' => null, 'source' => 'ca', 'change_state' => 'added'],
            'a removed line without the removal reason' => ['status' => 'not_dispensed', 'quantity_dispensed' => '0.000', 'reason' => 'other', 'change_state' => 'removed'],
            'the removal reason on a line that is not removed' => ['status' => 'not_dispensed', 'quantity_dispensed' => '0.000', 'reason' => 'ca_removed', 'change_state' => 'edited'],
            'an unknown change state' => ['status' => 'dispensed', 'quantity_dispensed' => '1.000', 'reason' => null, 'change_state' => 'rewritten'],
        ];
        foreach ($bad as $label => $values) {
            try {
                DB::transaction(fn () => DB::table('dispensary_items')->where('id', $id)->update($values));
                $this->fail("The database accepted {$label}.");
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0] ?? null, $label);
            }
        }
        // An edited line may exceed the doctor's quantity; that is the point of the change.
        DB::table('dispensary_items')->where('id', $id)->update(['status' => 'dispensed', 'quantity_dispensed' => '5.000', 'reason' => null, 'change_state' => 'edited']);
        $this->assertSame('5.000', DB::table('dispensary_items')->where('id', $id)->value('quantity_dispensed'));
    }

    /** @param array<string, mixed> $f */
    private function serviceLine(array $f): DispensaryServiceLine
    {
        return DispensaryServiceLine::query()->where('dispensary_handoff_id', $f['case']->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->value('id'))->whereNotNull('treatment_plan_service_order_id')->sole();
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function servicePayloadFor(array $f, DispensaryServiceLine $line, string $quantity, ?string $instruction = null): array
    {
        return ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'line_lock_version' => $line->refresh()->lock_version, 'quantity_performed' => $quantity, 'clinical_instruction' => $instruction ?? $line->clinical_instruction];
    }

    public function test_a_ca_confirms_and_edits_a_service_and_the_doctors_evidence_is_kept(): void
    {
        $f = $this->stockedPartialFixture(true);
        $line = $this->serviceLine($f);
        $order = $line->serviceOrder()->firstOrFail();
        $orderBefore = $order->attributesToArray();
        $deliveryBefore = DB::table('service_deliveries')->where('treatment_plan_service_order_id', $order->id)->first();
        $this->assertSame('not_performed', $line->disposition, 'the line starts from the doctor\'s confirmation');
        $this->assertSame(DispensaryServiceLine::CHANGE_UNCHANGED, $line->change_state);

        $edited = app(DispensaryService::class)->editServiceLine($f['ca'], $f['case']->refresh(), $line, $this->servicePayloadFor($f, $line, '1.000', 'Done at the counter'));

        $this->assertSame('performed', $edited->disposition);
        $this->assertSame('1.000', $edited->quantity_performed);
        $this->assertSame(DispensaryServiceLine::CHANGE_EDITED, $edited->change_state);
        $this->assertSame('Done at the counter', $edited->final_instruction);
        $this->assertSame('Done at the counter', $edited->effectiveInstruction());
        $this->assertSame($f['ca']->id, $edited->confirmed_by_user_id, 'the CA confirms performance');
        $this->assertSame($orderBefore, $order->fresh()->attributesToArray(), 'the treatment-plan service order is untouched');
        $this->assertEquals($deliveryBefore, DB::table('service_deliveries')->where('treatment_plan_service_order_id', $order->id)->first(), 'the doctor\'s delivery evidence is untouched');
        $audit = AuditLog::query()->where('event', 'dispensary.service_edited')->sole();
        $this->assertStringNotContainsString('Done at the counter', $audit->toJson());

        $again = app(DispensaryService::class)->editServiceLine($f['ca'], $f['case']->refresh(), $edited, $this->servicePayloadFor($f, $edited, '0', $line->clinical_instruction));
        $this->assertSame('not_performed', $again->disposition);
        $this->assertSame(DispensaryServiceLine::CHANGE_UNCHANGED, $again->change_state, 'back to the doctor\'s values means unchanged');
        $this->assertNull($again->final_instruction);
    }

    public function test_the_ca_adds_removes_and_restores_services(): void
    {
        $f = $this->stockedPartialFixture(true);
        $extra = $this->serviceCatalogue($f['doctor']);
        $service = app(DispensaryService::class);
        $add = ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'service_public_id' => $extra->public_id, 'quantity_performed' => '2.000', 'clinical_instruction' => 'Walk-in service'];

        $added = $service->addServiceLine($f['ca'], $f['case']->refresh(), $add);

        $this->assertSame(DispensaryServiceLine::SOURCE_CA, $added->source);
        $this->assertSame(DispensaryServiceLine::CHANGE_ADDED, $added->change_state);
        $this->assertNull($added->treatment_plan_service_order_id);
        $this->assertSame('performed', $added->disposition);
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.service_added')->count());

        foreach ([['service_public_id', [...$add, 'case_lock_version' => $f['case']->refresh()->lock_version]], ['service_public_id', [...$add, 'service_public_id' => (string) Str::uuid(), 'case_lock_version' => $f['case']->lock_version]], ['quantity_performed', [...$add, 'quantity_performed' => '0', 'service_public_id' => $this->serviceCatalogue($f['doctor'])->public_id, 'case_lock_version' => $f['case']->lock_version]]] as [$key, $payload]) {
            try {
                $service->addServiceLine($f['ca'], $f['case']->refresh(), $payload);
                $this->fail("Accepted an invalid {$key}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
            }
        }

        $removed = $service->removeServiceLine($f['ca'], $f['case']->refresh(), $added, ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version, 'line_lock_version' => $added->refresh()->lock_version]);
        $this->assertSame(DispensaryServiceLine::CHANGE_REMOVED, $removed->change_state);
        $this->assertSame('0.000', $removed->quantity_performed);
        $this->assertSame('not_performed', $removed->disposition);
        $this->assertDatabaseHas('dispensary_service_lines', ['id' => $added->id]);

        $restored = $service->editServiceLine($f['ca'], $f['case']->refresh(), $removed, $this->servicePayloadFor($f, $removed, '2.000', 'Walk-in service'));
        $this->assertSame(DispensaryServiceLine::CHANGE_ADDED, $restored->change_state);
        $this->assertSame('performed', $restored->disposition);
    }

    public function test_service_lines_need_the_owning_ca_and_the_current_version(): void
    {
        $f = $this->stockedPartialFixture(true);
        $line = $this->serviceLine($f);
        $other = $this->actor('ca');
        $this->selectBranch($other, $f['visit']->branch);

        try {
            app(DispensaryService::class)->editServiceLine($other, $f['case']->refresh(), $line, $this->servicePayloadFor($f, $line, '1.000'));
            $this->fail('A CA who does not own the case edited a service.');
        } catch (AuthorizationException) {
            $this->assertSame('not_performed', $line->fresh()->disposition);
        }
        $stale = $this->servicePayloadFor($f, $line, '1.000');
        $stale['line_lock_version'] += 5;
        try {
            app(DispensaryService::class)->editServiceLine($f['ca'], $f['case']->refresh(), $line, $stale);
            $this->fail('A stale service version was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('line_lock_version', $exception->errors());
        }
        $this->expectException(ValidationException::class);
        app(DispensaryService::class)->editServiceLine($f['ca'], $f['case']->refresh(), $line, $this->servicePayloadFor($f, $line, '-1'));
    }

    public function test_billing_uses_the_cas_final_service_list(): void
    {
        $f = $this->stockedPartialFixture(true);
        $service = app(DispensaryService::class);
        $line = $this->serviceLine($f);
        $extra = $this->serviceCatalogue($f['doctor']);
        $secondMedicine = $f['case']->refresh()->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->where('id', '<>', $f['item']->id)->sole();
        $service->removeItem($f['ca'], $f['case']->refresh(), $secondMedicine, ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version, 'item_lock_version' => $secondMedicine->lock_version]);
        $edited = $service->editServiceLine($f['ca'], $f['case']->refresh(), $line, $this->servicePayloadFor($f, $line, '1.000'));
        $added = $service->addServiceLine($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version, 'service_public_id' => $extra->public_id, 'quantity_performed' => '2.000', 'clinical_instruction' => null]);
        $service->complete($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->lock_version]);

        $book = new PriceBook;
        $book->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'scope_key' => 'organisation', 'name' => 'Synthetic billing prices', 'currency' => 'MYR'])->save();
        $charges = [
            ['consultation', 'consultation', ['unit' => 'consultation'], 4000],
            ['medicine', 'medicine:'.$f['item']->medicine_catalogue_item_id, ['medicine_catalogue_item_id' => $f['item']->medicine_catalogue_item_id, 'unit' => $f['item']->unit_snapshot], 100],
            ['service', 'service:'.$edited->clinical_service_catalogue_item_id, ['clinical_service_catalogue_item_id' => $edited->clinical_service_catalogue_item_id, 'unit' => $edited->unit_snapshot], 500],
            ['service', 'service:'.$added->clinical_service_catalogue_item_id, ['clinical_service_catalogue_item_id' => $added->clinical_service_catalogue_item_id, 'unit' => $added->unit_snapshot], 300],
        ];
        foreach ($charges as $index => [$type, $source, $extraColumns, $price]) {
            $charge = new ChargeDefinition;
            $charge->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'type' => $type, 'code' => 'UAT-'.$type.$index, 'display_name' => 'Synthetic '.$type, 'source_key' => $source, ...$extraColumns])->save();
            (new PriceEntry)->forceFill(['organisation_id' => $f['ca']->organisation_id, 'price_book_id' => $book->id, 'charge_definition_id' => $charge->id, 'version' => 1, 'unit_price_sen' => $price, 'effective_at' => now()->subMinute(), 'published_by_user_id' => $f['ca']->id])->save();
        }
        $builder = app(BillingBuilderService::class);
        $invoice = $builder->build($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => null]);

        $lines = InvoiceLine::query()->where('invoice_id', $invoice->id)->where('line_type', 'service')->orderBy('id')->get();
        $this->assertSame(['1.000', '2.000'], $lines->pluck('quantity')->all());
        $this->assertSame([$edited->id, $added->id], $lines->pluck('dispensary_service_line_id')->all());
        $this->assertSame([null, null], $lines->pluck('service_delivery_id')->all());
        $this->assertSame(['dservice:'.$edited->id, 'dservice:'.$added->id], $lines->pluck('source_key')->all());
        $this->assertSame(4000 + 50 + 500 + 600, $invoice->total_sen);
        $builder->finalize($f['ca'], $f['visit'], $invoice, ['expected_branch_id' => $f['visit']->branch_id, 'lock_version' => $invoice->lock_version]);
    }

    public function test_the_dispensary_detail_lists_services_beside_the_doctors_confirmation(): void
    {
        $f = $this->stockedPartialFixture(true);
        $line = $this->serviceLine($f);
        app(DispensaryService::class)->editServiceLine($f['ca'], $f['case']->refresh(), $line, $this->servicePayloadFor($f, $line, '1.000', 'Done'));

        $detail = app(DispensaryDirectoryService::class)->detail($f['ca'], $f['case']->refresh());
        $this->assertCount(1, $detail['services']);
        $row = $detail['services'][0];
        $this->assertSame('1.000', $row['quantityPerformed']);
        $this->assertSame('Done', $row['instruction']);
        $this->assertSame('edited', $row['changeState']);
        $this->assertSame('0.000', $row['original']['quantity']);
        $this->assertSame('doctor', $row['source']);
    }

    public function test_the_service_routes_stay_on_the_case_when_refused_and_are_closed_to_other_roles(): void
    {
        $f = $this->stockedPartialFixture(true);
        $line = $this->serviceLine($f);
        $url = route('dispensary.services.edit', [$f['case'], $line]);

        $this->actingAs($f['ca'])->withSession(['_previous' => ['url' => 'http://localhost/check-in/status']])
            ->put($url, [...$this->servicePayloadFor($f, $line, '1.000'), 'quantity_performed' => 'abc'])
            ->assertRedirect(route('dispensary.show', $f['case']))->assertSessionHasErrors('quantity_performed');

        $this->actingAs($f['ca'])->put($url, $this->servicePayloadFor($f, $line, '1.000'))
            ->assertRedirect(route('dispensary.show', $f['case']))->assertSessionHasNoErrors();
        $this->assertSame('performed', $line->fresh()->disposition);

        $this->actingAs($f['ca'])->postJson(route('dispensary.services.search', $f['case']), ['query' => 'Synthetic'])->assertOk()->assertJsonStructure(['data']);

        $this->selectBranch($f['doctor'], $f['visit']->branch);
        $this->actingAs($f['doctor'])->put($url, $this->servicePayloadFor($f, $line, '3.000'))->assertForbidden();
        $this->actingAs($f['doctor'])->postJson(route('dispensary.services.search', $f['case']), ['query' => 'Synthetic'])->assertForbidden();
    }

    public function test_the_database_rejects_inconsistent_service_lines_and_the_backfill_restores_them(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The service-line constraints exist on PostgreSQL only.');
        }
        $f = $this->stockedPartialFixture(true);
        $line = $this->serviceLine($f);
        $bad = [
            'performed with zero quantity' => ['disposition' => 'performed', 'quantity_performed' => '0.000'],
            'not performed with a quantity' => ['disposition' => 'not_performed', 'quantity_performed' => '1.000'],
            'a doctor line marked added' => ['disposition' => 'performed', 'quantity_performed' => '1.000', 'change_state' => 'added'],
            'a removed line that is performed' => ['disposition' => 'performed', 'quantity_performed' => '1.000', 'change_state' => 'removed'],
            'an unknown change state' => ['change_state' => 'rewritten'],
            'a CA line that points at a doctor order' => ['source' => 'ca', 'change_state' => 'added'],
        ];
        foreach ($bad as $label => $values) {
            try {
                DB::transaction(fn () => DB::table('dispensary_service_lines')->where('id', $line->id)->update($values));
                $this->fail("The database accepted {$label}.");
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0] ?? null, $label);
            }
        }

        $before = DB::table('dispensary_service_lines')->where('id', $line->id)->first();
        DB::table('dispensary_service_lines')->where('id', $line->id)->delete();
        $migration = require base_path('database/migrations/2026_10_09_000200_add_dispensary_service_lines.php');
        (new \ReflectionMethod($migration, 'backfill'))->invoke($migration);
        $after = DB::table('dispensary_service_lines')->where('treatment_plan_service_order_id', $before->treatment_plan_service_order_id)->sole();
        foreach (['dispensary_handoff_id', 'clinical_service_catalogue_item_id', 'service_name_snapshot', 'quantity_ordered', 'disposition', 'quantity_performed', 'doctor_quantity_performed', 'source', 'change_state', 'confirmed_by_user_id'] as $column) {
            $this->assertEquals($before->{$column}, $after->{$column}, $column);
        }
    }

    private function medicineCatalogue(User $doctor): MedicineCatalogueItem
    {
        $item = new MedicineCatalogueItem;
        $item->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $doctor->organisation_id,
            'code' => 'SYN-MED-'.Str::upper(Str::random(8)), 'display_name' => 'Synthetic medicine', 'strength_text' => 'Synthetic strength',
            'dosage_form' => 'Synthetic form', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED,
            'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id,
        ])->save();

        return $item;
    }

    private function serviceCatalogue(User $doctor): ClinicalServiceCatalogueItem
    {
        $item = new ClinicalServiceCatalogueItem;
        $item->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $doctor->organisation_id,
            'code' => 'SYN-SVC-'.Str::upper(Str::random(8)), 'display_name' => 'Synthetic clinical service', 'order_unit' => 'service',
            'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id,
        ])->save();

        return $item;
    }

    /** @return array<string, mixed> */
    /**
     * @param  list<array<string, mixed>>  $medicines
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    private function payload(?int $version, array $medicines = [], array $services = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $version, 'medicines' => $medicines, 'services' => $services];
    }

    /** @return array<string, mixed> */
    private function medicinePayload(MedicineCatalogueItem $item, ?string $publicId = null): array
    {
        return ['public_id' => $publicId, 'catalogue_public_id' => $publicId ? null : $item->public_id, 'quantity_ordered' => 1, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null];
    }

    /** @return array<string, mixed> */
    private function servicePayload(ClinicalServiceCatalogueItem $item, ?string $publicId = null): array
    {
        return ['public_id' => $publicId, 'catalogue_public_id' => $publicId ? null : $item->public_id, 'quantity_ordered' => 1, 'clinical_instruction' => null];
    }
}
