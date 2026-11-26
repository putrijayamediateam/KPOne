<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelWaitingPaymentProjection;
use App\Domain\Visit\Models\Panel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class PanelWaitingPaymentTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_actual_partial_payment_and_reversal_change_waiting_balances_without_claimed_amount_change(): void
    {
        $claim = $this->acceptedClaim(2501);
        $this->pay($claim, 1001);
        $this->get(route('panel-claims.index', ['tab' => 'waiting_payment']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.waiting_payment', 1)->where('work.tabs.approved', 1)
            ->where('work.waitingPayment', ['count' => 1, 'acceptedSen' => 2501, 'receivedSen' => 1001, 'outstandingSen' => 1500])
            ->where('work.claims.data.0.claimAmountSen', 3000)
            ->where('work.claims.data.0.paymentBalance', ['acceptedSen' => 2501, 'receivedSen' => 1001, 'outstandingSen' => 1500]));
        $this->pay($claim, 1500);
        $this->get(route('panel-claims.index', ['tab' => 'waiting_payment']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.waitingPayment', ['count' => 0, 'acceptedSen' => 0, 'receivedSen' => 0, 'outstandingSen' => 0])->has('work.claims.data', 0));
        $payment = DB::table('panel_claim_payments')->where('amount_sen', 1001)->sole();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->post(route('panel-payment-corrections.store'), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(),
            'kind' => 'payment', 'target' => $payment->public_id, 'reason_code' => 'wrong_amount',
            'note' => 'Synthetic reversal proof', 'recording_error_only' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('panel-claims.index', ['tab' => 'waiting_payment']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.tabs.waiting_payment', 1)->where('work.waitingPayment.receivedSen', 1500)
            ->where('work.waitingPayment.outstandingSen', 1001));
        $this->assertSame(3000, $claim->refresh()->claim_amount_sen);
    }

    public function test_filters_and_current_branch_authority_apply_before_matching_totals(): void
    {
        $claim = $this->acceptedClaim(2000);
        foreach ([['amount_min' => '30.01'], ['panel' => 'SYN-NO-MATCH'], ['name' => 'SYN-NO-MATCH'], ['range' => 'custom', 'from' => '2000-01-01', 'to' => '2000-01-02']] as $filters) {
            $this->get(route('panel-claims.index', ['tab' => 'waiting_payment', ...$filters]))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('work.tabs.waiting_payment', 0)->where('work.waitingPayment.count', 0)->where('work.waitingPayment.outstandingSen', 0)->has('work.claims.data', 0));
        }
        $director = $this->actor('director');
        $this->selectBranch($director, $this->branch);
        $this->get(route('panel-claims.index', ['tab' => 'waiting_payment', 'claim_no' => $claim->id, 'amount_min' => '30.00']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.waitingPayment.count', 1)->where('work.can.recordRemittances', false));
        $other = Branch::query()->where('code', 'PUCHONG')->where('organisation_id', $this->organisation->id)->sole();
        $this->selectBranch($this->actor('panel_officer', $other), $other);
        $this->get(route('panel-claims.index', ['tab' => 'waiting_payment']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.waitingPayment.count', 0)->has('work.claims.data', 0));
        $this->selectBranch($this->actor('ca'), $this->branch);
        $this->get(route('panel-claims.index', ['tab' => 'waiting_payment']))->assertForbidden();
    }

    public function test_projection_has_no_two_hundred_row_cap_and_one_statement_supplies_empty_clamped_and_full_totals(): void
    {
        $claim = $this->acceptedClaim(2501);
        // A temporary read fixture deliberately avoids manufacturing 201 clinical workflows. Production financial
        // transitions are exercised above; this table copies the real claim column shape, without modifying it.
        DB::statement('CREATE TEMP TABLE waiting_claim_fixture ON COMMIT DROP AS SELECT * FROM panel_claims WHERE false');
        $seed = DB::table('panel_claims')->where('id', $claim->id)->first();
        $rows = [];
        for ($i = 1; $i <= 201; $i++) {
            $rows[] = [...(array) $seed, 'id' => 100000 + $i, 'public_id' => (string) Str::uuid()];
        }
        foreach (['prepared', 'submitted', 'queried', 'rejected', 'paid', 'cancelled'] as $offset => $status) {
            $rows[] = [...(array) $seed, 'id' => 800000 + $offset, 'public_id' => (string) Str::uuid(), 'status' => $status];
        }
        $rows[] = [...(array) $seed, 'id' => 810001, 'public_id' => (string) Str::uuid(), 'accepted_amount_sen' => 0];
        $rows[] = [...(array) $seed, 'id' => 810002, 'public_id' => (string) Str::uuid(), 'accepted_amount_sen' => null];
        $rows[] = [...(array) $seed, 'id' => 810003, 'public_id' => (string) Str::uuid(), 'organisation_id' => 900001];
        $rows[] = [...(array) $seed, 'id' => 810004, 'public_id' => (string) Str::uuid(), 'branch_id' => 900001];
        DB::table('waiting_claim_fixture')->insert($rows);
        $base = fn () => PanelClaim::query()->from('waiting_claim_fixture as panel_claims')->where('organisation_id', $this->organisation->id)->where('branch_id', $this->branch->id);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $read = app(PanelWaitingPaymentProjection::class)->read($base(), 999);
        $this->assertCount(1, DB::getQueryLog());
        DB::flushQueryLog();
        $single = app(PanelWaitingPaymentProjection::class)->read($base()->where('id', 100001), 1);
        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame(1, $single['totals']['count']);
        DB::disableQueryLog();
        $this->assertSame(['count' => 201, 'acceptedSen' => 502701, 'receivedSen' => 0, 'outstandingSen' => 502701], $read['totals']);
        $this->assertSame(11, $read['claims']->currentPage());
        $this->assertCount(1, $read['claims']->items());
        $this->assertSame(100001, $read['claims']->items()[0]->id);
        $first = app(PanelWaitingPaymentProjection::class)->read($base(), 1);
        $this->assertCount(20, $first['claims']->items());
        $this->assertSame($read['totals'], $first['totals']);
        $none = app(PanelWaitingPaymentProjection::class)->read($base()->where('status', 'queried'), 9);
        $this->assertSame(['count' => 0, 'acceptedSen' => 0, 'receivedSen' => 0, 'outstandingSen' => 0], $none['totals']);
        $this->assertSame(1, $none['claims']->currentPage());
    }

    public function test_post_statement_insert_cannot_mix_current_rows_and_matching_totals(): void
    {
        $claim = $this->acceptedClaim(2501);
        DB::statement('CREATE TEMP TABLE waiting_snapshot_fixture ON COMMIT DROP AS SELECT * FROM panel_claims');
        $seed = (array) DB::table('panel_claims')->where('id', $claim->id)->sole();
        $inserted = false;
        DB::listen(function (QueryExecuted $event) use (&$inserted, $seed): void {
            if (! $inserted && str_starts_with($event->sql, 'WITH balances AS MATERIALIZED') && str_contains($event->sql, 'waiting_snapshot_fixture')) {
                $inserted = true;
                // Deterministic interleaving immediately after the read statement, before its PHP mapping.
                DB::table('waiting_snapshot_fixture')->insert([...$seed, 'id' => 900001, 'public_id' => (string) Str::uuid()]);
            }
        });
        $base = fn () => PanelClaim::query()->from('waiting_snapshot_fixture as panel_claims')->where('organisation_id', $this->organisation->id)->where('branch_id', $this->branch->id);
        $first = app(PanelWaitingPaymentProjection::class)->read($base(), 1);
        $this->assertTrue($inserted);
        $this->assertSame(1, $first['totals']['count']);
        $this->assertSame(2501, $first['totals']['outstandingSen']);
        $this->assertCount(1, $first['claims']->items());
        $next = app(PanelWaitingPaymentProjection::class)->read($base(), 1);
        $this->assertSame(2, $next['totals']['count']);
        $this->assertSame(5002, $next['totals']['outstandingSen']);
        $this->assertCount(2, $next['claims']->items());
    }

    private function acceptedClaim(int $accepted): PanelClaim
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id, 'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version, 'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
        $this->post(route('panel-claims.decision', $claim), [
            'expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version,
            'decision' => $accepted === 3000 ? 'accepted' : 'partially_accepted', 'accepted_amount_sen' => $accepted,
            'note' => 'Synthetic accepted amount',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return $claim->refresh();
    }

    private function pay(PanelClaim $claim, int $amount): void
    {
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.payment', $claim), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => $amount,
            'received_date' => now()->setTimezone($this->branch->timezone)->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
    }
}
