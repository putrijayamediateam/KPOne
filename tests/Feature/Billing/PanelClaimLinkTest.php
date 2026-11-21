<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Services\PanelClaimLink;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SyntheticVisitCompletion;

/**
 * BIL-35 (owner "go", agreed with the Panel team): a Panel invoice offers "Create claim" or "Open Panel claim" to Panel
 * Claims users at the invoice's active branch, under the same conditions as the Panel team's Create page.
 */
class PanelClaimLinkTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_patient_billing_offers_create_claim_then_open_panel_claim_with_a_way_back(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-LINK']);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $visit = Visit::query()->findOrFail($invoice->visit_id);
        $director = $this->actor('director');
        $this->selectBranch($director, $this->branch);

        $create = route('panel-claims.create', [$allocation, 'from' => 'billing', 'visit' => $visit->visit_number], false);
        $this->actingAs($director)->get(route('billing.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('billing.panelClaim.kind', 'create')
                ->where('billing.panelClaim.label', 'Create claim')
                ->where('billing.panelClaim.href', $create)
                ->where('billing.panelClaim.claimNumber', null));
        // The Panel team's Create page accepts it (its own guards), with the way-back values carried along.
        $this->get($create)->assertOk();

        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $claim = app(PanelClaimService::class)->prepare($officer, $allocation, ['expected_branch_id' => $visit->branch_id]);
        $number = 'PCL-'.str_pad((string) $claim->id, 8, '0', STR_PAD_LEFT);

        $this->selectBranch($director, $this->branch);
        $this->actingAs($director)->get(route('billing.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('billing.panelClaim.kind', 'open')
                ->where('billing.panelClaim.label', 'Open Panel claim · '.$number)
                ->where('billing.panelClaim.claimNumber', $number)
                ->where('billing.panelClaim.href', route('panel-claims.show', [$claim, 'from' => 'billing', 'visit' => $visit->visit_number], false)));
    }

    public function test_the_completed_invoice_links_with_from_invoice(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-LINK2']);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $visit = Visit::query()->findOrFail($invoice->visit_id);
        SyntheticVisitCompletion::complete($visit);
        $director = $this->actor('director');
        $this->selectBranch($director, $this->branch);

        $this->actingAs($director)->get(route('visits.invoice', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.links.panelClaim.kind', 'create')
                ->where('invoice.links.panelClaim.href', route('panel-claims.create', [$allocation, 'from' => 'invoice', 'visit' => $visit->visit_number], false)));
    }

    public function test_no_button_without_panel_claims_access_or_at_another_branch_or_without_a_panel_share(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-LINK3']);
        [, $invoice] = $this->approvedAllocation($panel);
        $visit = Visit::query()->findOrFail($invoice->visit_id);
        $link = app(PanelClaimLink::class);

        // A CA cannot open Panel Claims.
        $ca = $this->actor('ca');
        $this->selectBranch($ca, $this->branch);
        $this->actingAs($ca)->get(route('billing.show', $visit))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('billing.panelClaim', null));

        // A Director whose active branch is another branch.
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $elsewhere = $this->actor('director', $other);
        $this->actingAs($elsewhere);
        $this->selectBranch($elsewhere, $other);
        $this->assertNull($link->for($elsewhere, $visit, $invoice, 'billing'));

    }

    public function test_no_button_on_a_self_pay_invoice(): void
    {
        $link = app(PanelClaimLink::class);
        [, , $selfPayVisit, $selfPayInvoice] = $this->finalizedFixture();
        $director = $this->actor('director');
        $this->actingAs($director);
        $this->selectBranch($director, $this->branch);
        $this->assertNull($link->for($director, $selfPayVisit, $selfPayInvoice, 'billing'));
        $this->assertNull($link->for($director, $selfPayVisit, null, 'billing'));
    }
}
