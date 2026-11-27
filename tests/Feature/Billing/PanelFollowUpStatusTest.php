<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelClaimFollowUpService;
use App\Domain\Visit\Models\Panel;
use App\Http\Middleware\RouteIsolatedThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class PanelFollowUpStatusTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_six_exact_states_count_before_filter_and_preserve_submission_history_and_money_labels(): void
    {
        $this->withoutMiddleware(RouteIsolatedThrottle::class);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $expected = [];
        foreach (PanelClaimFollowUpService::OPEN as $state) {
            $claim = $this->claim($panel);
            if (in_array($state, ['prepared', 'queried'], true)) {
                $this->post(route('panel-claims.query', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version, 'note' => 'Synthetic query'])->assertSessionHasNoErrors();
                if ($state === 'prepared') {
                    $this->patch(route('panel-claims.amount', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version, 'amount_sen' => 3100, 'reason' => 'Synthetic corrected amount'])->assertSessionHasNoErrors();
                }
            } elseif (in_array($state, ['accepted', 'partially_accepted', 'partially_paid'], true)) {
                $this->decide($claim, $state === 'partially_accepted' ? 'partially_accepted' : 'accepted', $state === 'partially_accepted' ? 2501 : 3000);
                if ($state === 'partially_paid') {
                    $this->pay($claim, 1001);
                }
            }
            $this->assertSame($state, $claim->refresh()->status);
            $expected[$state] = $claim->public_id;
        }
        $this->claim($panel, false);
        $paid = $this->claim($panel);
        $this->decide($paid, 'accepted', 3000);
        $this->pay($paid, 3000);
        $rejected = $this->claim($panel);
        $this->decide($rejected, 'rejected', 0);
        $this->travel(31)->days();
        $counts = array_fill_keys(PanelClaimFollowUpService::OPEN, 1);
        foreach ($expected as $state => $id) {
            $this->get(route('panel-claims.follow-up', ['status' => $state]))->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('filters.status', $state)->where('statusCounts', $counts)->where('claims.total', 1)->has('claims.data', 1)->where('claims.data.0.publicId', $id));
        }
        $this->get(route('panel-claims.follow-up', ['status' => 'prepared']))->assertInertia(fn (Assert $p) => $p->where('claims.data.0.status', 'Prepared after earlier submission')->where('claims.data.0.amountLabel', 'Claimed, awaiting decision')->where('claims.data.0.amountSen', 3100));
        $this->get(route('panel-claims.follow-up', ['status' => 'partially_paid']))->assertInertia(fn (Assert $p) => $p->where('claims.data.0.amountLabel', 'Unpaid')->where('claims.data.0.amountSen', 1999));
    }

    public function test_panel_intersection_full_counts_and_page_two_keep_query_count_constant(): void
    {
        $this->withoutMiddleware(RouteIsolatedThrottle::class);
        $start = CarbonImmutable::now()->startOfSecond();
        $this->travelTo($start);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->claim($panel);
        $this->travelTo($start->addDays(31));
        $service = app(PanelClaimFollowUpService::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->page($this->officer(), ['panel' => $panel->code, 'status' => 'submitted']);
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->travelTo($start);
        for ($i = 0; $i < 20; $i++) {
            $this->claim($panel);
        }
        $other = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->claim($other);
        $this->travelTo($start->addDays(31));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $service->page($this->officer(), ['panel' => $panel->code, 'status' => 'submitted'], 2);
        $this->assertSame($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertSame(21, $result['statusCounts']['submitted']);
        $this->assertSame(21, $result['claims']['total']);
        $this->assertCount(1, $result['claims']['data']);
        $this->assertSame(2, $result['claims']['currentPage']);
        $empty = $service->page($this->officer(), ['panel' => $panel->code, 'status' => 'queried']);
        $this->assertSame(21, $empty['statusCounts']['submitted']);
        $this->assertSame(0, $empty['statusCounts']['queried']);
        $this->assertSame(0, $empty['claims']['total']);
    }

    public function test_reversal_restarts_existing_action_clock_and_preserves_exact_unpaid_amount(): void
    {
        $this->freezeSecond();
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $claim = $this->claim($panel);
        $this->decide($claim, 'accepted', 3000);
        $this->pay($claim, 1001);
        $this->travel(30)->days();
        $this->get(route('panel-claims.follow-up', ['status' => 'partially_paid']))->assertInertia(fn (Assert $p) => $p->where('statusCounts.partially_paid', 1)->where('claims.total', 1));
        $payment = DB::table('panel_claim_payments')->where('panel_claim_id', $claim->id)->sole();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->post(route('panel-payment-corrections.store'), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'kind' => 'payment', 'target' => $payment->public_id, 'reason_code' => 'wrong_amount', 'note' => 'Synthetic correction', 'recording_error_only' => true])->assertSessionHasNoErrors();
        $this->get(route('panel-claims.follow-up', ['status' => 'accepted']))->assertInertia(fn (Assert $p) => $p->where('statusCounts.accepted', 0)->where('claims.total', 0));
        $this->travel(30)->days();
        $this->get(route('panel-claims.follow-up', ['status' => 'accepted']))->assertInertia(fn (Assert $p) => $p->where('statusCounts.accepted', 1)->where('claims.data.0.lastAction', 'Payment reversed')->where('claims.data.0.amountSen', 3000));
    }

    public function test_invalid_status_is_rejected_and_branch_and_role_boundaries_remain(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $this->claim($panel);
        $this->travel(31)->days();
        foreach (['paid', 'cancelled', 'unknown', ['submitted'], ['key' => 'submitted']] as $bad) {
            $this->getJson(route('panel-claims.follow-up', ['status' => $bad]))->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $other = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->sole();
        $actor = $this->actor('panel_officer', $other);
        $this->selectBranch($actor, $other);
        $this->get(route('panel-claims.follow-up', ['status' => 'submitted']))->assertInertia(fn (Assert $p) => $p->where('statusCounts', array_fill_keys(PanelClaimFollowUpService::OPEN, 0))->has('claims.data', 0));
        $this->selectBranch($this->actor('ca'), $this->branch);
        $this->get(route('panel-claims.follow-up', ['status' => 'submitted']))->assertForbidden();
    }

    private function claim(Panel $panel, bool $submit = true): PanelClaim
    {
        [$allocation] = $this->approvedAllocation($panel);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.prepare', $allocation), ['expected_branch_id' => $this->branch->id])->assertSessionHasNoErrors();
        $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
        if ($submit) {
            $this->post(route('panel-claims.submit', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->lock_version])->assertSessionHasNoErrors();
        }

        return $claim->refresh();
    }

    private function decide(PanelClaim $claim, string $decision, int $amount): void
    {
        $this->post(route('panel-claims.decision', $claim), ['expected_branch_id' => $this->branch->id, 'lock_version' => $claim->refresh()->lock_version, 'decision' => $decision, 'accepted_amount_sen' => $amount, 'note' => 'Synthetic decision'])->assertSessionHasNoErrors();
    }

    private function pay(PanelClaim $claim, int $amount): void
    {
        $this->post(route('panel-claims.payment', $claim), ['expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => $amount, 'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString()])->assertSessionHasNoErrors();
    }
}
