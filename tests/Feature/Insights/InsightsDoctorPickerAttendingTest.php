<?php

namespace Tests\Feature\Insights;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Billing\BillingTestCase;

/**
 * INS-BM-3 (owner decisions 28 Nov 2026): a doctor who attended (checked out) a visit at the branch in the last year
 * stays in a branch-only person's doctor filter even when another doctor was the visit's assigned doctor and the
 * attending doctor no longer works at the branch.
 */
final class InsightsDoctorPickerAttendingTest extends BillingTestCase
{
    public function test_an_attending_only_doctor_is_listed_for_the_branch(): void
    {
        [$attending, , $visit] = $this->finalizedFixture();
        $assigned = $this->actor('resident_doctor');
        DB::table('visits')->where('id', $visit->id)->update(['assigned_doctor_user_id' => $assigned->id]);
        DB::table('staff_branch_assignments')
            ->whereIn('staff_profile_id', DB::table('staff_profiles')->where('user_id', $attending->id)->select('id'))
            ->update(['valid_from' => now()->subDays(10)->toDateString(), 'valid_until' => now()->subDay()->toDateString()]);

        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $response = $this->actingAs($manager)->get(route('insights.report', ['section' => 'sales']))->assertOk();
        $ids = array_map('intval', array_column($response->viewData('page')['props']['doctors'], 'id'));

        $this->assertContains($attending->id, $ids, 'attended a visit at the branch in the last year');
        $this->assertContains($assigned->id, $ids);
        $this->actingAs($manager)->get(route('insights.report', ['section' => 'sales', 'doctor' => $attending->id]))->assertOk();
    }
}
