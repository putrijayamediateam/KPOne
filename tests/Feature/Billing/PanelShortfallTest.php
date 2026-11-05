<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * BIL-1b (owner decision 2026-11-05, Option B): the Billing page shows the part of the approved Panel share the
 * Panel did not accept, for information only. What the patient owes, the invoice, the allocation, receipts and
 * the claim stay exactly as they were.
 */
class PanelShortfallTest extends BillingTestCase
{
    private const SETTLED = ['total' => 4000, 'self_pay' => 1000, 'panel' => 3000, 'deferred' => 0, 'due_now' => 0];

    public function test_rejected_then_partially_accepted_claims_show_the_shortfall_without_changing_what_is_owed(): void
    {
        [$ca, $panelOfficer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $evidence = $this->evidence($invoice);
        $this->assertShortfall($ca, $visit, null);

        $claim = $this->createClaim($panelOfficer, $visit, $invoice, $allocation);
        $this->assertShortfall($ca, $visit, null); // Submitted, no decision yet.

        $this->decide($panelOfficer, $visit, $claim, 'rejected', '0', 'Synthetic rejection sentinel note');
        $this->assertShortfall($ca, $visit, ['shortfallSen' => 3000, 'panelShareSen' => 3000, 'acceptedSen' => 0, 'decision' => 'rejected']);
        $this->selectBranch($ca);
        $this->get(route('billing.show', $visit))->assertDontSee('Synthetic rejection sentinel note')
            ->assertInertia(fn (Assert $page) => $page->where('billing.can.pay', false)->where('billing.invoice.state', self::SETTLED));

        // Resubmitted: no decision is on record, so no warning.
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.submit', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertShortfall($ca, $visit, null);

        $this->decide($panelOfficer, $visit, $claim, 'partially_accepted', '2000', 'Synthetic partial note');
        $partial = ['shortfallSen' => 1000, 'panelShareSen' => 3000, 'acceptedSen' => 2000, 'decision' => 'partially_accepted'];
        $this->assertShortfall($ca, $visit, $partial);
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.payment', $claim), ['expected_branch_id' => $visit->branch_id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => '2000',
            'received_date' => now($this->branch->timezone)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $claim->refresh()->status);
        $this->assertShortfall($ca, $visit, $partial); // Still shown once paid.
        $this->assertShortfall($panelOfficer, $visit, $partial);
        $this->assertShortfall($this->actor('finance_officer'), $visit, $partial);
        $this->assertShortfall($this->actor('director'), $visit, $partial);

        $this->assertSame(ExactMoney::sen('1000'), $allocation->refresh()->amount_sen - $claim->accepted_amount_sen);
        $this->assertSame(self::SETTLED, app(FinancialLedger::class)->state($invoice->refresh()));
        $this->assertSame($evidence, $this->evidence($invoice));
    }

    public function test_a_fully_accepted_claim_shows_no_warning(): void
    {
        [$ca, $panelOfficer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $this->decide($panelOfficer, $visit, $this->createClaim($panelOfficer, $visit, $invoice, $allocation), 'accepted', '3000', 'Synthetic full acceptance');
        $this->assertShortfall($ca, $visit, null);
    }

    public function test_the_printed_invoice_never_carries_the_warning(): void
    {
        [$ca, $panelOfficer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $this->decide($panelOfficer, $visit, $this->createClaim($panelOfficer, $visit, $invoice, $allocation), 'rejected', '0', 'Synthetic rejection');
        $this->assertShortfall($ca, $visit, ['shortfallSen' => 3000, 'panelShareSen' => 3000, 'acceptedSen' => 0, 'decision' => 'rejected']);
        $this->selectBranch($ca);
        $this->get(route('billing.print', [$visit, $invoice]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.panelShortfall', null));
    }

    public function test_a_queried_claim_or_a_rejected_claim_reset_by_an_amount_edit_shows_no_warning(): void
    {
        [$ca, $panelOfficer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($panelOfficer, $visit, $invoice, $allocation);
        $this->post(route('panel-claims.query', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version, 'note' => 'Synthetic query'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('queried', $claim->refresh()->status);
        $this->assertShortfall($ca, $visit, null);

        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.submit', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version])->assertRedirect()->assertSessionHasNoErrors();
        $this->decide($panelOfficer, $visit, $claim, 'rejected', '0', 'Synthetic rejection');
        $this->assertShortfall($ca, $visit, ['shortfallSen' => 3000, 'panelShareSen' => 3000, 'acceptedSen' => 0, 'decision' => 'rejected']);
        $this->editAmount($panelOfficer, $visit, $claim, '2500');
        $this->assertSame('prepared', $claim->refresh()->status);
        $this->assertShortfall($ca, $visit, null);
    }

    public function test_a_claim_raised_above_the_share_and_accepted_at_or_above_it_shows_no_warning(): void
    {
        [$ca, $panelOfficer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($panelOfficer, $visit, $invoice, $allocation);
        $this->editAmount($panelOfficer, $visit, $claim, '3500');
        $this->decide($panelOfficer, $visit, $claim, 'partially_accepted', '3200', 'Synthetic partial above the share');
        $this->assertShortfall($ca, $visit, null); // RM 32 accepted covers the RM 30 share.
    }

    public function test_a_claim_raised_above_the_share_but_accepted_below_it_shows_the_gap_to_the_share(): void
    {
        [$ca, $panelOfficer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($panelOfficer, $visit, $invoice, $allocation);
        $this->editAmount($panelOfficer, $visit, $claim, '3500');
        $this->decide($panelOfficer, $visit, $claim, 'partially_accepted', '2400', 'Synthetic partial below the share');
        $this->assertShortfall($ca, $visit, ['shortfallSen' => 600, 'panelShareSen' => 3000, 'acceptedSen' => 2400, 'decision' => 'partially_accepted']);
    }

    public function test_a_claim_lowered_below_the_share_and_fully_accepted_is_explained_as_a_reduced_claim(): void
    {
        [$ca, $panelOfficer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($panelOfficer, $visit, $invoice, $allocation);
        $this->editAmount($panelOfficer, $visit, $claim, '2000');
        $this->decide($panelOfficer, $visit, $claim, 'accepted', '2000', 'Synthetic full acceptance of a reduced claim');
        $expected = ['shortfallSen' => 1000, 'panelShareSen' => 3000, 'acceptedSen' => 2000, 'decision' => 'claim_reduced'];
        $this->assertShortfall($ca, $visit, $expected);
        $this->selectBranch($ca);
        $this->get(route('billing.payments', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.panelShortfall', $expected));
    }

    public function test_a_self_pay_invoice_has_no_shortfall(): void
    {
        [, $ca, $visit] = $this->finalizedFixture();
        $this->assertShortfall($ca, $visit, null);
    }

    private function assertShortfall(User $viewer, Visit $visit, ?array $expected): void
    {
        $this->selectBranch($viewer);
        $this->get(route('billing.show', $visit))->assertOk()->assertInertia(fn (Assert $page) => $page->where('billing.panelShortfall', $expected));
    }

    /** @return array{User, User, Visit, Invoice, CoverageAllocation} RM 40 invoice: Panel RM 30 approved, patient paid RM 10, completed. */
    private function completedPanelVisit(): array
    {
        [, $ca, $visit, $invoice] = $this->finalizedFixture(ExactMoney::sen('4000'));
        (new PaymentMethod)->forceFill(['organisation_id' => $visit->organisation_id, 'code' => 'cash', 'name' => 'Synthetic cash'])->save();
        $panel = Panel::factory()->create(['organisation_id' => $visit->organisation_id]);
        $panelOfficer = $this->actor('panel_officer');
        DB::table('billing_approval_limits')->insert(['organisation_id' => $visit->organisation_id, 'branch_id' => $visit->branch_id, 'user_id' => $panelOfficer->id, 'capability' => 'panel', 'limit_sen' => 5000]);
        $this->selectBranch($ca);
        $this->post(route('billing.propose', [$visit, $invoice, 'panel']), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '3000',
            'panel_id' => $panel->id, 'reason' => 'Synthetic Panel responsibility'])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $allocation = CoverageAllocation::query()->where('invoice_id', $invoice->id)->sole();
        $this->selectBranch($panelOfficer);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', $allocation->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1000',
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])
            ->assertRedirect(route('billing.show', $visit))->assertSessionHasNoErrors();
        $this->assertSame(self::SETTLED, app(FinancialLedger::class)->state($invoice->refresh()));

        return [$ca, $panelOfficer, $visit->refresh(), $invoice, $allocation->refresh()];
    }

    private function createClaim(User $panelOfficer, Visit $visit, Invoice $invoice, CoverageAllocation $allocation): PanelClaim
    {
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.store', $allocation), ['expected_branch_id' => $visit->branch_id, 'allocation_lock_version' => $allocation->refresh()->lock_version,
            'invoice_lock_version' => $invoice->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => now($this->branch->timezone)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();

        return PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
    }

    private function editAmount(User $panelOfficer, Visit $visit, PanelClaim $claim, string $sen): void
    {
        $this->selectBranch($panelOfficer);
        $this->patch(route('panel-claims.amount', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version,
            'amount_sen' => $sen, 'reason' => 'Synthetic amount change', 'confirm_over_invoice' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame((int) $sen, $claim->refresh()->claim_amount_sen);
    }

    private function decide(User $panelOfficer, Visit $visit, PanelClaim $claim, string $decision, string $accepted, string $note): void
    {
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.decision', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version,
            'decision' => $decision, 'accepted_amount_sen' => $accepted, 'note' => $note])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame((int) $accepted, $claim->refresh()->accepted_amount_sen);
    }

    /** @return array<string, string> Row-for-row JSON of the invoice's financial evidence. */
    private function evidence(Invoice $invoice): array
    {
        $rows = ['invoices' => DB::table('invoices')->where('id', $invoice->id)->get()->toJson()];
        foreach (['invoice_lines', 'payment_allocations', 'payment_reversals', 'coverage_allocations', 'patient_receivables', 'invoice_adjustments'] as $table) {
            $rows[$table] = DB::table($table)->where('invoice_id', $invoice->id)->orderBy('id')->get()->toJson();
        }
        $rows['payments'] = DB::table('payments')->whereIn('id', DB::table('payment_allocations')->where('invoice_id', $invoice->id)->pluck('payment_id'))->orderBy('id')->get()->toJson();

        return $rows;
    }
}
