<?php

namespace Tests\Feature\Insights;

use App\Domain\Organisation\Models\Branch;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;
use Tests\Support\StaffBranchAssignmentBootstrapper;

/**
 * INS-BM-3 (owner decisions 28 Nov 2026): on the Insights reports a branch-only person's doctor filter lists only the
 * doctors of its branch: those assigned there today, plus any doctor who was the assigned or attending doctor on a
 * visit there in the last year (a locum or covering doctor). Any other doctor is refused with 403, on the page and on
 * the downloads. Organisation accounts still see and filter by every doctor.
 */
final class InsightsDoctorPickerScopeTest extends ClinicalTestCase
{
    private function otherBranch(): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
    }

    /** @return list<int> */
    private function pickerIds(User $viewer): array
    {
        $response = $this->actingAs($viewer)->get(route('insights.report', ['section' => 'sales']))->assertOk();

        return array_map('intval', array_column($response->viewData('page')['props']['doctors'], 'id'));
    }

    /** A doctor whose primary branch is Puchong and who saw one patient at Cheras while covering there, now ended. */
    private function locum(): User
    {
        $locum = $this->actor('resident_doctor', $this->otherBranch());
        StaffBranchAssignmentBootstrapper::create($locum->staffProfile, $this->branch, [
            'is_primary' => false,
            'assignment_type' => 'temporary',
            'valid_from' => now()->subDays(3)->toDateString(),
            'valid_until' => null,
        ]);
        $ca = $this->actor('ca');
        $this->register($ca, $this->patient($ca), ['visit_type' => 'consultation', 'assigned_doctor_user_id' => $locum->id, 'visit_reason' => 'Synthetic covering visit']);
        $locum->staffProfile->branchAssignments()->where('branch_id', $this->branch->id)
            ->update(['valid_until' => now()->subDay()->toDateString()]);

        return $locum;
    }

    public function test_a_branch_only_persons_picker_lists_its_branch_doctors_and_covering_doctors_only(): void
    {
        $here = $this->actor('resident_doctor');
        $elsewhere = $this->actor('resident_doctor', $this->otherBranch());
        $locum = $this->locum();
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);

        $ids = $this->pickerIds($manager);
        $this->assertContains($here->id, $ids, 'assigned to the branch today');
        $this->assertContains($locum->id, $ids, 'saw a patient at the branch in the last year');
        $this->assertNotContains($elsewhere->id, $ids, 'another branch only, no visit here');
    }

    public function test_a_branch_only_person_is_refused_a_doctor_outside_its_picker_on_the_page_and_downloads(): void
    {
        $here = $this->actor('resident_doctor');
        $elsewhere = $this->actor('resident_doctor', $this->otherBranch());
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $range = ['from' => '2026-10-01', 'to' => '2026-10-01'];

        foreach (['sales', 'in-clinic', 'payments', 'patients'] as $section) {
            $this->actingAs($manager)->get(route('insights.report', ['section' => $section, 'doctor' => $elsewhere->id] + $range))->assertForbidden();
            $this->actingAs($manager)->get(route('insights.report', ['section' => $section, 'doctor' => $here->id] + $range))->assertOk();
        }
        foreach (['insights.sales-summary.download', 'insights.daily-sales.download', 'insights.daily-receipts.download'] as $download) {
            $this->actingAs($manager)->get(route($download, ['doctor' => $elsewhere->id] + $range))->assertForbidden();
            $this->actingAs($manager)->get(route($download, ['doctor' => $here->id] + $range))->assertOk();
        }
    }

    public function test_the_director_still_sees_and_filters_by_every_doctor(): void
    {
        $here = $this->actor('resident_doctor');
        $elsewhere = $this->actor('resident_doctor', $this->otherBranch());
        $director = $this->actor('director');
        $this->selectBranch($director);

        $ids = $this->pickerIds($director);
        $this->assertContains($here->id, $ids);
        $this->assertContains($elsewhere->id, $ids);
        $this->actingAs($director)->get(route('insights.report', ['section' => 'sales', 'doctor' => $elsewhere->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('filters.doctor', $elsewhere->id));
    }
}
