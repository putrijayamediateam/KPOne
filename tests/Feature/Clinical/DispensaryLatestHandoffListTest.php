<?php

namespace Tests\Feature\Clinical;

use App\Domain\Clinical\Dispensary\Models\DispensaryHandoff;
use App\Domain\Clinical\Dispensary\Services\DispensaryHandoffService;
use App\Domain\Clinical\Dispensary\Services\DispensaryListService;
use App\Domain\Clinical\Dispensary\Services\DispensaryService;
use Tests\Support\DoctorCheckFixtures;

class DispensaryLatestHandoffListTest extends ClinicalTestCase
{
    use DoctorCheckFixtures;

    /** @return array<string, mixed> */
    private function resentCase(): array
    {
        $f = $this->prepared();
        $service = app(DispensaryService::class);
        $case = $service->returnToDoctor($f['ca'], $f['case']->refresh(), ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version]);
        $this->selectBranch($f['doctor'], $this->branch);
        $case = app(DispensaryHandoffService::class)->send($f['doctor'], $f['visit']->refresh(), ['expected_branch_id' => $this->branch->id, 'lock_version' => $case->treatmentPlan->lock_version]);
        $this->selectBranch($f['ca'], $this->branch);
        $case = $service->start($f['ca'], $case, ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $case->lock_version]);
        $item = $case->handoffs()->where('status', DispensaryHandoff::STATUS_OPEN)->sole()->items()->sole();
        $service->removeItem($f['ca'], $case, $item, ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $case->lock_version, 'item_lock_version' => $item->lock_version]);
        $f['case'] = $case->refresh();

        return $f;
    }

    public function test_prepare_count_uses_latest_attempt_without_changing_ascending_history(): void
    {
        $f = $this->resentCase();
        $row = app(DispensaryListService::class)->page($f['ca'], 'prepare', 1, '')['rows'][0];
        $this->assertSame(0, $row['medicineCount']);
        $this->assertNull($row['labelUrl']);
        $this->assertSame([1, 2], $f['case']->handoffs()->pluck('attempt_number')->all());
        $this->assertSame(['returned', 'open'], $f['case']->handoffs()->pluck('status')->all());
        $this->assertSame('dispensed', $f['case']->handoffs()->first()->items()->sole()->status);
        $this->assertSame('not_dispensed', $f['case']->handoffs()->get()->last()->items()->sole()->status);
    }

    public function test_completed_latest_attempt_restores_existing_ca_and_doctor_label_eligibility(): void
    {
        $f = $this->resentCase();
        $case = app(DispensaryService::class)->complete($f['ca'], $f['case'], ['expected_branch_id' => $this->branch->id, 'case_lock_version' => $f['case']->lock_version]);
        foreach (['ca' => 'reprint', 'doctor' => 'view'] as $role => $action) {
            $this->selectBranch($f[$role], $this->branch);
            $row = app(DispensaryListService::class)->page($f[$role], 'done', 1, '')['rows'][0];
            $this->assertSame(route('dispensary.labels', $case), $row['labelUrl']);
            $this->assertSame($action, $row['labelAction']);
            $this->assertSame(0, $row['medicineCount']);
        }
        $this->assertSame(['returned', 'completed'], $case->handoffs()->pluck('status')->all());
    }
}
