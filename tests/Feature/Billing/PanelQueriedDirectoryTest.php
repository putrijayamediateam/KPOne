<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Models\Panel;
use App\Http\Middleware\RouteIsolatedThrottle;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class PanelQueriedDirectoryTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_queried_is_distinct_from_rejected_and_preserves_submitted_membership_and_amounts(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $claim = $this->submittedClaim($panel);
        $this->queryClaim($claim);
        $this->get(route('panel-claims.index', ['tab' => 'queried']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.filters.tab', 'queried')->where('work.tabs.queried', 1)
            ->where('work.tabs.submitted', 1)->where('work.tabs.rejected', 0)
            ->has('work.claims.data', 1)->where('work.claims.data.0.status', 'Panel query')
            ->where('work.claims.data.0.claimAmountSen', 3000));
        $this->post(route('panel-claims.decision', $claim), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version,
            'decision' => 'rejected', 'accepted_amount_sen' => 0, 'note' => 'Synthetic final rejection',
        ])->assertSessionHasNoErrors();
        $this->get(route('panel-claims.index', ['tab' => 'queried']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.queried', 0)->where('work.tabs.rejected', 1)->has('work.claims.data', 0));
        $this->get(route('panel-claims.index', ['tab' => 'rejected']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('work.claims.data', 1)->where('work.claims.data.0.status', 'Rejected'));
    }

    public function test_queried_count_covers_all_filtered_rows_before_pagination(): void
    {
        $this->withoutMiddleware(RouteIsolatedThrottle::class);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        for ($i = 0; $i < 21; $i++) {
            $this->queryClaim($this->submittedClaim($panel));
        }
        $this->submittedClaim($panel); // Existing Submitted umbrella still contains this row.
        $this->get(route('panel-claims.index', ['tab' => 'queried', 'panel' => $panel->name, 'claims_page' => 2]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.queried', 21)->where('work.tabs.submitted', 22)
            ->where('work.claims.total', 21)->where('work.claims.currentPage', 2)->has('work.claims.data', 1));
        $this->get(route('panel-claims.index', ['tab' => 'queried', 'panel' => $panel->name, 'name' => 'SYN-NO-MATCH']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.queried', 0)->where('work.claims.total', 0)->has('work.claims.data', 0));
    }

    public function test_queried_respects_branch_authority_and_rejected_batch_keeps_the_view(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->queryClaim($this->submittedClaim($panel));
        $this->post(route('panel-claims.bulk-store'), ['tab' => 'queried', 'expected_branch_id' => $this->branch->id, 'claims' => []])
            ->assertSessionHasErrors()->assertRedirect(route('panel-claims.index', ['tab' => 'queried']));
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $otherOfficer = $this->actor('panel_officer', $otherBranch);
        $this->selectBranch($otherOfficer, $otherBranch);
        $this->get(route('panel-claims.index', ['tab' => 'queried']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.queried', 0)->has('work.claims.data', 0));
        $this->selectBranch($this->actor('ca'), $this->branch);
        $this->get(route('panel-claims.index', ['tab' => 'queried']))->assertForbidden();
    }

    private function submittedClaim(Panel $panel): PanelClaim
    {
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
    }

    private function queryClaim(PanelClaim $claim): void
    {
        $this->post(route('panel-claims.query', $claim), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version,
            'note' => 'Synthetic Panel question',
        ])->assertSessionHasNoErrors();
    }
}
