<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Clinical\Models\CatalogueOption;
use App\Domain\Clinical\Services\CatalogueOptionService;
use App\Domain\Clinical\Services\MedicineAdministrationService;
use App\Domain\Clinical\Services\MedicineOptionSettingsService;
use App\Domain\Clinical\Services\PatientAllergyService;
use App\Domain\Clinical\Services\TreatmentPlanService;
use App\Domain\Identity\Models\StaffBranchAssignment;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\Organisation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

class MedicineOptionSettingsTest extends ClinicalTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    public function test_settings_roles_and_seven_types_are_explicit_and_no_patient_content_is_returned(): void
    {
        foreach (['director', 'administrator', 'finance_officer', 'panel_officer'] as $role) {
            $this->selectBranch($this->actor($role));
            foreach (MedicineOptionSettingsService::TABS as $type => $label) {
                $this->get(route('medicine-details.index', ['type' => $type]))->assertOk()
                    ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
                    ->assertInertia(fn (Assert $p) => $p->component('InventoryServicesSettings/MedicineDetails')->where('type', $type)->where('tabs.'.$type, $label)->missing('patients'));
            }
        }
        foreach (['technical_admin', 'ca', 'ca_supervisor', 'resident_doctor', 'hr_manager'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('medicine-details.index'))->assertForbidden();
            $this->post(route('medicine-details.store', 'precaution'), $this->payload(['labels' => ['Synthetic']]))->assertForbidden();
            $this->patch(route('medicine-details.rename', ['instruction', 1]), $this->payload(['label' => 'Synthetic', 'lock_version' => 1]))->assertForbidden();
            $this->patch(route('medicine-details.active', ['instruction', 1]), $this->payload(['active' => false, 'lock_version' => 1]))->assertForbidden();
        }
        $this->selectBranch($this->actor('administrator'));
        $this->get(route('medicine-details.index', ['type' => 'service_category']))->assertNotFound();
        $this->post(route('medicine-details.store', 'service_category'), $this->payload(['labels' => ['Synthetic']]))->assertNotFound();
    }

    public function test_batch_creation_is_atomic_duplicate_refusing_and_retry_safe(): void
    {
        $this->selectBranch($this->actor('panel_officer'));
        $data = $this->payload(['labels' => [' Synthetic first ', 'Synthetic second']]);
        $this->post(route('medicine-details.store', 'precaution'), $data)->assertRedirect(route('medicine-details.index', ['type' => 'precaution']));
        $this->post(route('medicine-details.store', 'precaution'), $data)->assertRedirect();
        $this->assertDatabaseCount('catalogue_options', 2);
        $this->assertDatabaseCount('medicine_option_settings_commands', 1);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'catalogue_option.created')->count());
        $this->post(route('medicine-details.store', 'precaution'), array_replace($data, ['labels' => ['Changed']]))->assertSessionHasErrors('option');
        $this->post(route('medicine-details.store', 'precaution'), $this->payload(['labels' => ['Synthetic third', ' SYNTHETIC FIRST ']]))->assertSessionHasErrors('labels.1');
        $this->assertDatabaseCount('catalogue_options', 2);
        $this->post(route('medicine-details.store', 'indication'), $this->payload(['labels' => ['Synthetic first', 'Synthetic second']]))->assertRedirect();
        $this->assertDatabaseCount('catalogue_options', 4);
        $audit = DB::table('audit_logs')->where('event', 'catalogue_option.created')->orderBy('id')->pluck('metadata')
            ->map(fn (string $metadata): array => json_decode($metadata, true, flags: JSON_THROW_ON_ERROR))->all();
        $this->assertSame([
            ['option_type' => 'precaution', 'lock_version' => 1],
            ['option_type' => 'precaution', 'lock_version' => 1],
            ['option_type' => 'indication', 'lock_version' => 1],
            ['option_type' => 'indication', 'lock_version' => 1],
        ], $audit);
    }

    public function test_duplicates_inside_batch_and_invalid_fields_leave_no_records(): void
    {
        $this->selectBranch($this->actor('administrator'));
        foreach ([['labels' => ['One', ' one ']], ['labels' => ['']], ['labels' => ['   ']], ['labels' => []], ['labels' => ['Valid', str_repeat('x', 201)]], ['labels' => array_fill(0, 21, 'Synthetic')], ['labels' => ['Valid'], 'idempotency_key' => 'bad'], ['labels' => ['Valid'], 'expected_branch_id' => 999]] as $change) {
            $this->post(route('medicine-details.store', 'indication'), $this->payload($change))->assertRedirect(route('medicine-details.index', ['type' => 'indication']))->assertSessionHasErrors();
            $this->assertDatabaseCount('catalogue_options', 0);
            $this->assertDatabaseCount('medicine_option_settings_commands', 0);
        }
        $this->post(route('medicine-details.store', 'frequency'), $this->payload(['labels' => ['One', 'Two']]))->assertSessionHasErrors('labels');
    }

    public function test_rename_status_and_retry_use_versions_and_archived_names_remain_reserved(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('medicine-details.store', 'instruction'), $this->payload(['labels' => ['Synthetic original']]))->assertRedirect();
        $option = CatalogueOption::query()->sole();
        $rename = $this->payload(['label' => ' Synthetic renamed ', 'lock_version' => 1]);
        $this->patch(route('medicine-details.rename', ['instruction', $option->id]), $rename)->assertRedirect();
        $this->patch(route('medicine-details.rename', ['instruction', $option->id]), $rename)->assertRedirect();
        $this->assertSame('Synthetic renamed', $option->refresh()->label);
        $this->assertSame('synthetic renamed', $option->normalized_label);
        $this->assertSame(2, $option->lock_version);
        $this->patch(route('medicine-details.rename', ['instruction', $option->id]), array_replace($rename, ['label' => 'Changed']))->assertSessionHasErrors('option');
        $this->patch(route('medicine-details.rename', ['instruction', $option->id]), $this->payload(['label' => 'Stale', 'lock_version' => 1]))->assertSessionHasErrors('option');
        $archive = $this->payload(['active' => false, 'lock_version' => 2]);
        $this->patch(route('medicine-details.active', ['instruction', $option->id]), $archive)->assertRedirect();
        $this->patch(route('medicine-details.active', ['instruction', $option->id]), $archive)->assertRedirect();
        $this->assertFalse($option->refresh()->is_active);
        $this->assertSame(3, $option->lock_version);
        $this->post(route('medicine-details.store', 'instruction'), $this->payload(['labels' => [' SYNTHETIC RENAMED ']]))->assertSessionHasErrors('labels.0');
        $this->patch(route('medicine-details.active', ['instruction', $option->id]), $this->payload(['active' => true, 'lock_version' => 3]))->assertRedirect();
        $this->assertTrue($option->refresh()->is_active);
        $this->assertSame(4, $option->lock_version);
        foreach (['renamed', 'archived', 'restored'] as $event) {
            $this->assertSame(1, DB::table('audit_logs')->where('event', 'catalogue_option.'.$event)->count());
        }
        $this->assertDatabaseCount('catalogue_options', 1);
        $this->delete(route('medicine-details.active', ['instruction', $option->id]))->assertStatus(405);
    }

    public function test_rename_refuses_an_existing_name_and_cross_tab_target(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $service = app(CatalogueOptionService::class);
        $one = $service->create($director, 'frequency', 'Synthetic one');
        $service->create($director, 'frequency', 'Synthetic two');
        $this->patch(route('medicine-details.rename', ['frequency', $one->id]), $this->payload(['label' => ' SYNTHETIC TWO ', 'lock_version' => 1]))->assertSessionHasErrors('label');
        $this->patch(route('medicine-details.rename', ['duration', $one->id]), $this->payload(['label' => 'Synthetic other', 'lock_version' => 1]))->assertNotFound();
        $this->assertSame('Synthetic one', $one->refresh()->label);
    }

    public function test_rename_and_archive_preserve_saved_medicine_defaults_and_prescription_text(): void
    {
        $director = $this->actor('director');
        $old = 'Synthetic original instruction';
        $option = app(CatalogueOptionService::class)->create($director, 'instruction', $old);
        $medicine = app(MedicineAdministrationService::class)->create($director, ['code' => 'SYNTH-OPTION', 'display_name' => 'Synthetic Option Medicine', 'order_unit' => 'tablet', 'default_instruction' => $old]);
        [$doctor, , $visit, $queue] = $this->servingFixture();
        $this->startEncounter($doctor, $visit, $queue);
        $profile = app(PatientAllergyService::class)->declareNoKnown($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => null]);
        app(PatientAllergyService::class)->review($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'profile_lock_version' => $profile->lock_version]);
        $plan = app(TreatmentPlanService::class)->save($doctor, $visit, ['expected_branch_id' => $visit->branch_id, 'lock_version' => null,
            'medicines' => [['public_id' => null, 'catalogue_public_id' => $medicine->public_id, 'quantity_ordered' => 1, 'dosage' => 'Synthetic dosage', 'frequency' => 'Synthetic frequency', 'duration' => null, 'route' => null, 'administration_instruction' => $old, 'precaution' => null, 'indication' => null]], 'services' => []]);
        $order = $plan->medicineOrders()->sole();
        $this->selectBranch($director);
        $this->patch(route('medicine-details.rename', ['instruction', $option->id]), $this->payload(['label' => 'Synthetic future instruction', 'lock_version' => 1]))->assertRedirect();
        $this->assertSame($old, $medicine->refresh()->default_instruction);
        $this->assertSame($old, $order->refresh()->administration_instruction);
        $this->assertSame([['value' => 'Synthetic future instruction', 'label' => 'Synthetic future instruction']], app(CatalogueOptionService::class)->search($director, 'instruction'));
        $this->patch(route('medicine-details.active', ['instruction', $option->id]), $this->payload(['active' => false, 'lock_version' => 2]))->assertRedirect();
        $this->assertSame([], app(CatalogueOptionService::class)->search($director, 'instruction'));
        $this->assertSame($old, $medicine->refresh()->default_instruction);
        $this->assertSame($old, $order->refresh()->administration_instruction);
        $this->assertDatabaseCount('treatment_plan_medicine_orders', 1);
    }

    public function test_other_organisations_and_expired_assignments_cannot_mutate_options(): void
    {
        $director = $this->actor('director');
        $option = app(CatalogueOptionService::class)->create($director, 'dosage_unit', 'Synthetic unit');
        $organisation = new Organisation;
        $organisation->forceFill(['code' => 'SYNTH-OTHER', 'name' => 'Synthetic Other Organisation', 'is_active' => true])->save();
        $department = new Department;
        $department->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH-DEPT', 'name' => 'Synthetic Department', 'is_active' => true])->save();
        $branch = new Branch;
        $branch->forceFill(['organisation_id' => $organisation->id, 'code' => 'SYNTH-OTHER', 'name' => 'Synthetic Branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $outsider = $this->actor('administrator', $branch);
        $this->selectBranch($outsider, $branch);
        $this->get(route('medicine-details.index', ['type' => 'dosage_unit']))->assertInertia(fn (Assert $p) => $p->has('options', 0));
        $this->patch(route('medicine-details.active', ['dosage_unit', $option->id]), ['expected_branch_id' => $branch->id, 'idempotency_key' => (string) Str::uuid(), 'lock_version' => 1, 'active' => false])->assertNotFound();
        $this->selectBranch($director);
        StaffBranchAssignment::query()->where('staff_profile_id', $director->staffProfile->id)->update(['valid_until' => now()->subDay()->toDateString()]);
        $this->expectException(AuthorizationException::class);
        app(MedicineOptionSettingsService::class)->create($director, 'duration', $this->payload(['labels' => ['Synthetic']]));
    }

    public function test_search_is_literal_and_paginated_and_includes_archived_options(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        foreach (range(1, 26) as $n) {
            app(CatalogueOptionService::class)->create($director, 'medicine_group', 'Synthetic '.$n);
        }
        $special = app(CatalogueOptionService::class)->create($director, 'medicine_group', 'Synthetic 10%');
        $this->patch(route('medicine-details.active', ['medicine_group', $special->id]), $this->payload(['active' => false, 'lock_version' => 1]))->assertRedirect();
        $this->get(route('medicine-details.index'))->assertInertia(fn (Assert $p) => $p->has('options', 25)->where('pagination.total', 27)->where('pagination.lastPage', 2));
        $this->get(route('medicine-details.index', ['q' => '10%']))->assertInertia(fn (Assert $p) => $p->has('options', 1)->where('options.0.active', false));
    }

    public function test_saved_retry_evidence_prevents_destructive_rollback(): void
    {
        $this->selectBranch($this->actor('administrator'));
        $this->post(route('medicine-details.store', 'duration'), $this->payload(['labels' => ['Synthetic']]))->assertRedirect();
        $migration = require database_path('migrations/2026_10_14_000500_add_medicine_option_settings_versions_and_retries.php');
        try {
            $migration->down();
            $this->fail('Retry evidence was removed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Medicine option retry evidence must be retained.', $e->getMessage());
        }
        $this->assertDatabaseCount('medicine_option_settings_commands', 1);
        $this->assertSame(1, CatalogueOption::query()->sole()->lock_version);
    }
}
