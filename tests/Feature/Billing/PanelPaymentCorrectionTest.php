<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\BillingPermissions;
use App\Domain\Access\StaffAccessService;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelClaimPaymentReversal;
use App\Domain\Visit\Billing\Models\PanelRemittance;
use App\Domain\Visit\Billing\Services\PanelPaymentCorrectionService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/**
 * PNL-6 (owner decision 2026-11-05): a wrongly recorded Panel payment is reversed, never edited, and never by the
 * person who recorded it. A split Panel payment is reversed whole. Every figure that adds up claim payments then
 * leaves the reversed payment out.
 */
class PanelPaymentCorrectionTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_finance_officer_reverses_a_single_claim_payment_and_the_claim_is_unpaid_again(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $this->pay($claim, 3000, 'SYNTH-WRONG-0001');
        $this->assertSame('paid', $claim->refresh()->status);
        $payment = DB::table('panel_claim_payments')->sole();

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->get(route('panel-invoices.index'))->assertInertia(fn (Assert $page) => $page->where('can.reversePayments', true));
        $this->get(route('panel-payment-corrections.index'))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('PanelPaymentCorrections/Index')
                ->has('payments.data', 1)
                ->where('payments.data.0.kind', 'payment')
                ->where('payments.data.0.publicId', $payment->public_id)
                ->where('payments.data.0.amountSen', 3000)
                ->where('payments.data.0.recordedByMe', false)
                ->where('payments.data.0.reversal', null)
                ->has('reasons', 6));

        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', (string) $payment->public_id, ['note' => '  Entered the full amount; the Panel paid RM 10  ']))
            ->assertRedirect(route('panel-payment-corrections.index'))->assertSessionHasNoErrors();

        $reversal = PanelClaimPaymentReversal::query()->sole();
        $this->assertSame(3000, $reversal->amount_sen);
        $this->assertSame($finance->id, $reversal->reversed_by_user_id);
        $this->assertSame('Entered the full amount; the Panel paid RM 10', $reversal->note);
        $this->assertSame('accepted', $claim->refresh()->status);
        $this->assertSame(1, DB::table('panel_claim_payments')->count(), 'The payment itself is kept.');
        $audit = json_decode((string) DB::table('audit_logs')->where('event', 'panel_claim.payment_reversed')->sole()->metadata, true);
        $this->assertSame(['amount_sen' => 3000, 'reason_code' => 'wrong_amount'], array_intersect_key($audit, ['amount_sen' => 1, 'reason_code' => 1]));
        $this->assertStringNotContainsString('Entered the full amount', (string) DB::table('audit_logs')->pluck('metadata')->implode(' '));

        $this->get(route('panel-payment-corrections.index'))->assertInertia(fn (Assert $page) => $page
            ->where('payments.data.0.reversal.reason', 'Wrong amount')
            ->where('payments.data.0.reversal.reversedBy', $finance->name)
            // The recorder's name follows the staff-directory visibility rule (BIL-2), otherwise "Staff member".
            ->where('payments.data.0.recordedBy', app(StaffAccessService::class)->canView($finance, $this->officer()) ? $this->officer()->name : 'Staff member'));

        // The Panel Officer sees it struck out on the claim page, and the claim can be paid again correctly.
        $this->selectBranch($this->officer(), $this->branch);
        $this->get(route('panel-claims.show', $claim))->assertInertia(fn (Assert $page) => $page
            ->has('claim.payments', 1)->where('claim.payments.0.reversed', true)
            ->where('claim.paidAmountSen', 0)->where('claim.outstandingSen', 3000));
        $this->pay($claim, 1000);
        $this->assertSame('partially_paid', $claim->refresh()->status);
        $this->get(route('panel-remittances.index', ['panel' => $panel->code]))->assertInertia(fn (Assert $page) => $page
            ->where('openClaims.0.receivedSen', 1000)->where('openClaims.0.outstandingSen', 2000));
    }

    public function test_a_split_panel_payment_is_reversed_with_all_its_splits(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$full, $partial] = $this->decidedClaims($panel, [3000, 2500]);
        $this->pay($partial, 500);
        $this->travel(1)->minutes();
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-remittances.store'), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'panel' => $panel->code,
            'amount_sen' => 4000, 'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'reference' => 'SYNTH-SPLIT',
            'splits' => [['claim' => $full->public_id, 'amount_sen' => 3000], ['claim' => $partial->public_id, 'amount_sen' => 1000]],
        ])->assertSessionHasNoErrors();
        $remittance = PanelRemittance::query()->sole();
        $split = DB::table('panel_claim_payments')->where('panel_remittance_id', $remittance->id)->orderBy('id')->first();

        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->get(route('panel-payment-corrections.index'))->assertInertia(fn (Assert $page) => $page
            ->has('payments.data', 2)->where('payments.data.0.kind', 'remittance')->has('payments.data.0.claims', 2));

        // One split on its own cannot be reversed: the Panel payment would no longer add up.
        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', (string) $split->public_id))
            ->assertSessionHasErrors(['reversal' => 'This payment is part of a split Panel payment. Reverse the whole Panel payment instead.']);
        $this->post(route('panel-payment-corrections.store'), $this->reversal('remittance', strtoupper($remittance->public_id), ['reason_code' => 'wrong_claim']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PanelClaimPaymentReversal::query()->where('panel_remittance_id', $remittance->id)->count());
        $this->assertSame('accepted', $full->refresh()->status);
        $this->assertSame('partially_paid', $partial->refresh()->status, 'The separate RM 5.00 payment still counts.');
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'panel_claim.payment_reversed')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_remittance.reversed')->count());
        $this->post(route('panel-payment-corrections.store'), $this->reversal('remittance', $remittance->public_id))
            ->assertSessionHasErrors(['reversal' => 'This payment has already been reversed.']);

        $this->get(route('panel-remittances.index', ['panel' => $panel->code]))->assertInertia(fn (Assert $page) => $page
            ->where('remittances.data.0.reversed', true)->where('canReverse', true)
            ->has('openClaims', 2)->where('openClaims.1.outstandingSen', 2000));
    }

    public function test_the_person_who_recorded_a_payment_cannot_reverse_it_and_the_director_can(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->post(route('panel-remittances.store'), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'panel' => $panel->code,
            'amount_sen' => 3000, 'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'reference' => null,
            'splits' => [['claim' => $claim->public_id, 'amount_sen' => 3000]],
        ])->assertSessionHasNoErrors();
        $remittance = PanelRemittance::query()->sole();

        $this->get(route('panel-payment-corrections.index'))->assertInertia(fn (Assert $page) => $page->where('payments.data.0.recordedByMe', true));
        $this->post(route('panel-payment-corrections.store'), $this->reversal('remittance', $remittance->public_id))
            ->assertSessionHasErrors(['reversal' => 'You recorded this payment, so someone else must reverse it.']);
        $this->assertSame(0, PanelClaimPaymentReversal::query()->count());

        $director = $this->actor('director');
        $this->selectBranch($director, $this->branch);
        $this->post(route('panel-payment-corrections.store'), $this->reversal('remittance', $remittance->public_id, ['reason_code' => 'not_received']))
            ->assertSessionHasNoErrors();
        $this->assertSame('accepted', $claim->refresh()->status);
    }

    public function test_a_retry_reverses_once_changed_details_are_refused_and_input_is_checked(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $this->pay($claim, 2000);
        $payment = (string) DB::table('panel_claim_payments')->value('public_id');
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);

        foreach ([
            ['reason_code' => ''], ['reason_code' => 'typo'], ['note' => '   '], ['note' => str_repeat('a', 201)],
            ['recording_error_only' => false], ['kind' => 'invoice'], ['target' => 'not-a-uuid'],
        ] as $bad) {
            $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', $payment, $bad))->assertSessionHasErrors();
        }
        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', (string) Str::uuid()))
            ->assertSessionHasErrors(['reversal' => 'This payment is not recorded at this branch. Reload the page.']);
        $this->assertSame(0, PanelClaimPaymentReversal::query()->count());
        // Reversals are limited to 10 a minute per person; start a fresh minute.
        $this->travel(61)->seconds();

        $request = $this->reversal('payment', $payment, ['reason_code' => 'recorded_twice']);
        $this->post(route('panel-payment-corrections.store'), $request)->assertSessionHasNoErrors();
        $this->post(route('panel-payment-corrections.store'), $request)->assertSessionHasNoErrors();
        $this->post(route('panel-payment-corrections.store'), [...$request, 'note' => 'Something else'])
            ->assertSessionHasErrors(['reversal' => 'This correction was already made with different details. Reload the page.']);
        $this->assertSame(1, PanelClaimPaymentReversal::query()->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'panel_claim.payment_reversed')->count());
        $this->assertSame('accepted', $claim->refresh()->status);
    }

    public function test_only_finance_officers_and_directors_at_the_payments_branch_may_reverse(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $this->pay($claim, 3000);
        $payment = (string) DB::table('panel_claim_payments')->value('public_id');

        foreach (['panel_officer', 'ca', 'ca_supervisor', 'resident_doctor', 'administrator', 'technical_admin', 'hr_manager'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->get(route('panel-payment-corrections.index'))->assertForbidden();
            $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', $payment))->assertForbidden();
        }
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->actor('finance_officer', $otherBranch);
        $this->selectBranch($elsewhere, $otherBranch);
        $this->get(route('panel-payment-corrections.index'))->assertInertia(fn (Assert $page) => $page->has('payments.data', 0));
        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', $payment, ['expected_branch_id' => $otherBranch->id]))
            ->assertSessionHasErrors(['reversal' => 'This payment is not recorded at this branch. Reload the page.']);
        $this->assertSame(0, PanelClaimPaymentReversal::query()->count());
        $this->assertSame('paid', $claim->refresh()->status);

        $holders = collect(BillingPermissions::roles())->filter(fn (array $permissions): bool => in_array(PanelPaymentCorrectionService::PERMISSION, $permissions, true))->keys()->sort()->values()->all();
        $this->assertSame(['director', 'finance_officer'], $holders);
    }

    public function test_a_partially_accepted_claim_goes_back_and_one_of_two_payments_can_be_reversed(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$partial, $twice] = $this->decidedClaims($panel, [2500, 3000]);
        $this->pay($partial, 2500);
        $this->pay($twice, 1000);
        $this->pay($twice, 1000);
        $this->assertSame(['paid', 'partially_paid'], [$partial->refresh()->status, $twice->refresh()->status]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);

        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', (string) DB::table('panel_claim_payments')
            ->where('panel_claim_id', $partial->id)->value('public_id')))->assertSessionHasNoErrors();
        $this->assertSame('partially_accepted', $partial->refresh()->status);
        $this->assertSame(2500, $partial->accepted_amount_sen);

        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', (string) DB::table('panel_claim_payments')
            ->where('panel_claim_id', $twice->id)->orderByDesc('id')->value('public_id'), ['reason_code' => 'recorded_twice']))->assertSessionHasNoErrors();
        $this->assertSame('partially_paid', $twice->refresh()->status, 'The first RM 10.00 still counts.');
        $this->selectBranch($this->officer(), $this->branch);
        $this->get(route('panel-claims.show', $twice))->assertInertia(fn (Assert $page) => $page
            ->where('claim.paidAmountSen', 1000)->where('claim.outstandingSen', 2000)
            ->where('claim.payments.0.reversed', false)->where('claim.payments.1.reversed', true));
    }

    public function test_the_permission_migration_is_additive_and_replay_safe(): void
    {
        foreach (['finance_officer', 'director', 'panel_officer', 'ca_supervisor', 'administrator'] as $role) {
            $actor = $this->actor($role);
            $this->assertSame(in_array($role, ['finance_officer', 'director'], true), $actor->can(PanelPaymentCorrectionService::PERMISSION), $role);
        }
        $before = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $migration = require base_path('database/migrations/2026_11_06_300200_grant_panel_payment_reversal_permission_additively.php');
        $migration->up();
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());

        // A database that never received the grant gets it, and nothing else changes.
        $permission = DB::table('permissions')->where('name', PanelPaymentCorrectionService::PERMISSION)->value('id');
        DB::table('role_has_permissions')->where('permission_id', $permission)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $migration->up();
        $this->assertSame($before, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
    }

    public function test_insights_and_the_panel_summary_leave_a_reversed_payment_out_in_its_original_period(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $this->pay($claim, 3000);
        [$second] = $this->decidedClaims($panel, [3000]);
        $this->pay($second, 1000);
        $wrong = (string) DB::table('panel_claim_payments')->where('panel_claim_id', $claim->id)->value('public_id');
        $date = CarbonImmutable::now($this->branch->timezone)->toDateString();
        $director = $this->actor('director');
        $this->selectBranch($director, $this->branch);
        $insights = fn () => $this->get(route('insights.report', ['section' => 'payments', 'branch' => $this->branch->code, 'from' => $date, 'to' => $date]));
        $summary = function (): array {
            $report = $this->postJson(route('reports.panel-summary.run'), [
                'expected_branch_id' => $this->branch->id,
                'from' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
                'to' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            ])->assertOk()->json('result.totals');

            return is_array($report) ? $report : [];
        };

        $insights()->assertInertia(fn (Assert $page) => $page->where('data.report.summary.panelRemittancesReceivedSen', 4000)
            ->where('data.report.summary.panelClaimsOutstandingSen', 2000));
        $before = $summary();

        $this->travel(2)->days();
        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', $wrong))->assertSessionHasNoErrors();
        $this->travel(-2)->days();

        $insights()->assertInertia(fn (Assert $page) => $page->where('data.report.summary.panelRemittancesReceivedSen', 1000)
            ->where('data.report.summary.panelClaimsOutstandingSen', 5000));
        $after = $summary();
        $remitted = collect(array_keys($before))->first(fn (string $column): bool => stripos($column, 'remitted') !== false);
        $this->assertIsString($remitted);
        $this->assertSame(['40.00', '10.00'], [$before[$remitted], $after[$remitted]]);
    }

    public function test_a_reversal_restarts_the_follow_up_clock(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $this->pay($claim, 3000);
        $payment = (string) DB::table('panel_claim_payments')->value('public_id');
        $this->travel(10)->days();
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);
        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', $payment))->assertSessionHasNoErrors();

        $this->travel(29)->days();
        $this->selectBranch($this->officer(), $this->branch);
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page->has('claims.data', 0));
        $this->travel(2)->days();
        $this->get(route('panel-claims.follow-up'))->assertInertia(fn (Assert $page) => $page
            ->has('claims.data', 1)->where('claims.data.0.lastAction', 'Payment reversed')
            ->where('claims.data.0.lastActionBy', $finance->name)->where('claims.data.0.amountSen', 3000)
            ->where('claims.data.0.status', 'Accepted, unpaid'));
    }

    public function test_postgresql_keeps_reversals_append_only_whole_and_by_a_different_person(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL rules only.');
        }
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim, $other] = $this->decidedClaims($panel, [3000, 3000]);
        $this->pay($claim, 3000);
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-remittances.store'), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'panel' => $panel->code,
            'amount_sen' => 2000, 'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'reference' => null,
            'splits' => [['claim' => $other->public_id, 'amount_sen' => 2000]],
        ])->assertSessionHasNoErrors();
        $payment = DB::table('panel_claim_payments')->where('panel_claim_id', $claim->id)->sole();
        $split = DB::table('panel_claim_payments')->where('panel_claim_id', $other->id)->sole();
        $finance = $this->actor('finance_officer');

        $row = fn (object $target, array $overrides = []): array => [
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
            'panel_claim_id' => $target->panel_claim_id, 'panel_claim_payment_id' => $target->id, 'panel_remittance_id' => $target->panel_remittance_id,
            'amount_sen' => $target->amount_sen, 'reason_code' => 'other', 'note' => 'Synthetic', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('a', 64), 'reversed_at' => now(), 'reversed_by_user_id' => $finance->id,
            'created_at' => now(), 'updated_at' => now(), ...$overrides,
        ];
        $this->assertRefusedBy(fn () => DB::table('panel_claim_payment_reversals')->insert($row($payment, ['amount_sen' => 1000])), 'must match its payment');
        $this->assertRefusedBy(fn () => DB::table('panel_claim_payment_reversals')->insert($row($payment, ['reversed_by_user_id' => $this->officer()->id])), 'different person');
        $this->assertRefusedBy(fn () => DB::table('panel_claim_payment_reversals')->insert($row($payment, ['reason_code' => 'because'])), 'panel_claim_payment_reversals_shape');
        // The claim is still marked paid, so a reversal row alone is refused at commit.
        $this->assertRefusedBy(function () use ($row, $payment): void {
            DB::table('panel_claim_payment_reversals')->insert($row($payment));
            DB::statement('SET CONSTRAINTS panel_claim_payment_reversals_complete IMMEDIATE');
        }, 'status must match');

        $this->selectBranch($finance, $this->branch);
        $this->post(route('panel-payment-corrections.store'), $this->reversal('payment', (string) $payment->public_id))->assertSessionHasNoErrors();
        $id = PanelClaimPaymentReversal::query()->sole()->id;
        $this->assertRefusedBy(fn () => DB::table('panel_claim_payment_reversals')->where('id', $id)->update(['note' => 'changed']), 'immutable');
        $this->assertRefusedBy(fn () => DB::table('panel_claim_payment_reversals')->where('id', $id)->delete(), 'immutable');
        $this->assertRefusedBy(function (): void {
            // Fire the deferred checks of this test's open transaction first, so TRUNCATE reaches the trigger.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('TRUNCATE panel_claim_payment_reversals');
        }, 'immutable');
        $this->assertRefusedBy(fn () => DB::table('panel_claim_payment_reversals')->insert($row($payment)), 'unique');

        // A second split added to the Panel payment means reversing only one split leaves it unbalanced.
        $this->assertRefusedBy(function () use ($row, $split, $other): void {
            DB::table('panel_claims')->where('id', $other->id)->update(['status' => 'accepted', 'lock_version' => DB::raw('lock_version + 1')]);
            DB::table('panel_claim_payments')->insert([
                'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
                'panel_claim_id' => $other->id, 'panel_remittance_id' => $split->panel_remittance_id, 'amount_sen' => 1, 'currency' => 'MYR',
                'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64), 'received_at' => now(),
                'recorded_by_user_id' => $this->officer()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('panel_claim_payment_reversals')->insert($row($split));
            DB::statement('SET CONSTRAINTS panel_claim_payment_reversals_complete IMMEDIATE');
        }, 'reversed with all of its splits');
    }

    private function assertRefusedBy(callable $action, string $message): void
    {
        DB::beginTransaction();
        try {
            $action();
            $this->fail('Expected PostgreSQL to refuse: '.$message);
        } catch (QueryException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function reversal(string $kind, string $target, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $this->branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'kind' => $kind,
            'target' => $target,
            'reason_code' => 'wrong_amount',
            'note' => 'Synthetic recording mistake',
            'recording_error_only' => true,
            ...$overrides,
        ];
    }

    private function pay(PanelClaim $claim, int $amount, ?string $reference = null): void
    {
        $this->selectBranch($this->officer(), $this->branch);
        $this->post(route('panel-claims.payment', $claim), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => $amount,
            'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'reference' => $reference,
        ])->assertSessionHasNoErrors();
    }

    /**
     * One Submitted claim per amount with the Panel's decision recorded (the RM 30.00 claim amount is accepted in
     * full, a smaller amount partially).
     *
     * @param  list<int>  $acceptedAmounts
     * @return list<PanelClaim>
     */
    private function decidedClaims(Panel $panel, array $acceptedAmounts): array
    {
        $claims = [];
        foreach ($acceptedAmounts as $accepted) {
            [$allocation, $invoice] = $this->approvedAllocation($panel);
            $this->selectBranch($this->officer(), $this->branch);
            $this->post(route('panel-claims.store', $allocation), [
                'expected_branch_id' => $this->branch->id,
                'allocation_lock_version' => $allocation->lock_version,
                'invoice_lock_version' => $invoice->lock_version,
                'idempotency_key' => (string) Str::uuid(),
                'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            ])->assertSessionHasNoErrors();
            $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
            $this->post(route('panel-claims.decision', $claim), [
                'expected_branch_id' => $this->branch->id,
                'lock_version' => $claim->lock_version,
                'decision' => $accepted === $claim->claim_amount_sen ? 'accepted' : 'partially_accepted',
                'accepted_amount_sen' => $accepted,
                'note' => 'Synthetic Panel decision',
            ])->assertSessionHasNoErrors();
            $claims[] = $claim->refresh();
        }

        return $claims;
    }
}
