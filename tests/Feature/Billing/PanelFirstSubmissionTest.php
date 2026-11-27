<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelFirstSubmissionDueProjection;
use App\Domain\Visit\Models\Panel;
use App\Http\Middleware\RouteIsolatedThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

class PanelFirstSubmissionTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_only_never_submitted_prepared_claims_appear_and_prior_submission_edit_stays_out(): void
    {
        [$panel, $claim] = $this->preparedClaim();
        $this->get(route('panel-claims.index', ['tab' => 'not_submitted']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.not_submitted', 1)->where('work.tabs.submitted', 1)->where('work.tabs.pending', 0)
            ->where('work.claims.data.0.status', 'Prepared')->where('work.claims.data.0.claimAmountSen', 3000)
            ->where('work.claims.data.0.submissionDue.ruleLabel', 'Submit 30 days after the visit'));
        $this->post(route('panel-claims.submit', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version])->assertSessionHasNoErrors();
        $this->post(route('panel-claims.query', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version, 'note' => 'Synthetic query'])->assertSessionHasNoErrors();
        $this->patch(route('panel-claims.amount', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version, 'amount_sen' => 3100, 'reason' => 'Synthetic corrected amount'])->assertSessionHasNoErrors();
        $this->assertSame('prepared', $claim->refresh()->status);
        $this->get(route('panel-claims.index', ['tab' => 'not_submitted']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.not_submitted', 0)->where('work.tabs.submitted', 1)->has('work.claims.data', 0));
        $this->get(route('panel-claims.index', ['tab' => 'submitted']))->assertOk()->assertInertia(fn (Assert $page) => $page->has('work.claims.data', 1));
    }

    public function test_due_display_preserves_date_only_timed_exact_boundaries_and_legacy_fallback(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-27 15:00:00', $this->branch->timezone));
        [$panel, $claim] = $this->preparedClaim();
        $projection = app(PanelFirstSubmissionDueProjection::class);
        DB::table('panels')->where('id', $panel->id)->update(['claim_due_days' => 0]);
        $end = CarbonImmutable::parse('2026-11-27 23:59:59', $this->branch->timezone);
        $onDay = $projection->forClaims($this->branch, [$claim->id], $end)[$claim->id];
        $this->assertSame('27 Nov 2026', $onDay['dueLabel']);
        $this->assertFalse($onDay['overdue']);
        $this->assertTrue($projection->forClaims($this->branch, [$claim->id], $end->addSecond())[$claim->id]['overdue']);
        foreach ([['daily', 1, '09:00', '2026-11-28 09:00:00', '28 Nov 2026, 9:00 AM'], ['weekly', 1, '09:00', '2026-11-30 09:00:00', '30 Nov 2026, 9:00 AM'], ['monthly', 0, '17:00', '2026-11-30 17:00:00', '30 Nov 2026, 5:00 PM'], ['monthly', 15, '08:00', '2026-12-15 08:00:00', '15 Dec 2026, 8:00 AM']] as [$rule, $offset, $time, $at, $label]) {
            DB::table('panels')->where('id', $panel->id)->update(['claim_due_rule' => $rule, 'claim_due_offset' => $offset, 'claim_due_time' => $time]);
            $moment = CarbonImmutable::parse($at, $this->branch->timezone);
            $row = $projection->forClaims($this->branch, [$claim->id], $moment)[$claim->id];
            $this->assertSame($label, $row['dueLabel']);
            $this->assertFalse($row['overdue']);
            $this->assertTrue($projection->forClaims($this->branch, [$claim->id], $moment->addSecond())[$claim->id]['overdue']);
        }
        // The helper's existing incomplete-timed-rule fallback is date-only, not an invented midnight label.
        DB::table('panels')->where('id', $panel->id)->update(['claim_due_rule' => 'daily', 'claim_due_offset' => 0, 'claim_due_time' => null, 'claim_due_days' => 2, 'is_active' => false]);
        $legacy = $projection->forClaims($this->branch, [$claim->id], $end)[$claim->id];
        $this->assertSame('29 Nov 2026', $legacy['dueLabel']);
        $this->assertSame('Submit 2 days after the visit', $legacy['ruleLabel']);
        $this->assertSame([], $projection->forClaims($this->branch, [99999999], $end));
    }

    public function test_full_count_pagination_and_due_hydration_are_bounded_not_one_query_per_claim(): void
    {
        $this->withoutMiddleware(RouteIsolatedThrottle::class);
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            [, $claim] = $this->preparedClaim();
            $ids[] = $claim->id;
        }
        $this->get(route('panel-claims.index', ['tab' => 'not_submitted', 'claims_page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.not_submitted', 21)->where('work.claims.total', 21)->where('work.claims.currentPage', 2)
            ->has('work.claims.data', 1)->has('work.claims.data.0.submissionDue'));
        DB::enableQueryLog();
        foreach ([array_slice($ids, 0, 1), array_slice($ids, 0, 20)] as $pageIds) {
            DB::flushQueryLog();
            $due = app(PanelFirstSubmissionDueProjection::class)->forClaims($this->branch, $pageIds, CarbonImmutable::now($this->branch->timezone));
            $this->assertCount(1, DB::getQueryLog());
            $this->assertCount(count($pageIds), $due);
        }
        DB::disableQueryLog();
        $this->get(route('panel-claims.index', ['tab' => 'not_submitted', 'name' => 'SYN-NO-MATCH']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.not_submitted', 0)->has('work.claims.data', 0));
        $this->expectException(\InvalidArgumentException::class);
        app(PanelFirstSubmissionDueProjection::class)->forClaims($this->branch, $ids, CarbonImmutable::now());
    }

    public function test_branch_and_role_boundaries_apply_to_list_and_due_context(): void
    {
        [, $claim] = $this->preparedClaim();
        $other = Branch::query()->where('code', 'PUCHONG')->where('organisation_id', $this->organisation->id)->sole();
        $this->assertSame([], app(PanelFirstSubmissionDueProjection::class)->forClaims($other, [$claim->id], CarbonImmutable::now()));
        $this->selectBranch($this->actor('panel_officer', $other), $other);
        $this->get(route('panel-claims.index', ['tab' => 'not_submitted']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.not_submitted', 0)->has('work.claims.data', 0));
        $this->selectBranch($this->actor('ca'), $this->branch);
        $this->get(route('panel-claims.index', ['tab' => 'not_submitted']))->assertForbidden();
    }

    /** @return array{Panel, PanelClaim} */
    private function preparedClaim(): array
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'claim_due_days' => 30, 'claim_due_rule' => 'days_after_visit']);
        [$allocation] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $this->branch->id])->assertRedirect()->assertSessionHasNoErrors();

        return [$panel, PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole()];
    }
}
