<?php

namespace Tests\Feature\Insights;

use App\Domain\Organisation\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * INS-BM-1 (owner decision 21 Nov 2026, confirmed 27 Nov): the Branch Manager sees Insights for its own branch only.
 * Another branch or "All branches" is refused on every Insights page, the picker offers only its branch, and the CSV
 * links default to that branch. Organisation accounts (the Director) are unchanged.
 */
final class BranchManagerInsightsScopeTest extends ClinicalTestCase
{
    private const SECTIONS = ['sales', 'in-clinic', 'payments', 'inventory', 'patients'];

    private function otherBranch(): Branch
    {
        return Branch::query()->where('organisation_id', $this->organisation->id)->where('is_active', true)
            ->where('id', '!=', $this->branch->id)->orderBy('code')->firstOrFail();
    }

    public function test_a_branch_manager_is_refused_another_branch_and_all_branches_on_every_insights_page(): void
    {
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $other = $this->otherBranch()->code;

        foreach (['all', $other] as $branch) {
            $this->actingAs($manager)->get(route('insights.today', ['branch' => $branch]))->assertForbidden();
            foreach (self::SECTIONS as $section) {
                $this->actingAs($manager)->get(route('insights.report', ['section' => $section, 'branch' => $branch]))->assertForbidden();
            }
        }
    }

    public function test_a_branch_manager_sees_only_its_own_branch_by_default_with_no_all_branches_choice(): void
    {
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $own = $this->branch->code;

        $this->actingAs($manager)->get(route('insights.today'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('branch', $own)
            ->where('canSeeAllBranches', false)
            ->where('branches', fn ($branches): bool => collect($branches)->pluck('code')->all() === [$own]));
        $this->actingAs($manager)->get(route('insights.today', ['branch' => $own]))->assertOk();

        foreach (self::SECTIONS as $section) {
            $this->actingAs($manager)->get(route('insights.report', ['section' => $section]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('filters.branch', $own)
                ->where('data.branch', $own)
                ->where('canSeeAllBranches', false)
                ->where('branches', fn ($branches): bool => collect($branches)->pluck('code')->all() === [$own]));
        }
    }

    public function test_a_branch_managers_csv_links_default_to_its_own_branch_and_download(): void
    {
        $manager = $this->actor('branch_manager');
        $this->selectBranch($manager);
        $range = ['from' => '2026-10-01', 'to' => '2026-10-01'];

        $sales = $this->actingAs($manager)->get(route('insights.report', ['section' => 'sales'] + $range))->assertOk();
        $payments = $this->actingAs($manager)->get(route('insights.report', ['section' => 'payments'] + $range))->assertOk();
        $links = [
            $sales->viewData('page')['props']['summaryDownloadUrl'],
            $sales->viewData('page')['props']['dailyDownloadUrl'],
            $payments->viewData('page')['props']['dailyDownloadUrl'],
        ];
        foreach ($links as $link) {
            $this->assertIsString($link);
            $this->assertStringContainsString('branch='.$this->branch->code, $link);
            $this->actingAs($manager)->get($link)->assertOk();
        }

        // A download link with no branch also falls back to the manager's own branch rather than "all".
        $this->actingAs($manager)->get(route('insights.sales-summary.download', $range))->assertOk();
        $this->actingAs($manager)->get(route('insights.daily-receipts.download', $range))->assertOk();
    }

    public function test_the_director_still_sees_all_branches_and_any_branch(): void
    {
        $director = $this->actor('director');
        $this->selectBranch($director);
        $other = $this->otherBranch()->code;

        $this->actingAs($director)->get(route('insights.today'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('branch', 'all')
            ->where('canSeeAllBranches', true)
            ->where('branches', fn ($branches): bool => count($branches) > 1));
        $this->actingAs($director)->get(route('insights.today', ['branch' => $other]))->assertOk();
        foreach (self::SECTIONS as $section) {
            $this->actingAs($director)->get(route('insights.report', ['section' => $section]))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('filters.branch', 'all')->where('canSeeAllBranches', true));
            $this->actingAs($director)->get(route('insights.report', ['section' => $section, 'branch' => $other]))->assertOk();
        }
    }
}
