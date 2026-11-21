<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\PanelClaimAdjustmentService;
use App\Domain\Visit\Billing\Services\PanelClaimPackIssueService;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PNL-25 with PCA-2: a supplementary claim that a later minus adjustment cancelled stays in the pack with its own status
 * and its own figures (RM 20.00, cancelled), but it was never submitted and is counted in no total.
 */
class PanelClaimPackCancelledTest extends BillingTestCase
{
    private const SETTLED = ['total' => 4000, 'self_pay' => 1000, 'panel' => 3000, 'deferred' => 0, 'due_now' => 0];

    public function test_a_cancelled_supplementary_claim_is_in_the_pack_with_its_own_figures_but_in_no_total(): void
    {
        [, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($officer, $visit, $invoice, $allocation);
        $this->decide($officer, $visit, $claim, 'accepted', '3000');
        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'add', 2000));
        $supplementary = PanelClaim::query()->where('claim_kind', 'supplementary')->sole();
        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'deduct', 2000));
        $this->assertSame('cancelled', $supplementary->refresh()->status);

        $this->selectBranch($officer);
        $shown = app(PanelClaimPackIssueService::class)->show($officer, app(PanelClaimPackIssueService::class)->issue($officer, [
            'claims' => [$claim->public_id, $supplementary->public_id], 'idempotency_key' => (string) Str::uuid()])->public_id);

        $this->assertSame(2, $shown['pack']['claimCount']);
        $this->assertSame('Cancelled', $shown['pack']['claims'][1]['status']);
        $this->assertSame(2000, $shown['pack']['claims'][1]['claimAmountSen']);
        // A supplementary claim asks only for its own adjustment: no invoice figures, no lines.
        $this->assertSame(2000, $shown['pack']['claims'][1]['invoiceTotalSen']);
        $this->assertSame([], $shown['pack']['claims'][1]['lines']);
        $this->assertSame(3000, $shown['pack']['totalClaimSen']);
    }

    private function request(User $actor, Invoice $invoice, CoverageAllocation $allocation, string $direction, int $sen): PanelClaimAdjustment
    {
        $this->selectBranch($actor);

        return app(PanelClaimAdjustmentService::class)->request($actor, $allocation, [
            'expected_branch_id' => $invoice->branch_id, 'invoice_lock_version' => $invoice->refresh()->lock_version, 'direction' => $direction,
            'amount_sen' => (string) $sen, 'reason_code' => 'panel_service_charge', 'note' => 'Synthetic private note', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function confirm(User $actor, PanelClaimAdjustment $adjustment, ?string $key = null): void
    {
        $this->selectBranch($actor);
        app(PanelClaimAdjustmentService::class)->confirm($actor, $adjustment, [
            'expected_branch_id' => $adjustment->branch_id, 'lock_version' => $adjustment->lock_version, 'idempotency_key' => $key ?? (string) Str::uuid(),
        ]);
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
            'panel_id' => $panel->id, 'reason' => 'Synthetic Panel responsibility'])->assertSessionHasNoErrors();
        $allocation = CoverageAllocation::query()->where('invoice_id', $invoice->id)->sole();
        $this->selectBranch($panelOfficer);
        $this->post(route('billing.approve', [$visit, $invoice, 'panel', $allocation->public_id]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version,
            'proposal_lock_version' => $allocation->lock_version])->assertSessionHasNoErrors();
        $this->selectBranch($ca);
        $this->post(route('billing.payment', [$visit, $invoice]), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'amount_sen' => '1000',
            'method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post(route('billing.complete', $visit), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $invoice->refresh()->lock_version, 'visit_lock_version' => $visit->refresh()->lock_version])
            ->assertSessionHasNoErrors();
        $this->assertSame(self::SETTLED, app(FinancialLedger::class)->state($invoice->refresh()));

        return [$ca, $panelOfficer, $visit->refresh(), $invoice, $allocation->refresh()];
    }

    private function createClaim(User $panelOfficer, Visit $visit, Invoice $invoice, CoverageAllocation $allocation): PanelClaim
    {
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.store', $allocation), ['expected_branch_id' => $visit->branch_id, 'allocation_lock_version' => $allocation->refresh()->lock_version,
            'invoice_lock_version' => $invoice->refresh()->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => now($this->branch->timezone)->toDateString()])->assertSessionHasNoErrors();

        return PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->where('claim_kind', 'original')->sole();
    }

    private function decide(User $panelOfficer, Visit $visit, PanelClaim $claim, string $decision, string $accepted): void
    {
        $this->selectBranch($panelOfficer);
        $this->post(route('panel-claims.decision', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version,
            'decision' => $decision, 'accepted_amount_sen' => $accepted, 'note' => 'Synthetic decision'])->assertSessionHasNoErrors();
    }
}
