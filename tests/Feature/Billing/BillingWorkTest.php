<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Services\ResponsibilityService;
use App\Domain\Visit\Models\Panel;
use Inertia\Testing\AssertableInertia as Assert;

class BillingWorkTest extends BillingTestCase
{
    public function test_panel_navigation_lists_only_current_responsibility_and_reuses_secured_review(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panelActor = $this->actor('panel_officer');
        $this->selectBranch($panelActor);
        $this->get(route('clinic.panel-claims'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('work.data', 0));
        $this->selectBranch($ca);
        app(ResponsibilityService::class)->propose($ca, $visit, $invoice, 'deferment', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 1000, 'due_date' => now()->addDay()->toDateString(), 'reason' => 'Synthetic private reason']);
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        app(ResponsibilityService::class)->propose($ca, $visit, $invoice->refresh(), 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 3000, 'reason' => 'Synthetic coverage', 'panel_id' => $panel->id, 'member_reference' => 'SYNTHETIC-PRIVATE']);
        $this->selectBranch($panelActor);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('workspace.navigation.panelWork', true)->where('workspace.navigation.financeWork', false)->where('workspace.navigation.registration', false));
        $response = $this->get(route('clinic.panel-claims'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('FinancialWork/Index')->has('work.data', 1)
            ->where('work.data.0.amountSen', 3000)->where('work.data.0.status', 'proposed')->where('work.data.0.reviewUrl', route('billing.show', $visit))
            ->missing('work.data.0.state')->missing('work.data.0.reason')->missing('work.data.0.dueDate')->missing('work.data.0.lines'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSee('Synthetic private reason')->assertDontSee('SYNTHETIC-PRIVATE');
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billing.invoice.lines', [])->where('billing.deferment', null)->where('billing.can.pay', false)->where('billing.can.complete', false));
        $this->post(route('billing.payment', [$visit, $invoice]), [])->assertForbidden();
        $this->post(route('billing.complete', $visit), [])->assertForbidden();
        $this->get(route('billing.work'))->assertForbidden();
    }

    public function test_finance_navigation_has_financial_summary_only_and_no_new_operational_authority(): void
    {
        [, , $visit, $invoice] = $this->finalizedFixture();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('workspace.navigation.financeWork', true)->where('workspace.navigation.registration', false)->where('workspace.navigation.consultation', false));
        $this->get(route('billing.work'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('FinancialWork/Index')->has('work.data', 1)->where('work.data.0.state.total', 4000)->where('work.data.0.state.due_now', 4000)->where('work.data.0.reviewUrl', route('billing.show', $visit))->missing('work.data.0.lines')->missing('work.data.0.payments')->missing('work.data.0.reason'));
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $p) => $p->where('billing.invoice.lines', [])->where('billing.can.complete', false));
        foreach (['visits.complete.branch', 'encounters.view.own', 'dispensary.complete.branch'] as $permission) {
            $this->assertFalse($finance->can($permission));
        }
        $this->post(route('billing.complete', $visit), [])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'lock_version' => $invoice->lock_version]);
    }

    public function test_work_queues_do_not_trust_browser_branch_scope_and_deny_non_financial_actors(): void
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        app(ResponsibilityService::class)->propose($ca, $visit, $invoice, 'panel', ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->lock_version, 'amount_sen' => 4000, 'reason' => 'Synthetic coverage', 'panel_id' => $panel->id]);
        $this->get(route('clinic.panel-claims'))->assertInertia(fn (Assert $p) => $p->has('work.data', 1));
        foreach (['technical_admin', 'resident_doctor'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor);
            $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('workspace.navigation.panelWork', false)->where('workspace.navigation.financeWork', false));
            $this->get(route('billing.work'))->assertForbidden();
            $this->post(route('billing.complete', $visit), [])->assertForbidden();
            if ($role === 'technical_admin') {
                $this->get(route('clinic.panel-claims'))->assertForbidden();
            } else {
                $this->get(route('clinic.panel-claims'))->assertInertia(fn (Assert $p) => $p->component('Clinic/Placeholder')->missing('work'));
            }
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('id', '<>', $this->branch->id)->firstOrFail();
        $org = new Organisation;
        $org->forceFill(['code' => 'SYN-OTHER', 'name' => 'Synthetic other', 'is_active' => true])->save();
        $foreign = new Branch;
        $foreign->forceFill(['organisation_id' => $org->id, 'code' => 'SYN-OTHER', 'name' => 'Synthetic other', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        foreach ([$other, $foreign] as $branch) {
            foreach (['finance_officer', 'panel_officer'] as $role) {
                $actor = $this->actor($role, $branch);
                $this->selectBranch($actor, $branch);
                $route = $role === 'finance_officer' ? 'billing.work' : 'clinic.panel-claims';
                $this->get(route($route, ['branch_id' => $visit->branch_id, 'organisation_id' => $visit->organisation_id, 'scope' => 'organisation']))->assertOk()->assertInertia(fn (Assert $p) => $p->has('work.data', 0));
                $this->get(route('billing.show', $visit))->assertNotFound();
            }
        }
    }
}
