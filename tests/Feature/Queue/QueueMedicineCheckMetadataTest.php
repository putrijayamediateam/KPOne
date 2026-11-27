<?php

namespace Tests\Feature\Queue;

use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use App\Domain\Queue\Services\QueueDirectoryService;
use App\Domain\Queue\Services\QueueMedicineCheckMetadata;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;
use Tests\Support\DoctorCheckFixtures;

class QueueMedicineCheckMetadataTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    private ?User $sharedDoctor = null;

    protected function servingFixture(?User $doctor = null, ?User $ca = null): array
    {
        return parent::servingFixture($doctor ?? $this->sharedDoctor, $ca);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow(CarbonImmutable::parse('2026-11-27 09:00:00', $this->branch->timezone)->utc());
    }

    private function sent(array $reuse = []): array
    {
        $f = $this->prepared($reuse);
        $this->switchOn($this->branch);
        $this->selectBranch($f['ca']);
        app(DispensaryService::class)->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version,
            'retry_key' => (string) Str::uuid(),
        ]);
        $this->selectBranch($f['doctor']);

        return $f;
    }

    public function test_only_checks_receive_exact_current_sender_and_structural_line_count(): void
    {
        $f = $this->sent();
        $directory = app(QueueDirectoryService::class);
        $before = DB::table('dispensary_cases')->where('id', $f['case']->id)->first();
        $rows = $directory->workload($f['doctor'], $this->branch->id, 'checks')['data'];
        $this->assertSame(['itemLineCount' => 1, 'sentBy' => $f['ca']->name], $rows[0]['medicineCheck']);
        $this->assertNotSame($f['doctor']->name, $rows[0]['medicineCheck']['sentBy'], 'Original handoff doctor is not the check sender.');
        $this->assertArrayNotHasKey('lines', $rows[0]);
        $this->assertArrayNotHasKey('medicineCheck', $directory->workload($f['doctor'], $this->branch->id, 'completed')['data'][0]);
        $this->assertStringNotContainsString('"medicineCheck":', json_encode($directory->poll($f['doctor']), JSON_THROW_ON_ERROR));
        $this->assertEquals($before, DB::table('dispensary_cases')->where('id', $f['case']->id)->first());
        foreach ([$this->doctor(), $f['ca'], $this->actor('director')] as $actor) {
            $this->selectBranch($actor);
            $this->assertSame([], app(QueueMedicineCheckMetadata::class)->forVisits($actor, collect([$f['visit']])));
        }
    }

    public function test_stale_loaded_assignment_is_rechecked_from_database_and_denied(): void
    {
        $f = $this->sent();
        $metadata = app(QueueMedicineCheckMetadata::class);
        $visits = collect([$f['visit']]);
        $this->assertCount(1, $metadata->forVisits($f['doctor'], $visits));
        $other = $this->doctor();
        DB::table('visits')->where('id', $f['visit']->id)->update(['assigned_doctor_user_id' => $other->id]);
        $this->selectBranch($f['doctor']);
        $this->assertSame($f['doctor']->id, $f['visit']->assigned_doctor_user_id, 'Input remains deliberately stale.');
        $this->assertSame([], $metadata->forVisits($f['doctor'], $visits));
    }

    public function test_twenty_six_actual_checks_keep_bounded_pages_and_constant_projection_queries(): void
    {
        $this->sharedDoctor = $this->doctor();
        $first = $this->sent(['opening' => '100.000']);
        $directory = app(QueueDirectoryService::class);
        $read = function () use ($directory): array {
            $this->selectBranch($this->sharedDoctor);
            $directory->workload($this->sharedDoctor, $this->branch->id, 'checks');
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = $directory->workload($this->sharedDoctor, $this->branch->id, 'checks');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$result, $count];
        };
        [, $singleCount] = $read();
        $expected = [$first['visit']->visit_number => ['itemLineCount' => 1, 'sentBy' => $first['ca']->name]];
        for ($i = 1; $i < 26; $i++) {
            $f = $this->sent($first);
            $expected[$f['visit']->visit_number] = ['itemLineCount' => 1, 'sentBy' => $f['ca']->name];
        }
        $versions = DB::table('dispensary_cases')->orderBy('id')->pluck('lock_version', 'id')->all();
        [$page, $manyCount] = $read();
        $this->assertSame($singleCount, $manyCount);
        $this->assertSame(26, $page['total']);
        $this->assertCount(25, $page['data']);
        $last = $directory->workload($this->sharedDoctor, $this->branch->id, 'checks', 2);
        $this->assertCount(1, $last['data']);
        $this->assertSame($expected, $page['data']->concat($last['data'])->pluck('medicineCheck', 'visitNumber')->all());
        $this->assertSame($versions, DB::table('dispensary_cases')->orderBy('id')->pluck('lock_version', 'id')->all());
    }

    public function test_send_back_hides_metadata_and_resend_counts_intentionally_removed_review_line(): void
    {
        $f = $this->sent();
        $service = app(DispensaryService::class);
        $metadata = app(QueueMedicineCheckMetadata::class);
        $visits = collect([$f['visit']]);
        $service->sendBackDoctorCheck($f['doctor'], $f['case']->refresh(), [
            'expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version,
            'retry_key' => (string) Str::uuid(), 'reason' => 'wrong_quantity',
        ]);
        $this->assertSame([], $metadata->forVisits($f['doctor'], $visits), 'Old sender evidence cannot survive a non-awaiting state.');
        $this->selectBranch($f['ca']);
        $item = $f['case']->handoffs()->where('status', 'open')->sole()->items()->sole();
        $removed = $service->removeItem($f['ca'], $f['case']->refresh(), $item, [
            'expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version,
            'item_lock_version' => $item->lock_version,
        ]);
        $this->assertSame('not_dispensed', $removed->status);
        $this->assertSame('removed', $removed->change_state);
        Date::setTestNow(now()->addMinute());
        $service->sendForDoctorCheck($f['ca'], $f['case']->refresh(), [
            'expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version,
            'retry_key' => (string) Str::uuid(),
        ]);
        $this->selectBranch($f['doctor']);
        $this->assertSame([$f['visit']->visit_number => ['itemLineCount' => 1, 'sentBy' => $f['ca']->name]], $metadata->forVisits($f['doctor'], $visits));
        $this->assertSame('0.000', $removed->quantity_dispensed, 'Review line count is not supplied quantity.');
    }

    public function test_permission_and_current_actor_revocation_are_respected_after_prior_success(): void
    {
        $f = $this->sent();
        $metadata = app(QueueMedicineCheckMetadata::class);
        $visits = collect([$f['visit']]);
        $this->assertCount(1, $metadata->forVisits($f['doctor'], $visits));
        $f['doctor']->roles()->sole()->revokePermissionTo('dispensary.doctor_check.own');
        $f['doctor']->unsetRelation('roles')->unsetRelation('permissions');
        $this->assertSame([], $metadata->forVisits($f['doctor'], $visits));
        $f['doctor']->forceFill(['is_active' => false])->save();
        $this->assertSame([], $metadata->forVisits($f['doctor'], $visits));
    }

    public function test_metadata_rejects_foreign_branch_and_organisation_subjects(): void
    {
        $f = $this->sent();
        $metadata = app(QueueMedicineCheckMetadata::class);
        $wrongBranch = clone $f['visit'];
        $wrongBranch->branch_id = $this->branch->id + 999;
        $wrongOrganisation = clone $f['visit'];
        $wrongOrganisation->organisation_id = $f['doctor']->organisation_id + 999;
        $this->assertSame([], $metadata->forVisits($f['doctor'], collect([$wrongBranch])));
        $this->assertSame([], $metadata->forVisits($f['doctor'], collect([$wrongOrganisation])));
        $this->assertCount(1, $metadata->forVisits($f['doctor'], collect([$f['visit']])));
    }
}
