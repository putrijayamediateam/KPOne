<?php

namespace Tests\Feature\Compliance;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Organisation\Compliance\CompliancePermissions;
use App\Domain\Organisation\Compliance\Services\FridgeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/**
 * HR-11b vaccine fridge log (docs/teams/hr/HR-11-DESIGN.md section 6). Synthetic data only. The clock is frozen in Kuala
 * Lumpur, so every day is branch-local.
 */
class FridgeTest extends VisitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
    }

    /** @param array<string, mixed> $extra */
    private function addFridge(array $extra = []): string
    {
        $this->post(route('clinic-compliance.fridges.save'), [...['branch_id' => $this->branch->id, 'name' => 'Vaccine fridge 1', 'min' => '2.0', 'max' => '8.0', 'readings_per_day' => 1, 'idempotency_key' => (string) Str::uuid()], ...$extra])->assertSessionHasNoErrors();

        return (string) DB::table('compliance_fridges')->where('name', $extra['name'] ?? 'Vaccine fridge 1')->value('public_id');
    }

    /** @param array<string, mixed> $extra */
    private function read(string $fridge, array $extra = []): TestResponse
    {
        return $this->post(route('clinic-compliance.readings.store'), [...['fridge_id' => $fridge, 'temp' => '5.0', 'idempotency_key' => (string) Str::uuid()], ...$extra]);
    }

    private function asDirector(): void
    {
        $this->selectBranch($this->actor('director'));
    }

    public function test_grants_are_exact_and_other_roles_reach_nothing(): void
    {
        $holders = fn (string $p) => collect(PermissionCatalogue::roles())->filter(fn (array $perms) => in_array($p, $perms, true))->keys()->sort()->values()->all();
        $this->assertSame(['administrator', 'director'], $holders(CompliancePermissions::FRIDGE_MANAGE));
        $this->assertSame(['administrator', 'ca', 'ca_supervisor', 'director'], $holders(CompliancePermissions::FRIDGE_RECORD));
        $this->assertSame(['administrator', 'ca_supervisor', 'director'], $holders(CompliancePermissions::FRIDGE_REVIEW));
        foreach ([CompliancePermissions::FRIDGE_MANAGE, CompliancePermissions::FRIDGE_RECORD, CompliancePermissions::FRIDGE_REVIEW] as $p) {
            $this->assertFalse(PermissionCatalogue::isAdministrativeAuthority($p));
        }
        $this->asDirector();
        $fridge = $this->addFridge();
        foreach (['technical_admin', 'resident_doctor', 'finance_officer', 'panel_officer', 'marketing', 'business_development', 'queue_display'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get(route('clinic-compliance.index', ['tab' => 'fridge']))->assertForbidden();
            $this->read($fridge)->assertForbidden();
        }
        // The CA records but cannot review, manage or mark in error.
        $this->selectBranch($this->actor('ca'));
        $this->read($fridge)->assertSessionHasNoErrors();
        $reading = (string) DB::table('compliance_fridge_readings')->value('public_id');
        $this->post(route('clinic-compliance.readings.review', $reading), ['action' => 'rechecked_ok', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('clinic-compliance.readings.error', $reading), ['reason' => 'duplicate', 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post(route('clinic-compliance.fridges.save'), ['branch_id' => $this->branch->id, 'name' => 'X1', 'min' => '2', 'max' => '8', 'readings_per_day' => 1, 'idempotency_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_tenths_are_exact_and_bounded(): void
    {
        $this->assertSame(20, FridgeService::tenths('2.0'));
        $this->assertSame(-15, FridgeService::tenths('-1.5'));
        $this->assertSame(80, FridgeService::tenths('8'));
        $this->assertSame(301 - 1, FridgeService::tenths('30.0'));
        foreach (['30.1', '-30.1', '2.55', 'abc', '', '1e1', '2,5', ' '] as $bad) {
            $this->assertNull(FridgeService::tenths($bad), $bad);
        }
    }

    public function test_a_fridge_is_validated_saved_once_per_key_and_never_deleted(): void
    {
        $this->asDirector();
        $this->post(route('clinic-compliance.fridges.save'), ['branch_id' => $this->branch->id, 'name' => 'F', 'min' => '2', 'max' => '8', 'readings_per_day' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('name');
        $this->post(route('clinic-compliance.fridges.save'), ['branch_id' => $this->branch->id, 'name' => 'Vaccine fridge 1', 'min' => '8', 'max' => '2', 'readings_per_day' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('max');
        $this->post(route('clinic-compliance.fridges.save'), ['branch_id' => $this->branch->id, 'name' => 'Vaccine fridge 1', 'min' => 'x', 'max' => '8', 'readings_per_day' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('min');
        $this->post(route('clinic-compliance.fridges.save'), ['branch_id' => $this->branch->id, 'name' => 'Vaccine fridge 1', 'min' => '2', 'max' => '8', 'readings_per_day' => 9, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('readings_per_day');
        $this->post(route('clinic-compliance.fridges.save'), ['branch_id' => $this->branch->id, 'name' => 'Vaccine fridge 1', 'min' => '2', 'max' => '8', 'readings_per_day' => 1, 'expect_by' => '25:00', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('expect_by');
        $this->post(route('clinic-compliance.fridges.save'), ['branch_id' => 999999, 'name' => 'Vaccine fridge 1', 'min' => '2', 'max' => '8', 'readings_per_day' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('branch_id');
        $this->assertSame(0, DB::table('compliance_fridges')->count());

        $key = (string) Str::uuid();
        $payload = ['branch_id' => $this->branch->id, 'name' => 'Vaccine fridge 1', 'min' => '2.0', 'max' => '8.0', 'readings_per_day' => 1, 'idempotency_key' => $key];
        $this->post(route('clinic-compliance.fridges.save'), $payload)->assertSessionHasNoErrors();
        $this->post(route('clinic-compliance.fridges.save'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('compliance_fridges')->count());
        $this->post(route('clinic-compliance.fridges.save'), [...$payload, 'max' => '9.0'])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.fridges.save'), [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('name');
        $row = DB::table('compliance_fridges')->first();
        $this->assertSame([20, 80], [$row->min_tenths, $row->max_tenths]);

        // Edit with the current lock version; a stale one is refused; archiving keeps the row and its readings.
        $id = (string) $row->public_id;
        $this->post(route('clinic-compliance.fridges.save'), [...$payload, 'fridge_id' => $id, 'expected_lock_version' => 9, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.fridges.save'), [...$payload, 'fridge_id' => $id, 'expected_lock_version' => 1, 'min' => '2.0', 'max' => '8.5', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->read($id)->assertSessionHasNoErrors();
        $this->post(route('clinic-compliance.fridges.archive', $id), ['expected_lock_version' => 2, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->read($id)->assertSessionHasErrors('fridge_id');
        $this->assertSame(1, DB::table('compliance_fridges')->where('is_active', false)->count());
        $this->assertSame(1, DB::table('compliance_fridge_readings')->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'like', 'compliance.fridge%')->get()->filter(fn ($a) => str_contains(json_encode($a->metadata), 'Vaccine'))->count());
    }

    public function test_a_reading_is_exact_server_timed_keeps_its_range_and_audit_holds_no_temperature(): void
    {
        $this->asDirector();
        $fridge = $this->addFridge();
        $this->read($fridge, ['temp' => ''])->assertSessionHasErrors('temp');
        $this->read($fridge, ['temp' => '31'])->assertSessionHasErrors('temp');
        $this->read($fridge, ['temp' => '5.0', 'low' => '6.0'])->assertSessionHasErrors('low');
        $this->read($fridge, ['temp' => '5.0', 'high' => '4.0'])->assertSessionHasErrors('high');
        $this->read($fridge, ['temp' => '5.0', 'reason' => 'nonsense'])->assertSessionHasErrors('reason');
        $this->assertSame(0, DB::table('compliance_fridge_readings')->count());

        $key = (string) Str::uuid();
        $this->read($fridge, ['temp' => '5.5', 'low' => '3.0', 'high' => '7.8', 'reason' => 'delivery', 'taken_at' => '2020-01-01 00:00', 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $this->read($fridge, ['temp' => '5.5', 'low' => '3.0', 'high' => '7.8', 'reason' => 'delivery', 'taken_at' => '2020-01-01 00:00', 'idempotency_key' => $key])->assertSessionHasNoErrors();
        $r = DB::table('compliance_fridge_readings')->first();
        $this->assertSame(1, DB::table('compliance_fridge_readings')->count());
        $this->assertSame([55, 30, 78, 20, 80], [$r->temp_tenths, $r->low_tenths, $r->high_tenths, $r->range_min_tenths, $r->range_max_tenths]);
        $this->assertSame('2027-03-08 01:00:00', CarbonImmutable::parse((string) $r->taken_at)->utc()->format('Y-m-d H:i:s'), 'the time is the server time, never the submitted one');
        $audit = AuditLog::query()->where('event', 'compliance.fridge_reading_recorded')->first();
        $this->assertFalse($audit->metadata['out_of_range']);
        $this->assertStringNotContainsString('5.5', json_encode($audit->metadata));
        $this->assertStringNotContainsString('55', json_encode($audit->metadata['reading_id'] ?? ''));

        // Changing the range later never changes what the old reading was judged against.
        $this->post(route('clinic-compliance.fridges.save'), ['fridge_id' => $fridge, 'expected_lock_version' => 1, 'name' => 'Vaccine fridge 1', 'min' => '6.0', 'max' => '8.0', 'readings_per_day' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame([20, 80], [DB::table('compliance_fridge_readings')->value('range_min_tenths'), DB::table('compliance_fridge_readings')->value('range_max_tenths')]);
    }

    public function test_an_out_of_range_reading_is_an_alert_until_one_action_is_recorded_by_anyone_with_the_permission(): void
    {
        $this->asDirector();
        $fridge = $this->addFridge();
        $this->read($fridge, ['temp' => '5.0'])->assertSessionHasNoErrors();
        $inRange = (string) DB::table('compliance_fridge_readings')->value('public_id');
        $this->read($fridge, ['temp' => '5.0', 'high' => '9.1'])->assertSessionHasNoErrors();
        $this->read($fridge, ['temp' => '1.9'])->assertSessionHasNoErrors();
        $this->assertSame(2, (int) DB::table('compliance_fridge_readings')->get()->filter(fn ($r) => FridgeService::outOfRange($r))->count());

        $this->get(route('clinic-compliance.index', ['tab' => 'fridge']))->assertInertia(fn (Assert $page) => $page->where('compliance.fridge.alertCount', 2)->where('compliance.fridge.fridges.0.todayCount', 3));

        // In-range readings need no action.
        $this->post(route('clinic-compliance.readings.review', $inRange), ['action' => 'rechecked_ok', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $out = (string) DB::table('compliance_fridge_readings')->where('temp_tenths', 19)->value('public_id');
        $this->post(route('clinic-compliance.readings.review', $out), ['action' => 'nonsense', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('action');
        $this->post(route('clinic-compliance.readings.review', $out), ['action' => 'other', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('note');
        $this->post(route('clinic-compliance.readings.review', $out), ['action' => 'other', 'note' => 'Synthetic note', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertNotSame('Synthetic note', DB::table('compliance_fridge_reviews')->value('note_encrypted'));
        $this->post(route('clinic-compliance.readings.review', $out), ['action' => 'moved_stock', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->get(route('clinic-compliance.index', ['tab' => 'fridge']))->assertInertia(fn (Assert $page) => $page->where('compliance.fridge.alertCount', 1));
        $this->assertStringNotContainsString('Synthetic note', json_encode(AuditLog::query()->where('event', 'compliance.fridge_reading_reviewed')->get()->pluck('metadata')));

        // A reading is never changed by its review.
        $this->assertSame(19, (int) DB::table('compliance_fridge_readings')->where('public_id', $out)->value('temp_tenths'));
    }

    public function test_an_old_unreviewed_out_of_range_reading_stays_an_alert_after_many_in_range_readings(): void
    {
        $this->asDirector();
        $fridge = $this->addFridge();
        $this->read($fridge, ['temp' => '12.0'])->assertSessionHasNoErrors();
        for ($i = 0; $i < 60; $i++) {
            $this->read($fridge, ['temp' => '5.0'])->assertSessionHasNoErrors();
        }
        $this->get(route('clinic-compliance.index', ['tab' => 'fridge']))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.fridge.alertCount', 1)->where('compliance.fridge.fridges.0.alerts.0.temp', '12.0'));
    }

    public function test_marking_a_reading_in_error_removes_the_alert_and_the_daily_count_but_keeps_it(): void
    {
        $this->asDirector();
        $fridge = $this->addFridge();
        $this->read($fridge, ['temp' => '15.0'])->assertSessionHasNoErrors();
        $reading = (string) DB::table('compliance_fridge_readings')->value('public_id');
        $this->post(route('clinic-compliance.readings.error', $reading), ['reason' => 'nonsense', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('reason');
        $this->post(route('clinic-compliance.readings.error', $reading), ['reason' => 'wrong_number', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('clinic-compliance.readings.error', $reading), ['reason' => 'duplicate', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->post(route('clinic-compliance.readings.review', $reading), ['action' => 'rechecked_ok', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('compliance');
        $this->assertSame(1, DB::table('compliance_fridge_readings')->count());
        $this->get(route('clinic-compliance.index', ['tab' => 'fridge']))->assertInertia(fn (Assert $page) => $page
            ->where('compliance.fridge.alertCount', 0)->where('compliance.fridge.fridges.0.todayCount', 0)->where('compliance.fridge.fridges.0.recent.0.errorReason', 'Wrong number'));
    }

    public function test_a_branch_assignment_is_needed_to_record_or_review_and_each_person_sees_their_branch(): void
    {
        $this->asDirector();
        $mine = $this->addFridge();
        $other = $this->branch->replicate();
        $other->forceFill(['code' => 'FRIDGE_'.Str::upper(Str::random(5))]);
        if (array_key_exists('public_id', $other->getAttributes())) {
            $other->public_id = (string) Str::uuid();
        }
        $other->save();
        $elsewhere = $this->addFridge(['branch_id' => $other->id, 'name' => 'Other branch fridge']);

        $this->selectBranch($this->actor('ca_supervisor'));
        $this->read($elsewhere)->assertSessionHasErrors('compliance');
        $this->read($mine)->assertSessionHasNoErrors();
        $this->get(route('clinic-compliance.index', ['tab' => 'fridge']))->assertInertia(fn (Assert $page) => $page
            ->has('compliance.fridge.fridges', 1)->where('compliance.fridge.fridges.0.name', 'Vaccine fridge 1')->where('compliance.fridge.fridges.0.canRecord', true));
        $this->assertSame(0, DB::table('compliance_fridge_readings')->where('fridge_id', DB::table('compliance_fridges')->where('name', 'Other branch fridge')->value('id'))->count());
        $this->read((string) Str::uuid())->assertNotFound();
    }

    public function test_days_missed_and_today_follow_the_branch_day_and_the_cut_off(): void
    {
        $this->asDirector();
        $fridge = $this->addFridge(['readings_per_day' => 2, 'expect_by' => '10:00']);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 09:00', $this->branch->timezone));
        $this->read($fridge, ['temp' => '5.0'])->assertSessionHasNoErrors();
        $days = fn () => $this->get(route('clinic-compliance.index', ['tab' => 'fridge']))->viewData('page')['props']['compliance']['fridge']['fridges'][0]['days'];
        // 09:00, before the 10:00 cut-off: today is not yet missed.
        $this->assertFalse($days()[0]['missed']);
        $this->travelTo(CarbonImmutable::parse('2027-03-08 10:30', $this->branch->timezone));
        $today = $days()[0];
        $this->assertTrue($today['isToday']);
        $this->assertTrue($today['missed'], 'one reading of two after the cut-off is missed');
        $this->assertSame(1, $today['count']);
        // Late on the branch day (23:59 KL, which is the UTC afternoon) still counts as the same local day.
        $this->read($fridge, ['temp' => '6.0'])->assertSessionHasNoErrors();
        $this->assertFalse($days()[0]['missed']);
        $this->travelTo(CarbonImmutable::parse('2027-03-09 00:30', $this->branch->timezone));
        $this->assertTrue(collect($days())->contains(fn ($d) => $d['date'] === '2027-03-09'), 'a new local day starts at branch midnight');
    }

    public function test_postgresql_keeps_readings_and_enforces_its_own_rules(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The fridge rules are PostgreSQL only.');
        }
        $this->asDirector();
        $fridge = $this->addFridge();
        $this->read($fridge, ['temp' => '15.0'])->assertSessionHasNoErrors();
        $reading = DB::table('compliance_fridge_readings')->first();
        $reviewId = (string) $reading->public_id;
        $this->post(route('clinic-compliance.readings.review', $reviewId), ['action' => 'rechecked_ok', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $review = DB::table('compliance_fridge_reviews')->first();
        $this->read($fridge, ['temp' => '5.0'])->assertSessionHasNoErrors();
        $second = DB::table('compliance_fridge_readings')->where('temp_tenths', 50)->first();
        $this->post(route('clinic-compliance.readings.error', (string) $second->public_id), ['reason' => 'duplicate', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $correction = DB::table('compliance_fridge_corrections')->first();
        $fridgeRow = DB::table('compliance_fridges')->first();

        $refused = function (callable $write, string $needle): void {
            DB::beginTransaction();
            try {
                $write();
                $this->fail('PostgreSQL should refuse this');
            } catch (QueryException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            } finally {
                DB::rollBack();
            }
        };
        $kept = 'Clinic compliance records are kept';
        $refused(fn () => DB::table('compliance_fridge_readings')->where('id', $reading->id)->update(['temp_tenths' => 50]), $kept);
        $refused(fn () => DB::table('compliance_fridge_readings')->where('id', $reading->id)->delete(), $kept);
        $refused(fn () => DB::table('compliance_fridge_reviews')->where('id', $review->id)->update(['action_code' => 'other']), $kept);
        $refused(fn () => DB::table('compliance_fridge_reviews')->where('id', $review->id)->delete(), $kept);
        $refused(fn () => DB::table('compliance_fridge_corrections')->where('id', $correction->id)->update(['reason_code' => 'other']), $kept);
        $refused(fn () => DB::table('compliance_fridge_corrections')->where('id', $correction->id)->delete(), $kept);
        $refused(fn () => DB::statement('TRUNCATE compliance_fridge_readings CASCADE'), $kept);
        $refused(fn () => DB::table('compliance_fridges')->where('id', $fridgeRow->id)->delete(), $kept);

        // Shape: bounds, order, a range that matches the fridge, one review and one mark per reading, a note for "other".
        $base = (array) $reading;
        unset($base['id']);
        $refused(fn () => DB::table('compliance_fridge_readings')->insert([...$base, 'public_id' => (string) Str::uuid(), 'temp_tenths' => 301]), 'compliance_fridge_readings_shape');
        $refused(fn () => DB::table('compliance_fridge_readings')->insert([...$base, 'public_id' => (string) Str::uuid(), 'temp_tenths' => 50, 'low_tenths' => 60]), 'compliance_fridge_readings_shape');
        $refused(fn () => DB::table('compliance_fridge_readings')->insert([...$base, 'public_id' => (string) Str::uuid(), 'range_min_tenths' => 10]), 'keeps the fridge range');
        $refused(fn () => DB::table('compliance_fridge_readings')->insert([...$base, 'public_id' => (string) Str::uuid(), 'reason_code' => 'nonsense']), 'compliance_fridge_readings_shape');
        $refused(fn () => DB::table('compliance_fridge_reviews')->insert(['public_id' => (string) Str::uuid(), 'organisation_id' => $reading->organisation_id, 'reading_id' => $reading->id, 'action_code' => 'moved_stock',
            'reviewed_by_user_id' => $reading->entered_by_user_id, 'created_at' => now()]), 'compliance_fridge_reviews_reading_id_unique');
        $refused(fn () => DB::table('compliance_fridge_corrections')->insert(['organisation_id' => $reading->organisation_id, 'reading_id' => $second->id, 'reason_code' => 'other',
            'corrected_by_user_id' => $reading->entered_by_user_id, 'created_at' => now()]), 'compliance_fridge_corrections_reading_id_unique');
        // A fridge keeps its branch, and an archived fridge stays archived and takes no readings.
        $refused(fn () => DB::table('compliance_fridges')->where('id', $fridgeRow->id)->update(['branch_id' => $fridgeRow->branch_id + 1000]), 'keeps its branch');
        $this->post(route('clinic-compliance.fridges.archive', (string) $fridgeRow->public_id), ['expected_lock_version' => 1, 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $refused(fn () => DB::table('compliance_fridges')->where('id', $fridgeRow->id)->update(['is_active' => true]), 'stays archived');
        $refused(fn () => DB::table('compliance_fridge_readings')->insert([...$base, 'public_id' => (string) Str::uuid()]), 'archived fridge takes no new readings');
    }
}
