<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * PNL-6b: the Panel payments history and the Follow-up list show a colleague's name only under the
 * staff-directory visibility rule (owner BIL-2 decision); otherwise "Staff member". Your own name always shows.
 * PNL-6c applies the same rule to the Panel Claims list ("Updated by") and a claim page's Logs.
 */
class PanelStaffNameVisibilityTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_names_follow_the_staff_visibility_rule_on_panel_payments_and_the_follow_up_list(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-VIS']);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $recorder = $this->officer();
        $this->selectBranch($recorder, $this->branch);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
        $claim = PanelClaim::query()->sole();
        $this->post(route('panel-claims.decision', $claim), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version,
            'decision' => 'accepted', 'accepted_amount_sen' => 3000, 'note' => 'Synthetic Panel decision',
        ])->assertSessionHasNoErrors();
        $this->post(route('panel-remittances.store'), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'panel' => $panel->code,
            'amount_sen' => 1000, 'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'reference' => null,
            'splits' => [['claim' => $claim->public_id, 'amount_sen' => 1000]],
        ])->assertSessionHasNoErrors();
        $this->travel(31)->days();

        $colleague = $this->actor('panel_officer');
        $finance = $this->actor('finance_officer');

        // Today's Panel Officer and Finance Officer roles see the organisation's staff directory: names show.
        $this->selectBranch($colleague, $this->branch);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->where('claims.data.0.lastActionBy', $recorder->name));
        $this->get(route('panel-remittances.index'))->assertInertia(fn (Assert $page) => $page->where('remittances.data.0.recordedBy', $recorder->name));

        // Without staff-directory access, a colleague's name reads "Staff member"; your own name still shows.
        foreach (['panel_officer', 'finance_officer'] as $role) {
            Role::findByName($role, 'web')->revokePermissionTo(['staff.view.organisation']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($colleague, $this->branch);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->where('claims.data.0.lastActionBy', 'Staff member'));
        $this->get(route('panel-remittances.index'))->assertInertia(fn (Assert $page) => $page->where('remittances.data.0.recordedBy', 'Staff member'));
        $this->selectBranch($finance, $this->branch);
        $this->get(route('panel-remittances.index'))->assertInertia(fn (Assert $page) => $page->where('remittances.data.0.recordedBy', 'Staff member'));
        $this->selectBranch($recorder, $this->branch);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->where('claims.data.0.lastActionBy', $recorder->name));
        $this->get(route('panel-remittances.index'))->assertInertia(fn (Assert $page) => $page->where('remittances.data.0.recordedBy', $recorder->name));

        // A branch-scoped staff view shows colleagues assigned to the same branch.
        Role::findByName('panel_officer', 'web')->givePermissionTo('staff.view.branch');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($colleague, $this->branch);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->where('claims.data.0.lastActionBy', $recorder->name));
        $this->get(route('panel-remittances.index'))->assertInertia(fn (Assert $page) => $page->where('remittances.data.0.recordedBy', $recorder->name));
    }

    public function test_names_follow_the_staff_visibility_rule_on_the_claims_list_and_claim_logs(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-LOGS']);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $recorder = $this->officer();
        $this->selectBranch($recorder, $this->branch);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
        $claim = PanelClaim::query()->sole();
        $colleague = $this->actor('panel_officer');

        $this->selectBranch($colleague, $this->branch);
        $this->get(route('panel-claims.index'))->assertInertia(fn (Assert $page) => $page->where('work.claims.data.0.updatedBy', $recorder->name));
        $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->where('claim.updatedBy', $recorder->name)->where('claim.events.0.actor', $recorder->name));

        Role::findByName('panel_officer', 'web')->revokePermissionTo(['staff.view.organisation']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->selectBranch($colleague, $this->branch);
        $this->get(route('panel-claims.index'))->assertInertia(fn (Assert $page) => $page->where('work.claims.data.0.updatedBy', 'Staff member'));
        $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->where('claim.updatedBy', 'Staff member')->where('claim.events.0.actor', 'Staff member')
            ->where('claim.events.1.actor', 'Staff member'));

        // Your own actions always show your name.
        $this->selectBranch($recorder, $this->branch);
        $this->get(route('panel-claims.index'))->assertInertia(fn (Assert $page) => $page->where('work.claims.data.0.updatedBy', $recorder->name));
        $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page->where('claim.events.0.actor', $recorder->name));
    }
}
