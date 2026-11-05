<?php

namespace Tests\Feature\Clinical;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryAllergyConfirmation;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Models\PatientAllergyProfile;
use App\Domain\Clinical\Models\PatientAllergyRecord;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Organisation\Inventory\Models\InventoryBatch;
use App\Domain\Organisation\Inventory\Models\InventoryItem;
use App\Domain\Organisation\Inventory\Models\InventoryLocation;
use App\Domain\Organisation\Inventory\Models\InventorySku;
use App\Domain\Organisation\Inventory\Models\MedicineCatalogueInventorySku;
use App\Domain\Organisation\Inventory\Services\InventoryMovementService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\Support\OtcDispensaryFixtures;

/**
 * DSP-1 (owner decision 2026-11-05): when a CA adds or edits a medicine at Dispensary and it matches
 * one of the patient's active allergy records by text, the save needs the CA's explicit confirmation,
 * which is recorded append-only. A warning, never a block. Synthetic data only.
 */
class DispensaryAllergyWarningTest extends ClinicalTestCase
{
    use OtcDispensaryFixtures;

    private function allergy(int $patientId, User $by, string $allergen, string $status = PatientAllergyRecord::STATUS_ACTIVE): PatientAllergyRecord
    {
        $profile = PatientAllergyProfile::query()->where('patient_id', $patientId)->first()
            ?? PatientAllergyProfile::factory()->create(['patient_id' => $patientId, 'status' => PatientAllergyProfile::STATUS_HAS_ALLERGIES, 'reviewed_at' => now()->utc(), 'reviewed_by_user_id' => $by->id, 'updated_by_user_id' => $by->id]);

        return PatientAllergyRecord::factory()->create([
            'patient_allergy_profile_id' => $profile->id, 'allergen_text' => $allergen, 'status' => $status, 'recorded_by_user_id' => $by->id,
            'entered_in_error_at' => $status === PatientAllergyRecord::STATUS_ENTERED_IN_ERROR ? now()->utc() : null,
            'entered_in_error_by_user_id' => $status === PatientAllergyRecord::STATUS_ENTERED_IN_ERROR ? $by->id : null,
        ]);
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function otcEditPayload(array $f, DispensaryCase $case, DispensaryItem $item, array $override = []): array
    {
        $payload = $this->addPayload($f, $case, '1.000', $override);
        unset($payload['medicine_public_id']);

        return [...$payload, 'item_lock_version' => $item->refresh()->lock_version];
    }

    private function refusedForAllergy(callable $action): ValidationException
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_confirmation', $e->errors());

            return $e;
        }
        $this->fail('A matching save without the confirmation must be refused.');
    }

    public function test_an_otc_add_without_any_allergy_record_needs_no_confirmation(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);

        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));

        $this->assertSame(1, DispensaryItem::query()->count());
        $this->assertSame(0, DispensaryAllergyConfirmation::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'dispensary.allergy_match_confirmed')->count());
    }

    public function test_a_non_matching_or_entered_in_error_allergy_needs_no_confirmation(): void
    {
        $f = $this->fixture();
        $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic seafood');
        $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic OTC medicine', PatientAllergyRecord::STATUS_ENTERED_IN_ERROR);
        $case = $this->open($f);

        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));

        $this->assertSame(1, DispensaryItem::query()->count());
        $this->assertSame(0, DispensaryAllergyConfirmation::query()->count());
    }

    public function test_an_otc_add_matching_an_allergy_is_refused_at_the_service_without_the_confirmation(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], "  SYNTHETIC   otc\tMedicine ");
        $case = $this->open($f);
        $versionBefore = $case->refresh()->lock_version;

        $e = $this->refusedForAllergy(fn () => app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case)));

        $this->assertStringContainsString('I have checked with the patient/doctor', $e->errors()['allergy_confirmation'][0]);
        $this->assertSame(0, DispensaryItem::query()->count(), 'nothing is saved');
        $this->assertSame(0, DispensaryAllergyConfirmation::query()->count());
        $this->assertSame($versionBefore, $case->refresh()->lock_version);
        $this->assertSame(0, AuditLog::query()->where('event', 'dispensary.item_added')->count());

        $this->refusedForAllergy(fn () => app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => [(string) Str::uuid()]])));
        $this->assertSame(0, DispensaryItem::query()->count(), 'an unrelated id is not a confirmation');
        $this->assertTrue($record->exists);
    }

    public function test_an_otc_add_with_the_confirmation_is_saved_and_recorded_append_only(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'synthetic otc medicine');
        $case = $this->open($f);

        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => [$record->public_id]]));

        $confirmation = DispensaryAllergyConfirmation::query()->sole();
        $this->assertSame($f['ca']->id, $confirmation->confirmed_by_user_id);
        $this->assertNotNull($confirmation->confirmed_at);
        $this->assertSame($item->id, $confirmation->dispensary_item_id);
        $this->assertSame($record->id, $confirmation->patient_allergy_record_id);
        $this->assertSame($case->id, $confirmation->dispensary_case_id);
        $this->assertSame($case->handoffs()->sole()->id, $confirmation->dispensary_handoff_id);
        $this->assertSame($f['visit']->branch_id, $confirmation->branch_id);
        $this->assertSame(DispensaryAllergyConfirmation::ACTION_ADD, $confirmation->action);
        $this->assertSame($item->lock_version, $confirmation->item_lock_version);

        $audit = AuditLog::query()->where('event', 'dispensary.allergy_match_confirmed')->sole();
        $this->assertSame(['record_version', 'action', 'matched_records'], array_keys($audit->metadata));
        $this->assertSame(['add', 1], [$audit->metadata['action'], $audit->metadata['matched_records']]);
        $encoded = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsStringIgnoringCase('synthetic', $encoded, 'no medicine or allergy text in audit metadata');
        $this->assertStringNotContainsStringIgnoringCase('medicine', $encoded);

        try {
            $confirmation->forceFill(['action' => 'edit'])->save();
            $this->fail('The model must refuse an update.');
        } catch (LogicException) {
        }
        try {
            $confirmation->delete();
            $this->fail('The model must refuse a delete.');
        } catch (LogicException) {
        }
        foreach (["UPDATE dispensary_allergy_confirmations SET action='edit'", 'DELETE FROM dispensary_allergy_confirmations', 'TRUNCATE dispensary_allergy_confirmations'] as $sql) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail('The database must refuse: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(1, DispensaryAllergyConfirmation::query()->count());
    }

    public function test_the_database_refuses_a_confirmation_for_another_patients_allergy(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'synthetic otc medicine');
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => [$record->public_id]]));
        $stranger = $this->allergy($this->patient()->id, $f['ca'], 'synthetic otc medicine');

        $this->expectException(QueryException::class);
        DB::transaction(fn () => (new DispensaryAllergyConfirmation)->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $f['ca']->organisation_id, 'branch_id' => $f['visit']->branch_id,
            'dispensary_case_id' => $case->id, 'dispensary_handoff_id' => $item->dispensary_handoff_id, 'dispensary_item_id' => $item->id,
            'patient_allergy_record_id' => $stranger->id, 'allergy_profile_lock_version' => 1, 'action' => 'edit', 'item_lock_version' => 9,
            'confirmed_by_user_id' => $f['ca']->id, 'confirmed_at' => now()->utc(),
        ])->save());
    }

    public function test_every_matching_record_must_be_confirmed(): void
    {
        $f = $this->fixture();
        $first = $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic OTC medicine');
        $second = $this->allergy($f['visit']->patient_id, $f['ca'], 'Seafood, synthetic otc');
        $case = $this->open($f);

        $e = $this->refusedForAllergy(fn () => app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => [$first->public_id]])));
        $this->assertStringNotContainsStringIgnoringCase('seafood', $e->errors()['allergy_confirmation'][0], 'allergy text never goes into the flashed message');
        $this->assertStringNotContainsStringIgnoringCase('synthetic', $e->errors()['allergy_confirmation'][0]);

        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => [$first->public_id, $second->public_id]]));
        $this->assertSame([$first->id, $second->id], DispensaryAllergyConfirmation::query()->orderBy('id')->pluck('patient_allergy_record_id')->all());
        $this->assertSame(2, AuditLog::query()->where('event', 'dispensary.allergy_match_confirmed')->sole()->metadata['matched_records']);
    }

    public function test_an_otc_edit_matching_an_allergy_needs_the_confirmation_on_every_save(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $f['medicine']->forceFill(['generic_name' => 'Synthetic generic ingredient'])->save();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'synthetic GENERIC ingredient');

        $this->refusedForAllergy(fn () => app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $this->otcEditPayload($f, $case, $item)));
        $this->assertSame(1, $item->refresh()->lock_version, 'the line is unchanged');

        $edited = app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $this->otcEditPayload($f, $case, $item, ['allergy_confirmed_record_ids' => [$record->public_id]]));
        $confirmation = DispensaryAllergyConfirmation::query()->sole();
        $this->assertSame(DispensaryAllergyConfirmation::ACTION_EDIT, $confirmation->action);
        $this->assertSame($edited->lock_version, $confirmation->item_lock_version);

        $this->refusedForAllergy(fn () => app(DispensaryService::class)->editItem($f['ca'], $case->refresh(), $item->refresh(), $this->otcEditPayload($f, $case, $item)));
        $this->assertSame(1, DispensaryAllergyConfirmation::query()->count(), 'an earlier confirmation does not cover a later save');
    }

    public function test_the_page_and_the_medicine_search_show_possible_matches(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic OTC medicine');
        $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic OTC medicine', PatientAllergyRecord::STATUS_ENTERED_IN_ERROR);
        $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic seafood');
        $case = $this->open($f);
        $expected = [['publicId' => $record->public_id, 'allergen' => 'Synthetic OTC medicine']];

        $results = app(DispensaryDirectoryService::class)->searchMedicines($f['ca'], $case, 'Synthetic OTC');
        $this->assertSame($expected, $results[0]['allergyMatches']);

        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => [$record->public_id]]));
        $detail = app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh());
        $this->assertSame($expected, $detail['items'][0]['allergyMatches']);
        $this->assertCount(2, $detail['allergySafety']['allergies'], 'the full active allergy list stays visible');
    }

    public function test_the_routes_refuse_without_and_accept_with_the_confirmation(): void
    {
        $f = $this->fixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic OTC medicine');
        $case = $this->open($f);

        $this->from(route('dispensary.show', $case))->post("/dispensary/{$case->public_id}/items", $this->addPayload($f, $case))
            ->assertRedirect(route('dispensary.show', $case))->assertSessionHasErrors('allergy_confirmation');
        $this->post("/dispensary/{$case->public_id}/items", $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => ['not-a-uuid']]))
            ->assertSessionHasErrors('allergy_confirmed_record_ids.0');
        $this->assertSame(0, DispensaryItem::query()->count());

        $this->post("/dispensary/{$case->public_id}/items", $this->addPayload($f, $case, override: ['allergy_confirmed_record_ids' => [$record->public_id]]))
            ->assertRedirect(route('dispensary.show', $case))->assertSessionHasNoErrors();
        $item = DispensaryItem::query()->sole();

        $this->put("/dispensary/{$case->public_id}/items/{$item->public_id}/final", $this->otcEditPayload($f, $case, $item))->assertSessionHasErrors('allergy_confirmation');
        $this->put("/dispensary/{$case->public_id}/items/{$item->public_id}/final", $this->otcEditPayload($f, $case, $item, ['allergy_confirmed_record_ids' => [$record->public_id]]))->assertSessionHasNoErrors();
        $this->assertSame(['add', 'edit'], DispensaryAllergyConfirmation::query()->orderBy('id')->pluck('action')->all());

        $this->get(route('dispensary.show', $case))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Dispensary/Show')
            ->where('dispensary.items.0.allergyMatches.0.publicId', $record->public_id));
    }

    /** @return array<string, mixed> */
    private function consultationFixture(): array
    {
        [$doctor, $ca, $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $allergies = app(PatientAllergyService::class);
        $profile = $allergies->declareNoKnown($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => null]);
        $allergies->review($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => $profile->lock_version]);
        $medicine = $this->stockedMedicine($doctor, $ca, $visit->branch_id, 'Synthetic Brandcillin 250mg', 'Synthetic amoxicillin');
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null, 'services' => [], 'medicines' => [[
            'public_id' => null, 'catalogue_public_id' => $medicine['medicine']->public_id, 'quantity_ordered' => 1, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency',
            'duration' => null, 'route' => null, 'administration_instruction' => null, 'indication' => null, 'precaution' => null,
        ]]]);
        $case = app(DispensaryHandoffService::class)->send($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $plan->lock_version, 'service_deliveries' => []]);
        $this->selectBranch($ca, $visit->branch);
        $case = app(DispensaryService::class)->start($ca, $case, ['expected_branch_id' => $visit->branch_id, 'case_lock_version' => $case->lock_version]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();

        return ['doctor' => $doctor, 'ca' => $ca, 'visit' => $visit->refresh(), 'case' => $case, 'item' => $item, ...$medicine];
    }

    /** @return array{medicine: MedicineCatalogueItem, location: InventoryLocation, sku: InventorySku, batch: InventoryBatch} */
    private function stockedMedicine(User $doctor, User $ca, int $branchId, string $name, ?string $generic, ?InventoryLocation $location = null): array
    {
        $organisation = $ca->organisation_id;
        $medicine = new MedicineCatalogueItem;
        $medicine->forceFill([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'code' => 'SYN-MED-'.Str::upper(Str::random(8)), 'display_name' => $name, 'generic_name' => $generic,
            'strength_text' => 'Synthetic strength', 'dosage_form' => 'Synthetic form', 'order_unit' => 'unit', 'authorisation_class' => MedicineCatalogueItem::AUTHORISATION_DOCTOR_REQUIRED,
            'is_active' => true, 'created_by_user_id' => $doctor->id, 'updated_by_user_id' => $doctor->id,
        ])->save();
        $inventoryItem = new InventoryItem;
        $inventoryItem->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'code' => 'SYN-ITEM-'.Str::upper(Str::random(6)), 'generic_name' => 'Synthetic stock item', 'is_active' => true])->save();
        $sku = new InventorySku;
        $sku->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'inventory_item_id' => $inventoryItem->id, 'sku_code' => 'SYN-SKU-'.Str::upper(Str::random(6)), 'pack_size' => 1, 'purchase_unit' => 'unit', 'stock_unit' => 'unit', 'dispensing_unit' => 'unit', 'unit_conversion' => 1, 'storage_type' => 'ambient', 'cold_chain_required' => false, 'do_not_freeze' => false, 'protect_from_light' => false, 'batch_tracking_required' => true, 'expiry_tracking_required' => true, 'is_active' => true])->save();
        (new MedicineCatalogueInventorySku)->forceFill(['organisation_id' => $organisation, 'medicine_catalogue_item_id' => $medicine->id, 'inventory_sku_id' => $sku->id, 'is_active' => true, 'approved_by_user_id' => $doctor->id, 'approved_at' => now()->utc()])->save();
        if (! $location) {
            $location = new InventoryLocation;
            $location->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'branch_id' => $branchId, 'code' => 'SYN-DISP-'.Str::upper(Str::random(5)), 'name' => 'Synthetic Dispensary', 'type' => InventoryLocation::TYPE_DISPENSARY, 'is_active' => true])->save();
        }
        $branch = $location->branch()->firstOrFail();
        $batch = new InventoryBatch;
        $batch->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $organisation, 'inventory_sku_id' => $sku->id, 'batch_number' => 'SYN-BATCH-'.Str::upper(Str::random(5)), 'expiry_date' => now()->setTimezone($branch->timezone)->addMonth()->toDateString(), 'received_at' => now()->subDay()->toDateString(), 'status' => InventoryBatch::STATUS_AVAILABLE])->save();
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor, $branch);
        app(InventoryMovementService::class)->openingBalance($supervisor, ['expected_branch_id' => $branchId, 'location_public_id' => $location->public_id, 'sku_public_id' => $sku->public_id, 'batch_public_id' => $batch->public_id, 'quantity' => '10.000']);
        $this->selectBranch($ca, $branch);

        return compact('medicine', 'location', 'sku', 'batch');
    }

    /** @param array<string, mixed> $f @return array<string, mixed> */
    private function consultationEditPayload(array $f, array $override = []): array
    {
        $item = $f['item']->refresh();

        return [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'item_lock_version' => $item->lock_version,
            'quantity_dispensed' => '1.000', 'dosage' => $item->dosage, 'frequency' => $item->frequency,
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000']],
            ...$override,
        ];
    }

    public function test_a_consultation_edit_of_a_doctor_line_matching_by_generic_name_needs_the_confirmation(): void
    {
        $f = $this->consultationFixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic AMOXICILLIN');

        $this->refusedForAllergy(fn () => app(DispensaryService::class)->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $this->consultationEditPayload($f)));
        $this->assertSame(DispensaryItem::STATUS_PENDING, $f['item']->refresh()->status, 'the line is unchanged');

        $edited = app(DispensaryService::class)->editItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $this->consultationEditPayload($f, ['allergy_confirmed_record_ids' => [$record->public_id]]));

        $this->assertSame(DispensaryItem::STATUS_DISPENSED, $edited->status);
        $confirmation = DispensaryAllergyConfirmation::query()->sole();
        $this->assertSame([DispensaryAllergyConfirmation::ACTION_EDIT, $record->id, $f['ca']->id], [$confirmation->action, $confirmation->patient_allergy_record_id, $confirmation->confirmed_by_user_id]);
    }

    public function test_the_legacy_status_save_of_a_matching_doctor_line_needs_the_confirmation(): void
    {
        $f = $this->consultationFixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Allergic to amoxicillin');
        $payload = fn (array $override = []): array => [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'item_lock_version' => $f['item']->refresh()->lock_version,
            'status' => 'dispensed', 'quantity_dispensed' => '1.000', 'reason' => null,
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $f['sku']->public_id, 'batch_public_id' => $f['batch']->public_id, 'quantity' => '1.000']],
            ...$override,
        ];

        $this->refusedForAllergy(fn () => app(DispensaryService::class)->updateItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $payload()));
        $this->assertSame(DispensaryItem::STATUS_PENDING, $f['item']->refresh()->status);

        app(DispensaryService::class)->updateItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $payload(['allergy_confirmed_record_ids' => [$record->public_id]]));
        $this->assertSame(DispensaryItem::STATUS_DISPENSED, $f['item']->refresh()->status);
        $confirmation = DispensaryAllergyConfirmation::query()->sole();
        $this->assertSame([DispensaryAllergyConfirmation::ACTION_EDIT, $f['item']->lock_version], [$confirmation->action, $confirmation->item_lock_version]);

        app(DispensaryService::class)->updateItem($f['ca'], $f['case']->refresh(), $f['item']->refresh(), $payload(['status' => 'not_dispensed', 'quantity_dispensed' => '0.000', 'reason' => 'patient_declined', 'allocations' => []]));
        $this->assertSame(1, DispensaryAllergyConfirmation::query()->count(), 'a line that is not dispensed needs no confirmation');
    }

    public function test_a_consultation_add_matching_by_name_needs_the_confirmation_and_a_non_match_does_not(): void
    {
        $f = $this->consultationFixture();
        $record = $this->allergy($f['visit']->patient_id, $f['ca'], 'Synthetic ibuprofen');
        $match = $this->stockedMedicine($f['doctor'], $f['ca'], $f['visit']->branch_id, 'Synthetic Ibuprofen 200mg', null, $f['location']);
        $other = $this->stockedMedicine($f['doctor'], $f['ca'], $f['visit']->branch_id, 'Synthetic chlorphenamine 4mg', null, $f['location']);
        $payload = fn (array $stock, array $override = []): array => [
            'expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $f['case']->refresh()->lock_version, 'medicine_public_id' => $stock['medicine']->public_id,
            'quantity_dispensed' => '1.000', 'dosage' => 'One tablet', 'frequency' => 'Twice daily',
            'allocations' => [['location_public_id' => $f['location']->public_id, 'sku_public_id' => $stock['sku']->public_id, 'batch_public_id' => $stock['batch']->public_id, 'quantity' => '1.000']],
            ...$override,
        ];

        app(DispensaryService::class)->addItem($f['ca'], $f['case']->refresh(), $payload($other));
        $this->assertSame(0, DispensaryAllergyConfirmation::query()->count());

        $this->refusedForAllergy(fn () => app(DispensaryService::class)->addItem($f['ca'], $f['case']->refresh(), $payload($match)));
        $added = app(DispensaryService::class)->addItem($f['ca'], $f['case']->refresh(), $payload($match, ['allergy_confirmed_record_ids' => [$record->public_id]]));

        $confirmation = DispensaryAllergyConfirmation::query()->sole();
        $this->assertSame([DispensaryAllergyConfirmation::ACTION_ADD, $added->id], [$confirmation->action, $confirmation->dispensary_item_id]);
    }
}
