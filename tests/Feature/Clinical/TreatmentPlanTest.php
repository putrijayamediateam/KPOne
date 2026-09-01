<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\TreatmentPlanMedicineOrder;
use App\Domain\Clinical\Models\TreatmentPlanServiceOrder;
use App\Domain\Clinical\Services\ClinicalEncounterDirectoryService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class TreatmentPlanTest extends ClinicalTestCase
{
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
