<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Services\ClinicalServiceCatalogueAdministrationService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ClinicalServiceCatalogueAdministrationServiceTest extends ClinicalTestCase
{
    public function test_permission_mapping_is_limited_to_director_and_ca_supervisor(): void
    {
        $roles = PermissionCatalogue::roles();
        $this->assertContains(ClinicalServiceCatalogueAdministrationService::PERMISSION, PermissionCatalogue::all());
        foreach (['director', 'ca_supervisor'] as $role) {
            $this->assertContains(ClinicalServiceCatalogueAdministrationService::PERMISSION, $roles[$role]);
        }
        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'business_development', 'marketing', 'hr_manager', 'technical_admin'] as $role) {
            $this->assertNotContains(ClinicalServiceCatalogueAdministrationService::PERMISSION, $roles[$role]);
        }

        // Same role set as medicines.manage.organisation, per the UI-1A authorization.
        foreach ($roles as $role => $permissions) {
            $this->assertSame(
                in_array('medicines.manage.organisation', $permissions, true),
                in_array(ClinicalServiceCatalogueAdministrationService::PERMISSION, $permissions, true),
                $role,
            );
        }
    }

    public function test_create_normalizes_values_attributes_actor_and_audits_safe_fields(): void
    {
        $director = $this->actor('director');
        $service = $this->service()->create($director, [
            'code' => '  cons01  ',
            'display_name' => '  Synthetic   Consultation Service  ',
            'order_unit' => ' session ',
        ]);

        $this->assertSame('CONS01', $service->code);
        $this->assertSame('Synthetic Consultation Service', $service->display_name);
        $this->assertSame('session', $service->order_unit);
        $this->assertTrue($service->is_active);
        $this->assertSame($director->id, $service->created_by_user_id);
        $this->assertSame($director->id, $service->updated_by_user_id);

        $audit = AuditLog::query()->where('event', 'clinical_service_catalogue.created')->sole();
        $this->assertSame($director->id, $audit->actor_user_id);
        $this->assertSame($director->organisation_id, $audit->organisation_id);
        $this->assertSame($service->id, $audit->subject_id);
        $this->assertEqualsCanonicalizing(
            ['code', 'display_name', 'order_unit', 'is_active'],
            $audit->metadata['changed_fields'],
        );
    }

    public function test_case_insensitive_duplicates_are_rejected_per_organisation_and_foreign_mutation_is_neutral(): void
    {
        $director = $this->actor('director');
        $this->service()->create($director, $this->attributes(['code' => 'SVC500']));

        try {
            $this->service()->create($director, $this->attributes(['code' => '  svc500 ']));
            $this->fail('Case-insensitive duplicate code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        [, $foreignService] = $this->foreignService('svc500');
        $this->assertSame('SVC500', $foreignService->code);

        $this->expectException(ModelNotFoundException::class);
        $this->service()->update($director, $foreignService, ['display_name' => 'Cross tenant mutation']);
    }

    public function test_unauthorised_and_inactive_users_cannot_manage_clinical_services(): void
    {
        foreach (['resident_doctor', 'ca', 'panel_officer', 'finance_officer', 'technical_admin'] as $role) {
            try {
                $this->service()->create($this->actor($role), $this->attributes(['code' => 'DENY-'.$role]));
                $this->fail("{$role} managed the Clinical Service Catalogue.");
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('clinical_service_catalogue_items', ['code' => Str::upper('DENY-'.$role)]);
            }
        }

        $director = $this->actor('director');
        $director->forceFill(['is_active' => false])->save();
        $this->expectException(AuthorizationException::class);
        $this->service()->create($director->refresh(), $this->attributes(['code' => 'INACTIVE']));
    }

    public function test_unused_identity_can_change_but_pricing_dependency_locks_code_and_order_unit(): void
    {
        $director = $this->actor('director');
        $service = $this->service()->create($director, $this->attributes(['code' => 'OLD-SVC', 'order_unit' => 'session']));
        $service = $this->service()->update($director, $service, ['code' => ' corrected-svc ', 'order_unit' => 'procedure']);
        $this->assertSame('CORRECTED-SVC', $service->code);
        $this->assertSame('procedure', $service->order_unit);

        $charge = new ChargeDefinition;
        $charge->forceFill([
            'public_id' => (string) Str::uuid(),
            'organisation_id' => $service->organisation_id,
            'code' => 'SVC-CORRECTED',
            'type' => 'service',
            'display_name' => $service->display_name,
            'unit' => $service->order_unit,
            'medicine_catalogue_item_id' => null,
            'clinical_service_catalogue_item_id' => $service->id,
            'source_key' => 'service:'.$service->id,
            'is_active' => true,
        ])->save();

        foreach (['code' => 'NEW-CODE', 'order_unit' => 'box'] as $field => $value) {
            try {
                $this->service()->update($director, $service, [$field => $value]);
                $this->fail("Established {$field} was changed.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertSame('CORRECTED-SVC', $service->refresh()->code);
        $this->assertSame('procedure', $service->order_unit);

        $service = $this->service()->update($director, $service, ['display_name' => 'Synthetic updated service']);
        $this->assertSame('Synthetic updated service', $service->display_name);
    }

    public function test_lifecycle_controls_are_audited_and_hard_delete_is_blocked(): void
    {
        $director = $this->actor('director');
        $service = $this->service()->create($director, $this->attributes(['code' => 'LIFECYCLE-SVC']));

        $service = $this->service()->deactivate($director, $service);
        $this->assertFalse($service->is_active);
        $service = $this->service()->activate($director, $service);
        $this->assertTrue($service->is_active);
        $this->assertSame($director->id, $service->refresh()->updated_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'clinical_service_catalogue.deactivated', 'subject_id' => $service->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'clinical_service_catalogue.activated', 'subject_id' => $service->id]);

        // Idempotent no-op: re-activating an already active row does not audit again.
        $countBefore = AuditLog::query()->where('event', 'clinical_service_catalogue.activated')->count();
        $this->service()->activate($director, $service);
        $this->assertSame($countBefore, AuditLog::query()->where('event', 'clinical_service_catalogue.activated')->count());

        try {
            $service->delete();
            $this->fail('Clinical Service Catalogue hard delete was allowed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('clinical_service_catalogue_items', ['id' => $service->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function attributes(array $overrides = []): array
    {
        return [
            'code' => 'SVC-'.Str::upper(Str::random(8)),
            'display_name' => 'Synthetic governed clinical service',
            'order_unit' => 'session',
            ...$overrides,
        ];
    }

    /** @return array{User, ClinicalServiceCatalogueItem} */
    private function foreignService(string $code): array
    {
        $organisation = new Organisation;
        $organisation->forceFill([
            'code' => 'FOREIGN-'.Str::upper(Str::random(6)),
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
        $director = $this->actor('director', $branch);

        return [$director, $this->service()->create($director, $this->attributes(['code' => $code]))];
    }

    private function service(): ClinicalServiceCatalogueAdministrationService
    {
        return app(ClinicalServiceCatalogueAdministrationService::class);
    }
}
