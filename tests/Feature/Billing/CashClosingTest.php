<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\CashClosing;
use App\Domain\Visit\Billing\Models\CashClosingDecision;
use App\Domain\Visit\Billing\Models\Payment;
use App\Domain\Visit\Billing\Models\PaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * BIL-4 (owner decisions 2026-11-05): daily cash closing. Takings only (float excluded); expected cash is the day's
 * posted Cash-classified receipts; a CA counts, a CA Supervisor, Finance Officer or Director approves, never the counter.
 */
class CashClosingTest extends ClinicalTestCase
{
    private CarbonImmutable $day;

    private int $patientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->day = CarbonImmutable::now($this->branch->timezone)->subDay()->startOfDay();
    }

    public static function viewers(): array
    {
        return [['ca', 200, 403], ['ca_supervisor', 200, 302], ['finance_officer', 200, 302], ['director', 200, 302],
            ['resident_doctor', 403, 403], ['panel_officer', 403, 403], ['administrator', 403, 403], ['technical_admin', 403, 403]];
    }

    #[DataProvider('viewers')]
    public function test_only_reconcilers_open_the_page_and_only_approvers_may_decide(string $role, int $page, int $decide): void
    {
        $actor = $this->actor($role);
        $this->selectBranch($actor);
        $this->get(route('cash-closings.index'))->assertStatus($page);
        $this->post(route('cash-closings.decide', [(string) Str::uuid(), 'approved']), [])->assertStatus($decide); // 302: allowed through to validation (empty request).
    }

    public function test_expected_cash_counts_only_posted_cash_receipts_of_this_branch_local_day_and_a_difference_needs_a_reason(): void
    {
        $ca = $this->actor('ca');
        $this->receipts($ca);
        $before = $this->financialRows();
        $this->selectBranch($ca);

        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Billing/CashClosings')
            ->where('cashClosing.expected', ['receiptCount' => 2, 'cashSen' => 3550])
            ->where('cashClosing.status', 'not_counted')->where('cashClosing.can', ['count' => true, 'approve' => false]));

        $key = (string) Str::uuid();
        $this->post(route('cash-closings.store'), $this->countInput('35.00', null, $key))
            ->assertRedirect(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertSessionHasErrors('variance_reason');
        $this->assertSame(0, CashClosing::query()->count());

        $input = $this->countInput('35.00', 'Synthetic: RM 0.50 short, coin given as change', $key);
        $this->post(route('cash-closings.store'), $input)->assertSessionHasNoErrors();
        $this->post(route('cash-closings.store'), $input)->assertSessionHasNoErrors(); // Same-key retry.
        $this->post(route('cash-closings.store'), $this->countInput('36.00', 'Synthetic other', $key))->assertSessionHasErrors('cash_closing');
        $closing = CashClosing::query()->sole();
        $this->assertSame([1, 2, 3550, 3500, -50, $ca->id], [$closing->revision, $closing->expected_receipt_count, $closing->expected_cash_sen, $closing->counted_cash_sen, $closing->variance_sen, $closing->counted_by_user_id]);
        $this->assertSame(['business_date' => $this->day->toDateString(), 'revision' => 1, 'expected_receipt_count' => 2, 'expected_cash_sen' => 3550, 'counted_cash_sen' => 3500, 'variance_sen' => -50],
            json_decode((string) DB::table('audit_logs')->where('event', 'billing.cash_counted')->sole()->metadata, true));
        $this->assertSame($before, $this->financialRows());

        // An exact count needs no reason; a future date is refused.
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors();
        $this->assertNull(CashClosing::query()->where('revision', 2)->value('variance_reason'));
        $this->post(route('cash-closings.store'), [...$this->countInput('0', null), 'business_date' => now($this->branch->timezone)->addDay()->toDateString()])->assertSessionHasErrors('business_date');
    }

    public function test_the_counter_cannot_approve_and_another_approver_closes_the_day_for_good(): void
    {
        $ca = $this->actor('ca');
        $this->receipts($ca);
        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors();
        $own = CashClosing::query()->sole();
        $this->post(route('cash-closings.decide', [$own->public_id, 'approved']), $this->decideInput())->assertSessionHasErrors('cash_closing');
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.closings.0.canDecide', false)->where('cashClosing.closings.0.countedByYou', true));
        $this->assertSame(0, CashClosingDecision::query()->count());

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.status', 'waiting')->where('cashClosing.closings.0.canDecide', true));
        $input = $this->decideInput();
        $this->post(route('cash-closings.decide', [$own->public_id, 'approved']), $input)->assertSessionHasNoErrors();
        $this->post(route('cash-closings.decide', [$own->public_id, 'approved']), $input)->assertSessionHasNoErrors(); // Same-key retry.
        $this->assertSame([['approved', $finance->id]], CashClosingDecision::query()->get()->map(fn ($d) => [$d->decision, $d->decided_by_user_id])->all());
        $this->assertDatabaseHas('audit_logs', ['event' => 'billing.cash_closing_approved', 'subject_id' => $own->id, 'actor_user_id' => $finance->id]);

        $this->selectBranch($ca);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.status', 'approved')->where('cashClosing.can.count', false)->where('cashClosing.closings.0.state', 'approved'));
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasErrors('cash_closing');
        $this->assertSame(1, CashClosing::query()->count());

        // The evidence cannot be changed or removed, by the model or by PostgreSQL.
        $this->assertThrows(fn () => $own->forceFill(['counted_cash_sen' => 1])->save(), LogicException::class);
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('cash_closings')->where('id', $own->id)->update(['counted_cash_sen' => 1]));
        $this->assertDatabaseRefuses('P0001', fn () => DB::table('cash_closing_decisions')->delete());
        $this->assertDatabaseRefuses('P0001', fn () => DB::statement('TRUNCATE cash_closing_decisions'));
        $this->assertDatabaseRefuses('P0001', fn () => DB::statement('TRUNCATE cash_closings CASCADE'));
        $this->assertDatabaseRefuses('23514', fn () => DB::table('cash_closings')->insert($this->rawClosing($own, 2)), 'already has an approved cash closing');
    }

    public function test_postgres_refuses_a_decision_by_the_counter_or_on_an_older_count_even_without_the_service(): void
    {
        $ca = $this->actor('ca');
        $this->receipts($ca);
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors();
        $first = CashClosing::query()->sole();
        $this->assertDatabaseRefuses('23514', fn () => DB::table('cash_closing_decisions')->insert($this->rawDecision($first, $ca->id)), 'cannot decide');

        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors();
        $this->assertDatabaseRefuses('23514', fn () => DB::table('cash_closing_decisions')->insert($this->rawDecision($first, $this->actor('director')->id)), 'Only the latest');
        $this->assertSame(0, CashClosingDecision::query()->count());
    }

    public function test_today_can_be_returned_but_approved_only_after_the_day_ends(): void
    {
        $ca = $this->actor('ca');
        $this->day = CarbonImmutable::now($this->branch->timezone)->startOfDay();
        $this->receipts($ca);
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors();
        $closing = CashClosing::query()->sole();

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->get(route('cash-closings.index'))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.dayEnded', false)->where('cashClosing.closings.0.canDecide', true)->where('cashClosing.closings.0.canApproveNow', false));
        $this->post(route('cash-closings.decide', [$closing->public_id, 'approved']), $this->decideInput())->assertSessionHasErrors('cash_closing');
        $this->assertSame(0, CashClosingDecision::query()->count());

        $this->travelTo($this->day->addDay()->addMinute()); // Just after branch-local midnight.
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page->where('cashClosing.closings.0.canApproveNow', true));
        $this->post(route('cash-closings.decide', [$closing->public_id, 'approved']), $this->decideInput())->assertSessionHasNoErrors();
        $this->assertSame('approved', CashClosingDecision::query()->sole()->decision);
    }

    public function test_a_returned_count_is_counted_again_and_only_the_latest_count_can_be_decided(): void
    {
        $ca = $this->actor('ca');
        $this->receipts($ca);
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput('30.00', 'Synthetic: RM 5.50 not found yet'))->assertSessionHasNoErrors();
        $first = CashClosing::query()->sole();

        $director = $this->actor('director');
        $this->selectBranch($director);
        $this->post(route('cash-closings.decide', [$first->public_id, 'returned']), $this->decideInput())->assertSessionHasErrors('reason');
        $this->post(route('cash-closings.decide', [$first->public_id, 'returned']), $this->decideInput('Synthetic: please count the coins again'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'billing.cash_closing_returned', 'subject_id' => $first->id]);

        $this->selectBranch($ca);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.status', 'returned')->where('cashClosing.can.count', true));
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors();
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors(); // A second count replaces the first pending one.
        [$second, $third] = CashClosing::query()->where('revision', '>', 1)->orderBy('revision')->get()->all();

        $this->selectBranch($director);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.closings', fn ($rows) => collect($rows)->map(fn ($r) => [$r['revision'], $r['state'], $r['canDecide']])->all() === [[3, 'waiting', true], [2, 'replaced', false], [1, 'returned', false]]));
        $this->post(route('cash-closings.decide', [$second->public_id, 'approved']), $this->decideInput())->assertSessionHasErrors('cash_closing');
        // A request key already used for the return cannot be reused for another decision.
        $usedKey = CashClosingDecision::query()->sole()->idempotency_key;
        $this->post(route('cash-closings.decide', [$third->public_id, 'approved']), [...$this->decideInput(), 'idempotency_key' => $usedKey])->assertSessionHasErrors('cash_closing');
        $this->post(route('cash-closings.decide', [$third->public_id, 'approved']), $this->decideInput())->assertSessionHasNoErrors();
        $this->assertSame([[$first->id, 'returned'], [$third->id, 'approved']], CashClosingDecision::query()->orderBy('id')->get()->map(fn ($d) => [$d->cash_closing_id, $d->decision])->all());
    }

    public function test_approval_is_refused_when_cash_receipts_changed_after_the_count_and_other_branches_cannot_see_it(): void
    {
        $ca = $this->actor('ca');
        $cash = $this->receipts($ca);
        $this->selectBranch($ca);
        $this->post(route('cash-closings.store'), $this->countInput('35.50', null))->assertSessionHasNoErrors();
        $closing = CashClosing::query()->sole();
        $this->payment($ca, $cash, 700, $this->day->setTime(18, 0)->utc()); // Recorded late, for the same day.

        $supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($supervisor);
        $this->post(route('cash-closings.decide', [$closing->public_id, 'approved']), $this->decideInput())->assertSessionHasErrors('cash_closing');
        $this->assertSame(0, CashClosingDecision::query()->count());

        $puchong = Branch::query()->where('organisation_id', $this->branch->organisation_id)->where('code', 'PUCHONG')->firstOrFail();
        $other = $this->actor('ca_supervisor', $puchong);
        $this->selectBranch($other, $puchong);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.closings', [])->where('cashClosing.expected', ['receiptCount' => 1, 'cashSen' => 11000])); // Puchong's own receipt only.
        $this->post(route('cash-closings.decide', [$closing->public_id, 'returned']), [...$this->decideInput('Synthetic'), 'expected_branch_id' => $puchong->id])->assertNotFound();

        $org = new Organisation;
        $org->forceFill(['code' => 'UAT_FOREIGN', 'name' => 'Synthetic foreign organisation', 'is_active' => true])->save();
        $foreignBranch = new Branch;
        $foreignBranch->forceFill(['organisation_id' => $org->id, 'code' => 'UAT_FOREIGN', 'name' => 'Synthetic foreign branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true])->save();
        $foreign = $this->actor('director', $foreignBranch);
        $this->selectBranch($foreign, $foreignBranch);
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page->where('cashClosing.closings', []));
        $this->post(route('cash-closings.decide', [$closing->public_id, 'approved']), [...$this->decideInput(), 'expected_branch_id' => $foreignBranch->id])->assertNotFound();

        $this->selectBranch($supervisor);
        $this->post(route('cash-closings.decide', [$closing->public_id, 'returned']), $this->decideInput('Synthetic: a late cash receipt was added'))->assertSessionHasNoErrors();
        $this->post(route('cash-closings.store'), [...$this->countInput('42.50', null)])->assertSessionHasNoErrors();
        $this->selectBranch($this->actor('finance_officer'));
        $this->post(route('cash-closings.decide', [CashClosing::query()->where('revision', 2)->value('public_id'), 'approved']), $this->decideInput())->assertSessionHasNoErrors();

        // A receipt recorded for that day after approval: the approved snapshot is kept and the page flags the change.
        $this->payment($ca, $cash, 100, $this->day->setTime(19, 0)->utc());
        $this->get(route('cash-closings.index', ['date' => $this->day->toDateString()]))->assertInertia(fn (Assert $page) => $page
            ->where('cashClosing.status', 'approved')->where('cashClosing.changedSinceApproval', true)->where('cashClosing.closings.0.expectedCashSen', 4250));
    }

    /** Cash receipts for the day: RM 10.00 and RM 25.50 posted; excluded: a reversed one, a card one, the day before, the day after, another branch. */
    private function receipts(User $actor): PaymentMethod
    {
        $this->patientId = $this->patient($actor->hasRole('director') ? $actor : $this->actor('director'))->id;
        $cash = $this->method($actor, 'CASH', 'cash');
        $card = $this->method($actor, 'CARD', 'non_cash');
        $start = $this->day->utc();
        $end = $this->day->addDay()->utc();
        $this->payment($actor, $cash, 1000, $start->addSecond());
        $this->payment($actor, $cash, 2550, $end->subSecond());
        $this->payment($actor, $cash, 9000, $start->addMinute(), 'reversed');
        $this->payment($actor, $card, 4000, $start->addMinute());
        $this->payment($actor, $cash, 5000, $start->subSecond());
        $this->payment($actor, $cash, 7000, $end);
        $puchong = Branch::query()->where('organisation_id', $this->branch->organisation_id)->where('code', 'PUCHONG')->firstOrFail();
        $this->payment($actor, $cash, 11000, $start->addMinute(), 'posted', $puchong->id);

        return $cash;
    }

    private function method(User $actor, string $code, string $classification): PaymentMethod
    {
        $method = new PaymentMethod;
        $method->forceFill(['organisation_id' => $actor->organisation_id, 'code' => $code, 'name' => 'Synthetic '.$code, 'is_active' => true, 'classification' => $classification])->save();

        return $method;
    }

    private function payment(User $actor, PaymentMethod $method, int $sen, CarbonImmutable $at, string $status = 'posted', ?int $branchId = null): void
    {
        (new Payment)->forceFill(['public_id' => (string) Str::uuid(), 'organisation_id' => $actor->organisation_id, 'branch_id' => $branchId ?? $this->branch->id,
            'patient_id' => $this->patientId, 'payment_method_id' => $method->id, 'receipt_number' => 'SYN-'.Str::upper(Str::random(12)), 'amount_sen' => $sen,
            'currency' => 'MYR', 'method_snapshot' => $method->name, 'status' => $status, 'reference' => null, 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', (string) Str::uuid()), 'received_at' => $at, 'recorded_by_user_id' => $actor->id, 'lock_version' => 1])->save();
    }

    /** @return array<string, mixed> */
    private function countInput(string $counted, ?string $reason, ?string $key = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'business_date' => $this->day->toDateString(), 'counted_cash' => $counted,
            'variance_reason' => $reason, 'idempotency_key' => $key ?? (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function decideInput(?string $reason = null): array
    {
        return ['expected_branch_id' => $this->branch->id, 'business_date' => $this->day->toDateString(), 'reason' => $reason, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function rawClosing(CashClosing $closing, int $revision): array
    {
        return collect($closing->getAttributes())->except(['id', 'public_id', 'revision', 'idempotency_key'])->all()
            + ['public_id' => (string) Str::uuid(), 'revision' => $revision, 'idempotency_key' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function rawDecision(CashClosing $closing, int $userId): array
    {
        return ['organisation_id' => $closing->organisation_id, 'cash_closing_id' => $closing->id, 'decision' => 'approved', 'reason' => null,
            'decided_by_user_id' => $userId, 'decided_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('0', 64)];
    }

    /** The statement must fail in PostgreSQL with this SQLSTATE (and message part), not for any other SQL reason. */
    private function assertDatabaseRefuses(string $sqlState, callable $statement, ?string $message = null): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail('PostgreSQL accepted a statement it must refuse.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0] ?? null, $exception->getMessage());
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    /** @return array<string, string> */
    private function financialRows(): array
    {
        return collect(['payments', 'payment_allocations', 'payment_reversals', 'invoices', 'payment_reconciliations'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
