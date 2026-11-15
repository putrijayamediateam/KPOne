<?php

namespace Tests\Feature\Billing;

use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimAdjustment;
use App\Domain\Visit\Billing\Models\PanelClaimSnapshot;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Domain\Visit\Billing\Services\ExactMoney;
use App\Domain\Visit\Billing\Services\FinancialLedger;
use App\Domain\Visit\Billing\Services\PanelClaimAdjustmentService;
use App\Domain\Visit\Billing\Services\PanelShare;
use App\Domain\Visit\Models\Panel;
use App\Domain\Visit\Models\Visit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PCA-2 (design note v3.2, owner approved 2026-11-15): a Panel claim adjustment moves the invoice payable and the Panel
 * share together; what the patient owes never moves, anywhere. Example from the note: invoice RM 40, Panel RM 30, the
 * patient paid RM 10 cash; the Panel will pay RM 80, so the adjustment is +RM 50.
 */
class PanelClaimAdjustmentTest extends BillingTestCase
{
    private const SETTLED = ['total' => 4000, 'self_pay' => 1000, 'panel' => 3000, 'deferred' => 0, 'due_now' => 0];

    public function test_an_unclaimed_share_moves_invoice_and_panel_together_after_completion_and_the_patient_owes_nothing_more(): void
    {
        [$ca, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $adjustment = $this->request($officer, $invoice, $allocation, 'add', 5000);
        $this->assertSame(self::SETTLED, app(FinancialLedger::class)->state($invoice->refresh()), 'A waiting request changes nothing.');

        $this->confirm($officer, $adjustment);
        $this->assertSame(['total' => 9000, 'self_pay' => 1000, 'panel' => 8000, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($invoice->refresh()));
        $this->assertSame(8000, (int) DB::selectOne('SELECT kpone_panel_effective(?) AS v', [$invoice->id])->v);
        $this->assertSame(9000, (int) DB::selectOne('SELECT kpone_invoice_payable_effective(?) AS v', [$invoice->id])->v);
        $this->assertSame(4000, (int) DB::selectOne('SELECT kpone_invoice_payable(?) AS v', [$invoice->id])->v, 'The day-story payable is unchanged.');
        $this->assertSame(3000, $allocation->refresh()->amount_sen, 'The approved allocation row is never edited.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'panel_claim_adjustment.confirmed', 'subject_id' => $adjustment->id]);
        $this->assertTrue(DB::table('audit_logs')->where('event', 'panel_claim_adjustment.confirmed')->where('subject_id', $adjustment->id)->value('metadata') !== null);
        $this->assertSame(1, $invoice->refresh()->lock_version - $adjustment->invoice_version, 'Confirming bumps the invoice version.');
        $this->assertSame(0, app(FinancialLedger::class)->states(collect([$invoice]), withPanelAdjustments: false)[$invoice->id]['due_now']);
        $this->assertSame(3000, app(FinancialLedger::class)->states(collect([$invoice]), withPanelAdjustments: false)[$invoice->id]['panel'], 'Day-story reports keep the original share.');
        unset($ca, $visit);
    }

    public function test_an_undecided_claim_is_resynced_and_versioned_and_a_submitted_claim_says_tell_the_panel(): void
    {
        [, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($officer, $visit, $invoice, $allocation);
        $this->assertSame([1], PanelClaimSnapshot::query()->where('panel_claim_id', $claim->id)->pluck('version')->all());

        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'add', 5000));
        $claim->refresh();
        $this->assertSame(8000, $claim->claim_amount_sen);
        $this->assertSame('submitted', $claim->status);
        $this->assertSame(1, PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->count(), 'No supplementary claim for an undecided claim.');
        $latest = PanelClaimSnapshot::query()->where('panel_claim_id', $claim->id)->orderByDesc('version')->firstOrFail();
        $this->assertSame([2, 5000, 8000, 8000, 9000], [$latest->version, $latest->panel_adjustment_sen, $latest->panel_share_sen, $latest->claim_amount_sen, $latest->invoice_total_sen]);
        $this->assertDatabaseHas('panel_claim_events', ['panel_claim_id' => $claim->id, 'event_type' => 'panel_adjusted', 'previous_amount_sen' => 3000, 'amount_sen' => 8000, 'note' => 'Amount changed after submission: tell the Panel']);

        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'deduct', 2000));
        $this->assertSame(6000, $claim->refresh()->claim_amount_sen);
        $this->assertSame(3, (int) PanelClaimSnapshot::query()->where('panel_claim_id', $claim->id)->max('version'));
    }

    public function test_a_plus_on_a_decided_claim_creates_a_supplementary_claim_and_never_touches_the_original(): void
    {
        [, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($officer, $visit, $invoice, $allocation);
        $this->decide($officer, $visit, $claim, 'accepted', '3000');
        $before = DB::table('panel_claims')->where('id', $claim->id)->first();

        $adjustment = $this->request($officer, $invoice, $allocation, 'add', 5000);
        $this->confirm($officer, $adjustment);
        $this->assertEquals($before, DB::table('panel_claims')->where('id', $claim->id)->first(), 'The original claim, its decision and payments never change.');
        $supplementary = PanelClaim::query()->where('claim_kind', 'supplementary')->sole();
        $this->assertSame([$claim->id, $adjustment->id, 5000, 'prepared'], [$supplementary->parent_claim_id, $supplementary->panel_claim_adjustment_id, $supplementary->claim_amount_sen, $supplementary->status]);
        $this->assertSame(1, PanelClaimSnapshot::query()->where('panel_claim_id', $supplementary->id)->count());
        $this->assertSame(8000, PanelShare::effective($allocation->refresh()));
    }

    public function test_a_minus_on_a_decided_claim_cannot_go_below_what_the_panel_accepted_and_reduces_an_undecided_supplementary_first(): void
    {
        [, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($officer, $visit, $invoice, $allocation);
        $this->decide($officer, $visit, $claim, 'partially_accepted', '2000');
        $this->refused(fn () => $this->request($officer, $invoice, $allocation, 'deduct', 1500), 'amount_sen');
        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'deduct', 1000));
        $this->assertSame(2000, PanelShare::effective($allocation->refresh()));
        $this->assertSame(['total' => 3000, 'self_pay' => 1000, 'panel' => 2000, 'deferred' => 0, 'due_now' => 0], app(FinancialLedger::class)->state($invoice->refresh()));

        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'add', 4000));
        $supplementary = PanelClaim::query()->where('claim_kind', 'supplementary')->sole();
        $this->confirm($officer, $this->request($officer, $invoice, $allocation, 'deduct', 1500));
        $this->assertSame(2500, $supplementary->refresh()->claim_amount_sen, 'The undecided supplementary claim is reduced first.');
        $this->assertSame(4500, PanelShare::effective($allocation->refresh()));
    }

    public function test_refusals_below_zero_claim_only_excess_and_one_waiting_request(): void
    {
        [, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $this->refused(fn () => $this->request($officer, $invoice, $allocation, 'deduct', 3001), 'amount_sen');
        $claim = $this->createClaim($officer, $visit, $invoice, $allocation);
        $this->patch(route('panel-claims.amount', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version,
            'amount_sen' => '12000', 'reason' => 'Synthetic claim-only excess', 'confirm_over_invoice' => true])->assertSessionHasNoErrors();
        $this->refused(fn () => $this->request($officer, $invoice, $allocation, 'add', 5000), 'adjustment');

        $this->patch(route('panel-claims.amount', $claim), ['expected_branch_id' => $visit->branch_id, 'lock_version' => $claim->refresh()->lock_version,
            'amount_sen' => '3000', 'reason' => 'Synthetic back to share'])->assertSessionHasNoErrors();
        $waiting = $this->request($officer, $invoice, $allocation, 'add', 5000);
        $this->refused(fn () => $this->request($officer, $invoice, $allocation, 'add', 100), 'adjustment');
        $this->selectBranch($officer);
        app(PanelClaimAdjustmentService::class)->withdraw($officer, $waiting, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $waiting->lock_version]);
        $this->assertSame('withdrawn', $waiting->refresh()->status);
        $this->assertSame(self::SETTLED, app(FinancialLedger::class)->state($invoice->refresh()));
    }

    public function test_confirm_is_idempotent_and_refused_when_the_claim_was_decided_after_the_request(): void
    {
        [, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $claim = $this->createClaim($officer, $visit, $invoice, $allocation);
        $adjustment = $this->request($officer, $invoice, $allocation, 'add', 5000);
        $this->decide($officer, $visit, $claim, 'accepted', '3000');
        $this->refused(fn () => $this->confirm($officer, $adjustment), 'adjustment');

        $this->selectBranch($officer);
        app(PanelClaimAdjustmentService::class)->withdraw($officer, $adjustment, ['expected_branch_id' => $visit->branch_id, 'lock_version' => $adjustment->refresh()->lock_version]);
        $again = $this->request($officer, $invoice, $allocation, 'add', 5000);
        $key = (string) Str::uuid();
        $this->confirm($officer, $again, $key);
        $this->confirm($officer, $again->refresh(), $key);
        $this->assertSame(1, PanelClaim::query()->where('claim_kind', 'supplementary')->count(), 'A retried confirmation does nothing.');
    }

    public function test_only_request_and_confirm_holders_at_the_branch_may_act(): void
    {
        [$ca, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'administrator', 'technical_admin'] as $role) {
            $this->assertFalse($this->actor($role)->can(PanelClaimAdjustmentService::REQUEST_PERMISSION), $role);
        }
        foreach (['panel_officer', 'finance_officer', 'director'] as $role) {
            $actor = $this->actor($role);
            $this->assertTrue($actor->can(PanelClaimAdjustmentService::REQUEST_PERMISSION) && $actor->can(PanelClaimAdjustmentService::CONFIRM_PERMISSION), $role);
        }
        $this->expectException(AuthorizationException::class);
        $this->request($ca, $invoice, $allocation, 'add', 100);
        unset($officer, $visit);
    }

    public function test_postgres_keeps_adjustments_retained_and_refuses_a_confirmation_without_the_permission(): void
    {
        [$ca, $officer, $visit, $invoice, $allocation] = $this->completedPanelVisit();
        $adjustment = $this->request($officer, $invoice, $allocation, 'add', 5000);
        foreach ([
            fn () => DB::table('panel_claim_adjustments')->where('id', $adjustment->id)->update(['amount_sen' => 1]),
            fn () => DB::table('panel_claim_adjustments')->where('id', $adjustment->id)->delete(),
            fn () => DB::table('panel_claim_adjustments')->where('id', $adjustment->id)->update(['status' => 'confirmed', 'confirmed_by_user_id' => $ca->id,
                'confirmed_at' => now(), 'confirm_idempotency_key' => (string) Str::uuid(), 'lock_version' => 2]),
        ] as $statement) {
            DB::beginTransaction();
            try {
                $statement();
                DB::commit();
                $this->fail('PostgreSQL accepted a forbidden change.');
            } catch (QueryException $e) {
                DB::rollBack();
                $this->assertSame('23514', $e->getCode());
            }
        }
        $this->assertSame('requested', $adjustment->refresh()->status);
        unset($visit);
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

    private function refused(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail("Expected a refusal on {$field}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
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
