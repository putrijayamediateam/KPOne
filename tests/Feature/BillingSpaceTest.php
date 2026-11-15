<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Visit\VisitTestCase;

/** DS-5: the Billing space shows only what the person's own billing pages already show. */
class BillingSpaceTest extends VisitTestCase
{
    public function test_a_ca_sees_pay_later_cash_closing_awaiting_billing_and_the_primary_action(): void
    {
        $ca = $this->actor('ca');
        $this->selectBranch($ca);

        $this->get('/spaces/billing')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Spaces/Show')
            ->where('billing.payLater.count', 0)
            ->where('billing.payLater.totalText', 'RM 0.00')
            ->where('billing.payLater.rows', [])
            ->has('billing.cashClosing.statusText')
            ->where('billing.awaiting.total', 0)
            ->where('billing.primaryAction', ['title' => 'Open cash closing', 'href' => '/cash-closings']));
    }

    public function test_the_finance_officer_gets_the_same_widgets_and_the_director_a_primary_action(): void
    {
        $this->selectBranch($this->actor('finance_officer'));
        $this->get('/spaces/billing')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('billing.payLater')->has('billing.cashClosing'));

        $this->selectBranch($this->actor('director'));
        $this->get('/spaces/billing')->assertOk()->assertInertia(fn (Assert $p) => $p->has('billing.primaryAction'));
    }

    public function test_roles_without_billing_permissions_are_refused_and_other_spaces_carry_no_billing_data(): void
    {
        foreach (['resident_doctor', 'marketing', 'hr_manager'] as $role) {
            $this->selectBranch($this->actor($role));
            $this->get('/spaces/billing')->assertForbidden();
        }
        $this->selectBranch($this->actor('ca'));
        $this->get('/spaces/pharmacy')->assertInertia(fn (Assert $p) => $p->where('billing', null));
    }
}
