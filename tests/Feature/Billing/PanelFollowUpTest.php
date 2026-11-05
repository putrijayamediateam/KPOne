<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PNL-4: the Follow-up list shows open Panel claims with no new action for longer than their Panel's
 * follow-up days (default 30).
 */
class PanelFollowUpTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_a_claim_appears_after_its_panels_follow_up_days_and_a_new_action_resets_the_clock(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $claim = $this->submittedClaim($panel);
        $officer = $this->officer();

        $this->assertFollowUp([]);
        $this->travel(29)->days();
        $this->assertFollowUp([]);
        $this->travel(2)->days();
        $this->selectBranch($officer, $this->branch);
        $this->get(route('panel-claims.follow-up'))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('PanelClaims/FollowUp')
                ->has('claims.data', 1)
                ->where('claims.data.0.publicId', $claim->public_id)
                ->where('claims.data.0.lastAction', 'Submitted to Panel')
                ->where('claims.data.0.lastActionBy', $officer->name)
                ->where('claims.data.0.daysSinceAction', 31)
                ->where('claims.data.0.followUpDays', 30)
                ->where('claims.data.0.status', 'Submitted')
                ->where('claims.data.0.amountLabel', 'Claimed, awaiting decision')->where('claims.data.0.amountSen', 3000)
                ->missing('claims.data.0.diagnoses'));

        // A note is a new action: the claim leaves the list until another 30 days pass.
        $this->post(route('panel-claims.note', $claim), ['expected_branch_id' => $this->branch->id, 'note' => 'Synthetic follow-up call made'])
            ->assertSessionHasNoErrors();
        $this->assertFollowUp([]);
        $this->travel(30)->days();
        $this->assertFollowUp([$claim->public_id]);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->where('claims.data.0.lastAction', 'Note added'));
        $this->assertSame(1, PanelClaim::query()->count());
    }

    public function test_each_panel_uses_its_own_follow_up_days_and_the_list_can_be_filtered(): void
    {
        $quick = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'claim_follow_up_days' => 10]);
        $standard = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $quickClaim = $this->submittedClaim($quick);
        $standardClaim = $this->submittedClaim($standard);

        $this->travel(11)->days();
        $this->assertFollowUp([$quickClaim->public_id]);
        $this->travel(20)->days();
        $this->assertFollowUp([$quickClaim->public_id, $standardClaim->public_id]);
        $this->get(route('panel-claims.follow-up', ['panel' => $standard->code]))
            ->assertInertia(fn (Assert $page) => $page->has('claims.data', 1)->where('claims.data.0.publicId', $standardClaim->public_id)
                ->where('panels', fn ($panels) => collect($panels)->contains(fn ($p) => $p['code'] === $quick->code && $p['followUpDays'] === 10)));
    }

    public function test_paid_and_rejected_claims_are_never_listed_and_a_payment_counts_as_an_action(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $paid = $this->submittedClaim($panel);
        $rejected = $this->submittedClaim($panel);
        $accepted = $this->submittedClaim($panel);
        $this->decide($paid, 'accepted', 3000);
        $this->decide($rejected, 'rejected', 0);
        $this->decide($accepted, 'accepted', 3000);
        $this->pay($paid, 3000);
        $this->pay($accepted, 1000);

        $this->travel(31)->days();
        $this->assertFollowUp([$accepted->public_id]);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page
            ->where('claims.data.0.lastAction', 'Payment recorded')
            ->where('claims.data.0.status', 'Partially paid')
            ->where('claims.data.0.amountLabel', 'Unpaid')->where('claims.data.0.amountSen', 2000));
    }

    public function test_actions_in_the_same_second_are_ordered_as_recorded(): void
    {
        $this->freezeSecond();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $claim = $this->submittedClaim($panel);
        $this->decide($claim, 'accepted', 3000);

        $this->travel(31)->days();
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page
            ->has('claims.data', 1)
            ->where('claims.data.0.lastAction', 'Panel accepted claim')
            ->where('claims.data.0.status', 'Accepted, unpaid'));
    }

    public function test_the_list_starts_exactly_at_the_follow_up_days_and_includes_prepared_claims(): void
    {
        $this->freezeSecond();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$allocation] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $this->branch->id])->assertSessionHasNoErrors();
        $prepared = PanelClaim::query()->sole();

        $this->travel(30)->days();
        $this->travel(-1)->seconds();
        $this->assertFollowUp([]);
        $this->travel(1)->seconds();
        $this->assertFollowUp([$prepared->public_id]);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page
            ->where('claims.data.0.status', 'Prepared (not yet submitted)')
            ->where('claims.data.0.lastAction', 'Claim prepared')
            ->where('claims.data.0.daysSinceAction', 30));
    }

    public function test_a_payment_in_the_same_second_as_a_decision_is_the_last_action(): void
    {
        $this->freezeSecond();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $claim = $this->submittedClaim($panel);
        $this->decide($claim, 'partially_accepted', 2000);
        $this->pay($claim, 500);

        $this->travel(31)->days();
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page
            ->has('claims.data', 1)->where('claims.data.0.lastAction', 'Payment recorded')->where('claims.data.0.amountSen', 1500));
    }

    public function test_only_panel_officers_at_the_claims_branch_see_the_list(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->submittedClaim($panel);
        $this->travel(31)->days();

        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'director', 'finance_officer', 'administrator', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->get(route('panel-claims.follow-up'))->assertForbidden();
        }
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->actor('panel_officer', $otherBranch);
        $this->selectBranch($elsewhere, $otherBranch);
        $this->get(route('panel-claims.follow-up'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('claims.data', 0));
    }

    public function test_follow_up_days_are_saved_in_panel_settings_audited_bounded_and_kept_when_not_sent(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'lock_version' => 1, 'verification_mode' => 'remark']);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $payload = [
            'name' => $panel->name, 'verification_mode' => 'remark', 'claim_due_rule' => 'days_after_visit', 'claim_due_days' => 30,
        ];

        $this->get(route('panel-settings.edit', $panel->id))->assertInertia(fn (Assert $page) => $page->where('panel.claimFollowUpDays', 30));
        $this->patch(route('panel-settings.update', $panel->id), [...$payload, 'claim_follow_up_days' => 45, 'lock_version' => 1])->assertSessionHasNoErrors();
        $this->assertSame(45, $panel->refresh()->claim_follow_up_days);
        $audit = DB::table('audit_logs')->where('event', 'panel.updated')->where('subject_id', $panel->id)->latest('id')->value('metadata');
        $this->assertContains('claim_follow_up_days', json_decode((string) $audit, true)['changed_fields']);

        foreach ([0, 366, 'abc'] as $bad) {
            $this->patch(route('panel-settings.update', $panel->id), [...$payload, 'claim_follow_up_days' => $bad, 'lock_version' => 2])
                ->assertSessionHasErrors('claim_follow_up_days');
        }
        // A request without the field leaves the saved value alone.
        $this->patch(route('panel-settings.update', $panel->id), [...$payload, 'phone' => '0312345678', 'lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame(45, $panel->refresh()->claim_follow_up_days);
    }

    /** @param list<string> $expected */
    private function assertFollowUp(array $expected): void
    {
        $this->selectBranch($this->officer(), $this->branch);
        $this->get(route('panel-claims.follow-up'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('claims.data', fn ($rows) => collect($rows)->pluck('publicId')->all() === $expected));
    }

    private function submittedClaim(Panel $panel): PanelClaim
    {
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id,
            'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();

        return PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
    }

    private function decide(PanelClaim $claim, string $decision, int $accepted): void
    {
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.decision', $claim), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version,
            'decision' => $decision, 'accepted_amount_sen' => $accepted, 'note' => 'Synthetic Panel decision',
        ])->assertSessionHasNoErrors();
    }

    private function pay(PanelClaim $claim, int $amount): void
    {
        $this->post(route('panel-claims.payment', $claim), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => $amount,
            'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
    }
}
