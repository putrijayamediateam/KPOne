<?php

namespace Tests\Feature\Reports;

use App\Domain\Access\PermissionCatalogue;
use Tests\Feature\Clinical\ClinicalTestCase;

/** ROLE-1 follow-up (owner, 22 Nov 2026, "BM reports B"): the Branch Manager downloads the aggregate reports, own branch only. */
final class BranchManagerAggregateReportsTest extends ClinicalTestCase
{
    public function test_the_branch_manager_holds_exactly_the_aggregate_report_permissions_and_no_patient_level_ones(): void
    {
        $held = PermissionCatalogue::roles()['branch_manager'];
        foreach (['reports.sales.export.organisation', 'reports.payments.export.organisation', 'reports.panel_summary.view.branch', 'reports.panel_summary.export.branch', 'reports.daily_summary.export.branch', 'reports.stock_movement.export.branch'] as $permission) {
            $this->assertContains($permission, $held);
        }
        foreach (['reports.transactions.view.branch', 'reports.transactions.export.branch', 'reports.patient_billing.view.branch', 'reports.patient_billing.export.branch'] as $permission) {
            $this->assertNotContains($permission, $held);
        }
    }

    public function test_a_branch_manager_exports_only_their_own_branch_while_a_director_may_export_all(): void
    {
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $own = ['from' => '2026-10-01', 'to' => '2026-10-01'];
        $this->actingAs($manager)->get(route('insights.sales-summary.download', $own + ['branch' => $this->branch->code]))->assertOk();
        $this->actingAs($manager)->get(route('insights.sales-summary.download', $own + ['branch' => 'all']))->assertForbidden();

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->actingAs($director)->get(route('insights.sales-summary.download', $own + ['branch' => 'all']))->assertOk();
    }
}
