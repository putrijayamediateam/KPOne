<?php

namespace Tests\Feature\Clinical;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Dispensary\Models\DispensaryCase;
use App\Domain\Clinical\Dispensary\Models\DispensaryItem;
use App\Domain\Clinical\Dispensary\Services\DispensaryDirectoryService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Clinical\Dispensary\Services\OtcDispensaryService;
use App\Domain\Organisation\Inventory\Models\InventoryStockBalance;
use App\Domain\Visit\Models\Visit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Visit\VisitTestCase;
use Tests\Support\OtcDispensaryFixtures;

class OtcDispensaryTest extends VisitTestCase
{
    use OtcDispensaryFixtures;

    public function test_the_permission_is_held_by_ca_and_ca_supervisor_only(): void
    {
        $holders = collect(PermissionCatalogue::roles())->filter(fn (array $permissions): bool => in_array('dispensary.otc.create.branch', $permissions, true))->keys()->sort()->values()->all();

        $this->assertSame(['ca', 'ca_supervisor'], $holders);
        $this->assertContains('dispensary.otc.create.branch', PermissionCatalogue::all());
        $this->assertTrue(Permission::query()->where('name', 'dispensary.otc.create.branch')->exists());
        $this->assertNotContains('dispensary.otc.create.branch', PermissionCatalogue::AUTHORITY_OVER_PEOPLE_AND_ACCESS);
    }

    public function test_opening_an_otc_case_needs_no_encounter_plan_or_queue_and_is_idempotent(): void
    {
        $f = $this->fixture();

        $case = $this->open($f);

        $this->assertSame(DispensaryCase::TYPE_OTC, $case->case_type);
        $this->assertNull($case->clinical_encounter_id);
        $this->assertNull($case->treatment_plan_id);
        $this->assertSame(DispensaryCase::STATUS_DISPENSING, $case->status);
        $this->assertSame($f['ca']->id, $case->current_handler_user_id);
        $handoff = $case->handoffs->sole();
        $this->assertNull($handoff->treatment_plan_lock_version_received);
        $this->assertSame($case->id, $handoff->open_case_guard);
        $this->assertSame(0, DB::table('queue_entries')->where('visit_id', $f['visit']->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'dispensary.otc_opened')->count());

        $again = $this->open($f);
        $this->assertSame($case->id, $again->id);
        $this->assertSame(1, DispensaryCase::query()->where('visit_id', $f['visit']->id)->count());
    }

    public function test_only_a_registered_otc_visit_can_be_opened(): void
    {
        $f = $this->fixture('consultation');

        $this->expectException(ValidationException::class);
        $this->open($f);
    }

    public function test_opening_needs_the_otc_permission_a_ca_role_and_the_active_branch(): void
    {
        $f = $this->fixture();
        $doctor = $this->actor('resident_doctor');
        $this->selectBranch($doctor, $f['visit']->branch);
        try {
            app(OtcDispensaryService::class)->open($doctor, $f['visit'], ['expected_branch_id' => $f['visit']->branch_id]);
            $this->fail('A doctor must not open an OTC case.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DispensaryCase::query()->count());
        }

        $this->selectBranch($f['ca'], $f['visit']->branch);
        $this->expectException(ValidationException::class);
        app(OtcDispensaryService::class)->open($f['ca'], $f['visit'], ['expected_branch_id' => $f['visit']->branch_id + 999]);
    }

    public function test_the_ca_adds_a_medicine_without_any_doctor_and_the_line_is_a_ca_line(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);

        $item = app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));

        $this->assertSame(DispensaryItem::SOURCE_CA, $item->source);
        $this->assertSame(DispensaryItem::CHANGE_ADDED, $item->change_state);
        $this->assertNull($item->treatment_plan_medicine_order_id);
        $this->assertSame('2.000', $item->quantity_dispensed);
        $this->assertSame(0, $item->allergy_profile_version_validated);
        $this->assertSame(1, $item->allocations()->count());
    }

    public function test_complete_requires_the_allergy_confirmation_and_one_medicine(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);

        try {
            $this->complete($f, $case);
            $this->fail('An empty OTC case must not complete.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allergy_safety', $e->errors());
        }

        $this->confirmAllergy($f, $case);
        try {
            $this->complete($f, $case);
            $this->fail('An OTC case without a medicine must not complete.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        app(DispensaryService::class)->addItem($f['ca'], $case->refresh(), $this->addPayload($f, $case));
        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $this->complete($f, $case)->status);
    }

    public function test_complete_debits_stock_once_and_closes_the_case(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case, '3.000'));
        $this->confirmAllergy($f, $case, 'has_allergy');

        $completed = $this->complete($f, $case);

        $this->assertSame(DispensaryCase::STATUS_COMPLETED, $completed->status);
        $balance = InventoryStockBalance::query()->where('inventory_location_id', $f['location']->id)->sole();
        $this->assertSame('7.000', $balance->quantity);
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'dispense')->count());
        $handoff = $completed->handoffs->sole();
        $this->assertNotNull($handoff->ca_verified_at);
        $this->assertSame('has_allergy', $handoff->otc_allergy_statement);
        $this->assertSame(Visit::STATUS_REGISTERED, $f['visit']->refresh()->status, 'Dispensing alone does not complete the visit; Billing does.');
    }

    public function test_a_removed_line_is_kept_and_not_debited(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $service = app(DispensaryService::class);
        $line = $service->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $service->removeItem($f['ca'], $case->refresh(), $line->refresh(), ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $line->lock_version]);
        $this->confirmAllergy($f, $case);

        try {
            $this->complete($f, $case);
            $this->fail('A case whose only line was removed must not complete.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }
        $this->assertSame(DispensaryItem::CHANGE_REMOVED, $line->refresh()->change_state);
        $this->assertSame(0, DB::table('stock_movements')->where('movement_type', 'dispense')->count());
    }

    public function test_the_ca_can_edit_an_added_line_and_the_edit_keeps_it_a_ca_line(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $service = app(DispensaryService::class);
        $line = $service->addItem($f['ca'], $case, $this->addPayload($f, $case));
        $payload = $this->addPayload($f, $case->refresh(), '4.000', ['item_lock_version' => $line->lock_version, 'dosage' => 'Two tablets']);
        unset($payload['medicine_public_id']);

        $edited = $service->editItem($f['ca'], $case, $line->refresh(), $payload);

        $this->assertSame('4.000', $edited->quantity_dispensed);
        $this->assertSame(DispensaryItem::CHANGE_ADDED, $edited->change_state);
    }

    public function test_doctor_only_actions_are_refused_for_an_otc_case(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        $service = app(DispensaryService::class);
        $attributes = ['expected_branch_id' => $f['visit']->branch_id, 'case_lock_version' => $case->lock_version];

        foreach ([
            fn () => $service->returnToDoctor($f['ca'], $case, $attributes),
            fn () => $service->addServiceLine($f['ca'], $case, [...$attributes, 'service_public_id' => (string) Str::uuid(), 'quantity_performed' => '1.000']),
        ] as $action) {
            try {
                $action();
                $this->fail('A doctor-only action must be refused for an OTC case.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('case', $e->errors());
            }
        }
        $this->assertSame(DispensaryCase::STATUS_DISPENSING, $case->refresh()->status);
    }

    public function test_the_detail_projection_works_without_a_doctor_and_exposes_the_allergy_confirmation(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);
        app(DispensaryService::class)->addItem($f['ca'], $case, $this->addPayload($f, $case));

        $detail = app(DispensaryDirectoryService::class)->detail($f['ca'], $case->refresh());

        $this->assertSame('otc', $detail['caseType']);
        $this->assertNull($detail['doctor']);
        $this->assertFalse($detail['otc']['allergyConfirmed']);
        $this->assertFalse($detail['can']['return']);
        $this->assertCount(1, $detail['items']);
        $board = app(DispensaryDirectoryService::class)->board($f['ca'], []);
        $this->assertSame(1, $board['total']);
        $this->assertSame('otc', $board['data'][0]['visitType']);
    }

    public function test_the_database_keeps_case_types_consistent(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint.');
        }
        $f = $this->fixture();
        $case = $this->open($f);

        $this->expectException(QueryException::class);
        DB::table('dispensary_cases')->where('id', $case->id)->update(['case_type' => 'consultation']);
    }

    public function test_only_one_otc_case_exists_per_visit(): void
    {
        $f = $this->fixture();
        $case = $this->open($f);

        $this->expectException(QueryException::class);
        DB::table('dispensary_cases')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $case->organisation_id, 'branch_id' => $case->branch_id, 'visit_id' => $case->visit_id,
            'case_type' => 'otc', 'status' => 'dispensing', 'lock_version' => 1, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
