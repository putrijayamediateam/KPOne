<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Inventory\Models\InventorySupplier;
use App\Domain\Organisation\Inventory\Services\SupplierAdministrationService;
use App\Domain\Organisation\Inventory\Services\SupplierSettingsService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Tests\Feature\Visit\VisitTestCase;

class SupplierSettingsTest extends VisitTestCase
{
    /** @return array<string, mixed> */
    private function payload(): array
    {
        $phone = PhoneNumberUtil::getInstance();

        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'name' => 'Synthetic Supplier Company', 'contacts' => [
                ['name' => 'Synthetic Contact One', 'phone' => $phone->format($phone->getExampleNumber('MY'), PhoneNumberFormat::E164), 'country' => 'MY'],
                ['name' => 'Synthetic Contact Two', 'phone' => $phone->format($phone->getExampleNumber('GB'), PhoneNumberFormat::E164), 'country' => 'GB'],
            ]];
    }

    public function test_agreed_roles_reach_settings_but_other_inventory_roles_do_not(): void
    {
        foreach (['director', 'administrator', 'finance_officer', 'panel_officer'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('supplier-settings.index'))->assertOk()
                ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
                ->assertInertia(fn (Assert $p) => $p->component('InventoryServicesSettings/Suppliers')->where('workspace.navigation.catalogueSettings', true));
        }
        foreach (['technical_admin', 'ca_supervisor', 'ca', 'resident_doctor', 'hr_manager'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('supplier-settings.index'))->assertForbidden();
            $this->post(route('supplier-settings.store'), $this->payload())->assertForbidden();
        }
        $this->assertDatabaseCount('inventory_suppliers', 0);
    }

    public function test_create_keeps_primary_contact_compatible_adds_extra_contacts_and_audits_no_contact_content(): void
    {
        $officer = $this->actor('panel_officer');
        $this->selectBranch($officer);
        $payload = $this->payload();
        $this->post(route('supplier-settings.store'), $payload)->assertRedirect(route('supplier-settings.index'));
        $supplier = InventorySupplier::query()->sole();
        $this->assertSame($officer->organisation_id, $supplier->organisation_id);
        $this->assertStringStartsWith('SUP-', $supplier->code);
        $this->assertSame('Synthetic Contact One', $supplier->contact_name);
        $this->assertSame($payload['contacts'][0]['phone'], $supplier->business_phone);
        $this->assertSame(1, $supplier->lock_version);
        $this->assertDatabaseHas('inventory_supplier_contacts', ['inventory_supplier_id' => $supplier->id, 'position' => 2, 'name' => 'Synthetic Contact Two']);
        $audit = json_decode(DB::table('audit_logs')->where('event', 'inventory.supplier.created')->sole()->metadata, true);
        $this->assertSame(['code' => $supplier->code, 'is_active' => true, 'contact_count' => 2], $audit);
        $this->get(route('supplier-settings.index'))->assertInertia(fn (Assert $p) => $p->has('suppliers', 1)->has('suppliers.0.contacts', 2)
            ->where('suppliers.0.contacts.1.name', 'Synthetic Contact Two')->where('suppliers.0.active', true)->missing('patients'));
        $this->post(route('inventory.suppliers.store'), ['code' => 'SYNTH', 'name' => 'Synthetic'])->assertForbidden();
        $this->patch(route('inventory.suppliers.status', $supplier), ['is_active' => false])->assertForbidden();
        $this->assertTrue($supplier->refresh()->is_active);
    }

    public function test_create_retry_is_idempotent_and_a_changed_payload_is_refused(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $payload = $this->payload();
        $this->post(route('supplier-settings.store'), $payload)->assertRedirect(route('supplier-settings.index'));
        $this->post(route('supplier-settings.store'), $payload)->assertRedirect(route('supplier-settings.index'));
        $this->assertDatabaseCount('inventory_suppliers', 1);
        $this->assertDatabaseCount('inventory_supplier_contacts', 1);
        $this->assertDatabaseCount('inventory_supplier_settings_commands', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.supplier.created')->count());
        $payload['contacts'][1]['name'] = 'Synthetic Changed Contact';
        $this->post(route('supplier-settings.store'), $payload)->assertRedirect(route('supplier-settings.index'))->assertSessionHasErrors('supplier');
        $this->assertDatabaseCount('inventory_suppliers', 1);
    }

    public function test_status_requires_current_version_is_audited_retry_safe_and_restorable(): void
    {
        $this->selectBranch($this->actor('panel_officer'));
        $this->post(route('supplier-settings.store'), $this->payload())->assertRedirect();
        $supplier = InventorySupplier::query()->sole();
        $data = ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'active' => false, 'lock_version' => 1];
        $this->patch(route('supplier-settings.active', $supplier), $data)->assertRedirect(route('supplier-settings.index'));
        $this->patch(route('supplier-settings.active', $supplier), $data)->assertRedirect(route('supplier-settings.index'));
        $this->assertFalse($supplier->refresh()->is_active);
        $this->assertSame(2, $supplier->lock_version);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'inventory.supplier.deactivated')->count());
        $stale = array_replace($data, ['idempotency_key' => (string) Str::uuid(), 'active' => true]);
        $this->patch(route('supplier-settings.active', $supplier), $stale)->assertRedirect(route('supplier-settings.index'))->assertSessionHasErrors('supplier');
        $this->patch(route('supplier-settings.active', $supplier), array_replace($data, ['active' => true]))->assertSessionHasErrors('supplier');
        $this->patch(route('supplier-settings.active', $supplier), array_replace($stale, ['lock_version' => 2]))->assertRedirect();
        $this->assertTrue($supplier->refresh()->is_active);
        $this->assertSame(3, $supplier->lock_version);
        $this->assertDatabaseCount('inventory_supplier_contacts', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.supplier.activated', 'subject_id' => $supplier->id]);
    }

    public function test_supplier_records_are_organisation_scoped_and_legacy_mutations_invalidate_settings_versions(): void
    {
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $service = app(SupplierAdministrationService::class);
        $supplier = $service->create($finance, ['code' => 'SYNTH-LEGACY', 'name' => 'Synthetic Legacy Supplier', 'contact_name' => 'Synthetic Original Contact']);
        $this->get(route('supplier-settings.index'))->assertInertia(fn (Assert $p) => $p->where('suppliers.0.contacts.0.name', 'Synthetic Original Contact'));
        $service->update($finance, $supplier, ['contact_name' => 'Synthetic Updated Contact']);
        $this->assertSame(2, $supplier->refresh()->lock_version);
        $this->patch(route('supplier-settings.active', $supplier), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 1, 'active' => false])->assertSessionHasErrors('supplier');
        $service->setActive($finance, $supplier, false);
        $this->assertSame(3, $supplier->refresh()->lock_version);
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYNTH-OTHER', 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $department = new Department;
        $department->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH-DEPT', 'name' => 'Synthetic Department', 'is_active' => true])->save();
        $other = new Branch;
        $other->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH-OTHER', 'name' => 'Synthetic Other Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $outsider = $this->actor('administrator', $other);
        $this->selectBranch($outsider, $other);
        $this->get(route('supplier-settings.index'))->assertInertia(fn (Assert $p) => $p->has('suppliers', 0));
        $this->patch(route('supplier-settings.active', $supplier), ['expected_branch_id' => $other->id, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 3, 'active' => true])->assertNotFound();
        $this->assertFalse($supplier->refresh()->is_active);
    }

    public function test_search_finds_additional_contacts_is_literal_and_paginated(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $payload = $this->payload();
        $payload['name'] = 'Synthetic 10% Supplier';
        $this->post(route('supplier-settings.store'), $payload)->assertRedirect();
        foreach (range(1, 26) as $n) {
            app(SupplierAdministrationService::class)->create(auth()->user(), ['code' => 'SYNTH-'.$n, 'name' => 'Synthetic Other '.$n]);
        }
        $this->get(route('supplier-settings.index'))->assertInertia(fn (Assert $p) => $p->has('suppliers', 25)->where('pagination.total', 27)->where('pagination.lastPage', 2));
        foreach (['Contact Two', '10%'] as $search) {
            $this->get(route('supplier-settings.index', ['q' => $search]))->assertInertia(fn (Assert $p) => $p->has('suppliers', 1)->where('suppliers.0.name', 'Synthetic 10% Supplier'));
        }
    }

    public function test_validation_and_branch_drift_refuse_creation_without_partial_records(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $payload = $this->payload();
        $invalid = [
            ['name' => ' '], ['name' => str_repeat('x', 201)], ['contacts' => []],
            ['contacts' => array_fill(0, 21, ['name' => '', 'phone' => '', 'country' => 'MY'])],
            ['contacts' => [['name' => 'Synthetic Contact', 'phone' => 'not a phone', 'country' => 'MY']]],
            ['code' => 'INJECTED'], ['organisation_id' => 999], ['is_active' => false],
            ['idempotency_key' => 'bad'], ['expected_branch_id' => 999],
        ];
        foreach ($invalid as $change) {
            $this->post(route('supplier-settings.store'), array_replace($payload, $change))->assertRedirect(route('supplier-settings.index'))->assertSessionHasErrors();
            $this->assertDatabaseCount('inventory_suppliers', 0);
            $this->assertDatabaseCount('inventory_supplier_contacts', 0);
            $this->assertDatabaseCount('inventory_supplier_settings_commands', 0);
        }
        StaffBranchAssignment::query()->where('staff_profile_id', $actor->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->expectException(AuthorizationException::class);
        app(SupplierSettingsService::class)->create($actor, $payload);
    }

    public function test_settings_migration_retains_saved_contacts_and_retry_evidence_on_rollback(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('supplier-settings.store'), $this->payload())->assertRedirect();
        $migration = require database_path('migrations/2026_10_14_000400_extend_suppliers_for_settings.php');
        try {
            $migration->down();
            $this->fail('Retained supplier settings evidence was removed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Supplier settings evidence must be retained.', $e->getMessage());
        }
        $this->assertDatabaseCount('inventory_supplier_contacts', 1);
        $this->assertDatabaseCount('inventory_supplier_settings_commands', 1);
        $this->assertSame(1, InventorySupplier::query()->sole()->lock_version);
    }

    public function test_company_only_creation_skips_empty_contact_rows_and_suppliers_remain_organisation_wide(): void
    {
        $actor = $this->actor('administrator');
        $this->selectBranch($actor);
        $data = array_replace($this->payload(), ['contacts' => [['name' => '', 'phone' => '', 'country' => 'MY']]]);
        $this->post(route('supplier-settings.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('inventory_supplier_contacts', 0);
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $this->selectBranch($this->actor('panel_officer', $other), $other);
        $this->get(route('supplier-settings.index'))->assertInertia(fn (Assert $p) => $p->has('suppliers', 1)->has('suppliers.0.contacts', 0));
    }
}
