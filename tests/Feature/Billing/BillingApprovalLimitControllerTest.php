<?php

namespace Tests\Feature\Billing;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

class BillingApprovalLimitControllerTest extends ClinicalTestCase
{
    public function test_routes_are_restricted_to_director(): void
    {
        foreach (['ca_supervisor', 'ca', 'finance_officer', 'resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->actingAs($actor);

            $this->get(route('billing-approval-limits.index'))->assertForbidden();
            $this->post(route('billing-approval-limits.store'), [
                'expected_branch_id' => $this->branch->id,
                'user_id' => $actor->id,
                'capability' => 'panel',
                'limit_sen' => 1000,
            ])->assertForbidden();
        }
    }

    public function test_director_can_set_and_clear_branch_approval_limits(): void
    {
        $director = $this->actor('director');
        $ca = $this->actor('ca');
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($director, $this->branch);
        $this->actingAs($director);

        $this->get(route('billing-approval-limits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('BillingApprovalLimits/Index')
                ->where('branch.id', $this->branch->id)
                ->has('approvers', 3));

        $this->post(route('billing-approval-limits.store'), [
            'expected_branch_id' => $this->branch->id,
            'user_id' => $ca->id,
            'capability' => 'panel',
            'limit_sen' => 5500,
        ])->assertRedirect(route('billing-approval-limits.index'));

        $this->assertDatabaseHas('billing_approval_limits', [
            'organisation_id' => $this->organisation->id,
            'branch_id' => $this->branch->id,
            'user_id' => $ca->id,
            'capability' => 'panel',
            'limit_sen' => 5500,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'billing.approval_limit.set',
            'actor_user_id' => $director->id,
        ]);

        $this->post(route('billing-approval-limits.store'), [
            'expected_branch_id' => $this->branch->id,
            'user_id' => $supervisor->id,
            'capability' => 'deferment',
            'limit_sen' => 7000,
        ])->assertRedirect(route('billing-approval-limits.index'));

        $this->post(route('billing-approval-limits.clear'), [
            'expected_branch_id' => $this->branch->id,
            'user_id' => $ca->id,
            'capability' => 'panel',
        ])->assertRedirect(route('billing-approval-limits.index'));

        $this->assertDatabaseMissing('billing_approval_limits', [
            'organisation_id' => $this->organisation->id,
            'branch_id' => $this->branch->id,
            'user_id' => $ca->id,
            'capability' => 'panel',
        ]);
        $this->assertDatabaseHas('billing_approval_limits', [
            'organisation_id' => $this->organisation->id,
            'branch_id' => $this->branch->id,
            'user_id' => $supervisor->id,
            'capability' => 'deferment',
            'limit_sen' => 7000,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'billing.approval_limit.cleared',
            'actor_user_id' => $director->id,
        ]);
    }

    public function test_target_must_hold_matching_approval_permission(): void
    {
        $director = $this->actor('director');
        $technical = $this->actor('technical_admin');
        $this->selectBranch($director, $this->branch);
        $this->actingAs($director);

        $this->post(route('billing-approval-limits.store'), [
            'expected_branch_id' => $this->branch->id,
            'user_id' => $technical->id,
            'capability' => 'panel',
            'limit_sen' => 3000,
        ])->assertRedirect(route('billing-approval-limits.index'))
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseCount('billing_approval_limits', 0);

        $this->post(route('billing-approval-limits.store'), [
            'expected_branch_id' => $this->branch->id + 1,
            'user_id' => $director->id,
            'capability' => 'panel',
            'limit_sen' => 1000,
        ])->assertStatus(422);

        $this->assertDatabaseCount('billing_approval_limits', 0);
    }
}
