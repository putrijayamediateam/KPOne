<?php

namespace Tests\Feature\Insights;

use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Billing\BillingTestCase;

/**
 * INS-1 (owner, 24 Nov 2026, "Lulus semua"): the sales and payments downloads and the Director and Finance overview stand on their own
 * permissions. Holding or not holding `insights.view.organisation` (the old Insights pages) changes none of them.
 */
final class InsightsDecouplingTest extends BillingTestCase
{
    private function without(string $role): void
    {
        Role::findByName($role)->revokePermissionTo('insights.view.organisation');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_downloads_need_their_export_permission_and_not_the_insights_pages(): void
    {
        $this->without('branch_manager');
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $this->assertFalse($manager->can('insights.view.organisation'));
        $own = ['from' => '2026-10-01', 'to' => '2026-10-01', 'branch' => $this->branch->code];

        $this->actingAs($manager)->get(route('insights.sales-summary.download', $own))->assertOk();
        $this->actingAs($manager)->get(route('insights.daily-sales.download', $own))->assertOk();
        $this->actingAs($manager)->get(route('insights.daily-receipts.download', $own))->assertOk();
        // The old pages still need their own permission, and the own-branch rule still holds.
        $this->actingAs($manager)->get(route('insights.today'))->assertForbidden();
        $this->actingAs($manager)->get(route('insights.sales-summary.download', [...$own, 'branch' => 'all']))->assertForbidden();
    }

    public function test_a_role_without_an_export_permission_is_still_refused_even_with_the_insights_pages(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);
        $this->assertTrue($ca->can('insights.view.organisation'));
        $this->actingAs($ca)->get(route('insights.sales-summary.download', ['from' => '2026-10-01', 'to' => '2026-10-01', 'branch' => 'all']))->assertForbidden();
    }

    public function test_the_overview_and_its_hub_row_stand_on_the_overview_permission_alone(): void
    {
        $this->without('director');
        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->assertFalse($director->can('insights.view.organisation'));

        $this->actingAs($director)->get(route('insights.overview'))->assertOk();
        $this->actingAs($director)->get(route('insights.today'))->assertForbidden();
        $this->actingAs($director)->get(route('clinic-management.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('groups', fn ($groups): bool => collect($groups)->flatMap(fn ($g) => $g['links'])->pluck('href')->contains('/insights/overview')));
    }

    public function test_someone_without_the_overview_permission_is_refused_even_with_the_insights_pages(): void
    {
        $assistant = $this->actor('account_assistant');
        $this->selectBranch($assistant);
        $this->assertTrue($assistant->can('insights.view.organisation'));
        $this->actingAs($assistant)->get(route('insights.overview'))->assertForbidden();
    }
}
