<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PriceResolutionService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Clinical\ClinicalTestCase;

class PricingReferenceAdministrationServiceTest extends ClinicalTestCase
{
    public function test_permission_mapping_is_limited_to_director_and_finance_officer(): void
    {
        $roles = PermissionCatalogue::roles();
        $this->assertContains(PricingReferenceAdministrationService::PERMISSION, PermissionCatalogue::all());
        foreach (['director', 'finance_officer'] as $role) {
            $this->assertContains(PricingReferenceAdministrationService::PERMISSION, $roles[$role]);
        }
        foreach (['ca_supervisor', 'ca', 'resident_doctor', 'panel_officer', 'business_development', 'marketing', 'hr_manager', 'technical_admin'] as $role) {
            $this->assertNotContains(PricingReferenceAdministrationService::PERMISSION, $roles[$role]);
        }

        foreach (['ca_supervisor', 'ca', 'resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            try {
                $this->service()->createConsultationCharge($this->actor($role), [
                    'code' => 'DENY-'.$role,
                    'display_name' => 'Synthetic denied consultation',
                ]);
                $this->fail("{$role} managed pricing references.");
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('charge_definitions', ['code' => 'DENY-'.strtoupper($role)]);
            }
        }
    }

    public function test_charge_creation_derives_exact_medicine_service_and_consultation_identity(): void
    {
        $director = $this->actor('director');
        $medicine = $this->medicine($director, 'UAT-PARA500', 'tablet');
        $serviceSource = ClinicalServiceCatalogueItem::factory()->create([
            'organisation_id' => $director->organisation_id,
            'code' => 'UAT-SERVICE-SOURCE',
            'display_name' => 'Synthetic service source',
            'order_unit' => 'session',
            'created_by_user_id' => $director->id,
            'updated_by_user_id' => $director->id,
            'is_active' => true,
        ]);

        $medicineCharge = $this->service()->createMedicineCharge($director, $medicine, [
            'code' => ' med-uat-para500 ',
            'display_name' => ' Synthetic   Paracetamol charge ',
        ]);
        $serviceCharge = $this->service()->createServiceCharge($director, $serviceSource, [
            'code' => 'SVC-UAT',
            'display_name' => 'Synthetic service charge',
        ]);
        $consultationCharge = $this->service()->createConsultationCharge($director, [
            'code' => 'CONSULT-UAT',
            'display_name' => 'Synthetic consultation',
        ]);

        $this->assertSame('MED-UAT-PARA500', $medicineCharge->code);
        $this->assertSame('Synthetic Paracetamol charge', $medicineCharge->display_name);
        $this->assertSame('medicine:'.$medicine->id, $medicineCharge->source_key);
        $this->assertSame($medicine->id, $medicineCharge->medicine_catalogue_item_id);
        $this->assertNull($medicineCharge->clinical_service_catalogue_item_id);
        $this->assertSame('tablet', $medicineCharge->unit);
        $this->assertSame('service:'.$serviceSource->id, $serviceCharge->source_key);
        $this->assertSame('session', $serviceCharge->unit);
        $this->assertSame('consultation', $consultationCharge->source_key);
        $this->assertSame('consultation', $consultationCharge->unit);
        $this->assertTrue($medicineCharge->is_active);

        $medicineCharge = $this->service()->deactivateCharge($director, $medicineCharge);
        $this->assertFalse($medicineCharge->is_active);
        $medicineCharge = $this->service()->updateCharge($director, $medicineCharge, [
            'display_name' => 'Synthetic updated Paracetamol charge',
        ]);
        $medicineCharge = $this->service()->activateCharge($director, $medicineCharge);
        $this->assertTrue($medicineCharge->is_active);
        $this->assertSame('Synthetic updated Paracetamol charge', $medicineCharge->display_name);

        $audit = AuditLog::query()->where('event', 'charge_definition.created')->where('subject_id', $medicineCharge->id)->sole();
        $this->assertSame($director->id, $audit->actor_user_id);
        $this->assertSame($director->organisation_id, $audit->organisation_id);
        $this->assertSame($medicineCharge->public_id, $audit->metadata['reference_public_id']);
        $this->assertSame($medicine->public_id, $audit->metadata['source_public_id']);
        $this->assertArrayNotHasKey('model', $audit->metadata);
        $this->assertDatabaseHas('audit_logs', ['event' => 'charge_definition.updated', 'subject_id' => $medicineCharge->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'charge_definition.deactivated', 'subject_id' => $medicineCharge->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'charge_definition.activated', 'subject_id' => $medicineCharge->id]);

        try {
            $medicineCharge->delete();
            $this->fail('Charge Definition hard deletion was allowed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('charge_definitions', ['id' => $medicineCharge->id]);
        }
    }

    public function test_charge_duplicates_tenancy_and_immutable_identity_are_governed(): void
    {
        $director = $this->actor('director');
        $medicine = $this->medicine($director, 'DUP-MED', 'tablet');
        $charge = $this->service()->createMedicineCharge($director, $medicine, [
            'code' => 'DUP-CHARGE',
            'display_name' => 'Synthetic charge',
        ]);

        try {
            $this->service()->createMedicineCharge($director, $medicine, [
                'code' => 'OTHER-CODE',
                'display_name' => 'Synthetic duplicate source',
            ]);
            $this->fail('Duplicate source identity was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('source', $exception->errors());
        }

        $otherMedicine = $this->medicine($director, 'OTHER-MED', 'tablet');
        try {
            $this->service()->createMedicineCharge($director, $otherMedicine, [
                'code' => ' dup-charge ',
                'display_name' => 'Synthetic duplicate code',
            ]);
            $this->fail('Case-insensitive duplicate charge code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        try {
            $this->service()->updateCharge($director, $charge, ['unit' => 'box']);
            $this->fail('Charge unit was administratively editable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }
        $this->assertSame('tablet', $charge->refresh()->unit);

        $foreignBranch = $this->foreignBranch();
        $foreignDirector = $this->actor('director', $foreignBranch);
        $foreignMedicine = $this->medicine($foreignDirector, 'FOREIGN-MED', 'tablet');
        $this->expectException(ModelNotFoundException::class);
        $this->service()->createMedicineCharge($director, $foreignMedicine, [
            'code' => 'FOREIGN-CHARGE',
            'display_name' => 'Foreign charge',
        ]);
    }

    public function test_price_book_scope_is_server_derived_unique_tenant_safe_and_retained(): void
    {
        $finance = $this->actor('finance_officer');
        $book = $this->service()->createPriceBook($finance, null, [
            'name' => ' Synthetic   organisation prices ',
            'currency' => 'myr',
        ]);

        $this->assertNull($book->branch_id);
        $this->assertSame('organisation', $book->scope_key);
        $this->assertSame('Synthetic organisation prices', $book->name);
        $this->assertSame('MYR', $book->currency);
        $this->assertTrue($book->is_active);

        $this->service()->deactivatePriceBook($finance, $book);
        try {
            $this->service()->createPriceBook($finance, null, ['name' => 'Ambiguous replacement']);
            $this->fail('A second retained organisation Price Book was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('scope', $exception->errors());
        }
        $book = $this->service()->activatePriceBook($finance, $book);
        $book = $this->service()->updatePriceBook($finance, $book, ['name' => 'Updated synthetic prices']);
        $this->assertSame('Updated synthetic prices', $book->name);

        $branchBook = $this->service()->createPriceBook($finance, $this->branch, [
            'name' => 'Synthetic Cheras prices',
        ]);
        $this->assertSame($this->branch->id, $branchBook->branch_id);
        $this->assertSame('branch:'.$this->branch->id, $branchBook->scope_key);

        $foreignBranch = $this->foreignBranch();
        try {
            $this->service()->createPriceBook($finance, $foreignBranch, ['name' => 'Foreign branch prices']);
            $this->fail('A foreign branch Price Book was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseMissing('price_books', ['name' => 'Foreign branch prices']);
        }

        $audit = AuditLog::query()->where('event', 'price_book.created')->where('subject_id', $book->id)->sole();
        $this->assertSame($finance->id, $audit->actor_user_id);
        $this->assertSame('organisation', $audit->metadata['scope']);
        $this->assertSame('MYR', $audit->metadata['currency']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'price_book.deactivated', 'subject_id' => $book->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'price_book.activated', 'subject_id' => $book->id]);

        try {
            $book->delete();
            $this->fail('Price Book hard deletion was allowed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('price_books', ['id' => $book->id]);
        }
    }

    public function test_existing_publication_and_resolver_produce_fifty_sen_tablets_and_version_history(): void
    {
        $director = $this->actor('director');
        $finance = $this->actor('finance_officer');
        $medicine = $this->medicine($director, 'UAT-FRI-PARA500', 'tablet');
        $charge = $this->service()->createMedicineCharge($finance, $medicine, [
            'code' => 'UAT-FRI-PARA500',
            'display_name' => 'UAT Paracetamol 500 mg Tablet',
        ]);
        $book = $this->service()->createPriceBook($finance, null, [
            'name' => 'Synthetic Friday UAT prices',
        ]);
        $this->selectBranch($finance);

        $publication = app(PricePublicationService::class);
        $first = $publication->publish($finance, $book, $charge, 50, 0, $this->branch->id);
        $second = $publication->publish($finance, $book, $charge, 75, 1, $this->branch->id);
        $this->assertSame(1, $first->version);
        $this->assertSame(50, $first->unit_price_sen);
        $this->assertSame(2, $second->version);
        $this->assertSame(75, $second->unit_price_sen);
        $this->assertSame(50, $first->refresh()->unit_price_sen);

        $visit = new Visit;
        $visit->forceFill([
            'organisation_id' => $director->organisation_id,
            'branch_id' => $this->branch->id,
        ]);
        $resolved = DB::transaction(fn (): array => app(PriceResolutionService::class)->price($visit, [[
            'charge_key' => 'medicine:'.$medicine->id,
            'unit_snapshot' => 'tablet',
            'quantity' => '10.000',
        ]]));

        $this->assertSame($charge->id, $resolved[0]['charge_definition_id']);
        $this->assertSame($second->id, $resolved[0]['price_entry_id']);
        $this->assertSame(2, $resolved[0]['price_version']);
        $this->assertSame(75, $resolved[0]['unit_price_sen']);
        $this->assertSame(750, $resolved[0]['line_total_sen']);

        $singleVersionBook = $this->service()->createPriceBook($finance, $this->branch, [
            'name' => 'Synthetic Friday exact price',
        ]);
        $exact = $publication->publish($finance, $singleVersionBook, $charge, 50, 0, $this->branch->id);
        $resolved = DB::transaction(fn (): array => app(PriceResolutionService::class)->price($visit, [[
            'charge_key' => 'medicine:'.$medicine->id,
            'unit_snapshot' => 'tablet',
            'quantity' => '10.000',
        ]]));
        $this->assertSame($exact->id, $resolved[0]['price_entry_id']);
        $this->assertSame(50, $resolved[0]['unit_price_sen']);
        $this->assertSame(500, $resolved[0]['line_total_sen']);
        $this->assertSame('tablet', $charge->unit);
        $this->assertFalse(Schema::hasColumn('medicine_catalogue_items', 'price'));

        try {
            $first->forceFill(['unit_price_sen' => 1])->save();
            $this->fail('Historical PriceEntry was mutable.');
        } catch (LogicException) {
            $this->assertSame(50, $first->refresh()->unit_price_sen);
        }
    }

    private function medicine(User $actor, string $code, string $unit): MedicineCatalogueItem
    {
        return app(MedicineAdministrationService::class)->create($actor, [
            'code' => $code,
            'display_name' => 'Synthetic '.$code,
            'strength_text' => null,
            'dosage_form' => null,
            'order_unit' => $unit,
        ]);
    }

    private function foreignBranch(): Branch
    {
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'FOREIGN-'.strtoupper(fake()->unique()->lexify('??????')),
            'name' => 'Synthetic Foreign Organisation',
            'is_active' => true,
        ])->save();
        $branch = new Branch;
        $branch->forceFill([
            'organisation_id' => $organisation->id,
            'code' => 'FOREIGN',
            'name' => 'Synthetic Foreign Branch',
            'timezone' => 'Asia/Kuala_Lumpur',
            'is_active' => true,
        ])->save();

        return $branch;
    }

    private function service(): PricingReferenceAdministrationService
    {
        return app(PricingReferenceAdministrationService::class);
    }
}
