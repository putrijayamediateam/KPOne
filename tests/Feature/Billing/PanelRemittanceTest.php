<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\BillingPermissions;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Models\PanelRemittance;
use App\Domain\Visit\Billing\Services\PanelRemittanceService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PNL-3: one Panel payment split across several accepted claims of that Panel.
 */
class PanelRemittanceTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    public function test_panel_officer_records_one_payment_split_across_accepted_claims(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$full, $partial] = $this->decidedClaims($panel, [3000, 2500]);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->get(route('panel-remittances.index', ['panel' => $panel->code]))->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('PanelRemittances/Index')
                ->has('openClaims', 2)
                ->where('openClaims.0.publicId', $full->public_id)
                ->where('openClaims.0.outstandingSen', 3000)
                ->where('openClaims.1.outstandingSen', 2500)
                ->missing('openClaims.0.diagnoses'));

        $payload = $this->payload($panel, 4000, [[$full, 3000], [$partial, 1000]], ['reference' => 'SYNTH-REMIT-0001']);
        $this->post(route('panel-remittances.store'), $payload)
            ->assertRedirect(route('panel-remittances.index', ['panel' => $panel->code]))
            ->assertSessionHasNoErrors();

        $remittance = PanelRemittance::query()->sole();
        $this->assertSame(4000, $remittance->amount_sen);
        $this->assertSame($panel->id, $remittance->panel_id);
        $this->assertSame('SYNTH-REMIT-0001', $remittance->reference);
        $this->assertSame([3000, 1000], DB::table('panel_claim_payments')->where('panel_remittance_id', $remittance->id)
            ->orderBy('panel_claim_id')->pluck('amount_sen')->map(fn ($v) => (int) $v)->all());
        $this->assertSame('paid', $full->refresh()->status);
        $this->assertSame('partially_paid', $partial->refresh()->status);
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'panel_claim.payment_received')->count());
        $audit = DB::table('audit_logs')->where('event', 'panel_remittance.recorded')->sole();
        $this->assertSame(['amount_sen' => 4000, 'claim_count' => 2], array_intersect_key(json_decode((string) $audit->metadata, true), ['amount_sen' => 1, 'claim_count' => 1]));
        $this->assertStringNotContainsString('SYNTH-REMIT', (string) DB::table('audit_logs')->pluck('metadata')->implode(' '));

        // The split payments are ordinary claim payments: the claim page shows them and the open list shrinks.
        $this->get(route('panel-claims.show', $partial))->assertInertia(fn (Assert $page) => $page->has('claim.payments', 1));
        $this->get(route('panel-remittances.index', ['panel' => $panel->code]))->assertInertia(fn (Assert $page) => $page
            ->has('openClaims', 1)->where('openClaims.0.outstandingSen', 1500)
            ->where('remittances.data.0.amountSen', 4000)->has('remittances.data.0.splits', 2));
    }

    public function test_finance_officer_may_record_a_split_payment_without_panel_claims_access(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $finance = $this->actor('finance_officer');
        $this->selectBranch($finance, $this->branch);

        $this->get(route('panel-claims.index'))->assertForbidden();
        $this->get(route('panel-invoices.index'))->assertInertia(fn (Assert $page) => $page->where('can.recordRemittances', true));
        $this->get(route('panel-remittances.index', ['panel' => $panel->code]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('openClaims', 1));
        $this->post(route('panel-remittances.store'), $this->payload($panel, 3000, [[$claim, 3000]]))->assertSessionHasNoErrors();

        $this->assertSame('paid', $claim->refresh()->status);
        $this->assertSame($finance->id, PanelRemittance::query()->sole()->recorded_by_user_id);
    }

    public function test_the_split_must_equal_the_payment_and_stay_within_each_claims_unpaid_amount(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$first, $second] = $this->decidedClaims($panel, [3000, 2500]);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->post(route('panel-remittances.store'), $this->payload($panel, 5000, [[$first, 3000], [$second, 1000]]))
            ->assertSessionHasErrors(['splits' => 'The split adds up to RM 40.00, but the payment is RM 50.00. They must be equal.']);
        $this->post(route('panel-remittances.store'), $this->payload($panel, 5600, [[$first, 3100], [$second, 2500]]))
            ->assertSessionHasErrors(['splits' => 'Claim '.$first->id.': RM 31.00 is more than the RM 30.00 still unpaid.']);
        $this->post(route('panel-remittances.store'), $this->payload($panel, 3000, [[$first, 3000], [$second, 0]]))
            ->assertSessionHasErrors('splits.1.amount_sen');
        $this->assertNothingRecorded();
    }

    public function test_claims_of_another_panel_or_without_an_accepted_amount_are_refused(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $other = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$ours] = $this->decidedClaims($panel, [3000]);
        [$theirs] = $this->decidedClaims($other, [3000]);
        [$submitted] = $this->decidedClaims($panel, [null]);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->post(route('panel-remittances.store'), $this->payload($panel, 6000, [[$ours, 3000], [$theirs, 3000]]))
            ->assertSessionHasErrors(['splits' => 'Claim '.$theirs->id.' is not a claim of '.$panel->name.'.']);
        $this->post(route('panel-remittances.store'), $this->payload($panel, 6000, [[$ours, 3000], [$submitted, 3000]]))
            ->assertSessionHasErrors(['splits' => 'Claim '.$submitted->id.' has no accepted amount waiting for payment.']);
        $this->post(route('panel-remittances.store'), [...$this->payload($panel, 3000, [[$ours, 3000]]), 'panel' => 'NO-SUCH-PANEL'])
            ->assertSessionHasErrors('panel');
        $this->assertNothingRecorded();
    }

    public function test_a_retry_records_the_payment_once_and_changed_details_are_refused(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$first, $second] = $this->decidedClaims($panel, [3000, 2500]);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $payload = $this->payload($panel, 4000, [[$first, 2000], [$second, 2000]]);

        $this->post(route('panel-remittances.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('panel-remittances.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, PanelRemittance::query()->count());
        $this->assertSame(2, DB::table('panel_claim_payments')->count());

        $changed = $payload;
        $changed['splits'][0]['amount_sen'] = 2500;
        $changed['splits'][1]['amount_sen'] = 1500;
        $this->post(route('panel-remittances.store'), $changed)
            ->assertSessionHasErrors(['remittance' => 'This payment was already recorded with different details. Reload the page.']);
        $this->assertSame(1, PanelRemittance::query()->count());
    }

    public function test_only_panel_officers_and_finance_officers_at_the_claims_branch_may_record(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $payload = $this->payload($panel, 3000, [[$claim, 3000]]);

        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'director', 'administrator', 'technical_admin', 'hr_manager'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->get(route('panel-remittances.index'))->assertForbidden();
            $this->post(route('panel-remittances.store'), $payload)->assertForbidden();
        }

        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->actor('finance_officer', $otherBranch);
        $this->selectBranch($elsewhere, $otherBranch);
        $this->get(route('panel-remittances.index', ['panel' => $panel->code]))->assertInertia(fn (Assert $page) => $page->has('openClaims', 0));
        $this->post(route('panel-remittances.store'), [...$payload, 'expected_branch_id' => $otherBranch->id])
            ->assertSessionHasErrors(['splits' => 'A selected claim is no longer available at this branch. Reload the page.']);
        $this->assertNothingRecorded();

        $holders = collect(BillingPermissions::roles())->filter(fn (array $permissions): bool => in_array(PanelRemittanceService::PERMISSION, $permissions, true))->keys()->sort()->values()->all();
        $this->assertSame(['finance_officer', 'panel_officer'], $holders);
    }

    public function test_single_claim_payments_and_split_payments_add_up_and_a_stale_split_is_refused(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$first, $second] = $this->decidedClaims($panel, [3000, 3000]);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $stale = $this->payload($panel, 6000, [[$first, 3000], [$second, 3000]]);

        // A single-claim payment made after the page was loaded leaves only RM 20.00 on the first claim.
        $this->post(route('panel-claims.payment', $first), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => 1000,
            'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->post(route('panel-remittances.store'), $stale)
            ->assertSessionHasErrors(['splits' => 'Claim '.$first->id.': RM 30.00 is more than the RM 20.00 still unpaid.']);
        $this->assertSame(0, PanelRemittance::query()->count());

        $this->post(route('panel-remittances.store'), $this->payload($panel, 5000, [[$first, 2000], [$second, 3000]]))->assertSessionHasNoErrors();
        $this->assertSame('paid', $first->refresh()->status);
        $this->assertSame(3000, (int) DB::table('panel_claim_payments')->where('panel_claim_id', $first->id)->sum('amount_sen'));
        $this->post(route('panel-claims.payment', $second), [
            'expected_branch_id' => $this->branch->id, 'idempotency_key' => (string) Str::uuid(), 'amount_sen' => 1,
            'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasErrors(['payment' => 'A Panel remittance can only be recorded after an accepted decision.']);
        $this->assertSame('paid', $second->refresh()->status);
    }

    public function test_an_archived_panel_with_accepted_claims_can_still_be_paid_and_claim_ids_ignore_letter_case(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $panel->forceFill(['is_active' => false])->save();
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->get(route('panel-remittances.index'))->assertInertia(fn (Assert $page) => $page
            ->where('panels', fn ($panels) => collect($panels)->contains('code', $panel->code)));
        $payload = $this->payload($panel, 3000, [[$claim, 3000]]);
        $payload['splits'][0]['claim'] = strtoupper($claim->public_id);
        $this->post(route('panel-remittances.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame('paid', $claim->refresh()->status);
    }

    public function test_input_is_bounded_and_the_date_cannot_be_in_the_future(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $payload = $this->payload($panel, 3000, [[$claim, 3000]]);

        $this->post(route('panel-remittances.store'), [...$payload, 'received_date' => CarbonImmutable::now($this->branch->timezone)->addDay()->toDateString()])
            ->assertSessionHasErrors('received_date');
        $this->post(route('panel-remittances.store'), [...$payload, 'amount_sen' => '30.00'])->assertSessionHasErrors('amount_sen');
        $this->post(route('panel-remittances.store'), [...$payload, 'splits' => []])->assertSessionHasErrors('splits');
        $this->post(route('panel-remittances.store'), [...$payload, 'splits' => [...$payload['splits'], $payload['splits'][0]]])
            ->assertSessionHasErrors('splits.1.claim');
        $tooMany = array_map(static fn () => ['claim' => (string) Str::uuid(), 'amount_sen' => 1], range(1, PanelRemittanceService::MAX_SPLITS + 1));
        $this->post(route('panel-remittances.store'), [...$payload, 'splits' => $tooMany])->assertSessionHasErrors('splits');
        $this->post(route('panel-remittances.store'), [...$payload, 'reference' => 'bad<reference>'])->assertSessionHasErrors('reference');
        $extra = $payload;
        $extra['splits'][0]['status'] = 'paid';
        $this->post(route('panel-remittances.store'), $extra)->assertSessionHasErrors('splits.0');
        $this->assertNothingRecorded();
    }

    public function test_postgresql_keeps_remittances_append_only_balanced_and_within_one_panel(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL rules only.');
        }
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $other = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$claim] = $this->decidedClaims($panel, [3000]);
        [$foreign] = $this->decidedClaims($other, [3000]);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $this->post(route('panel-remittances.store'), $this->payload($panel, 1000, [[$claim, 1000]]))->assertSessionHasNoErrors();
        $remittance = PanelRemittance::query()->sole();

        $this->assertRefusedBy(fn () => DB::table('panel_remittances')->where('id', $remittance->id)->update(['amount_sen' => 999]), 'immutable');
        $this->assertRefusedBy(fn () => DB::table('panel_remittances')->where('id', $remittance->id)->delete(), 'immutable');

        $this->rawRemittance($panel, 5000, $officer);
        $this->assertRefusedBy(fn () => DB::statement('SET CONSTRAINTS panel_remittances_balanced IMMEDIATE'), 'split in full');

        $mismatched = $this->rawRemittance($panel, 1000, $officer);
        $this->assertRefusedBy(fn () => DB::table('panel_claim_payments')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
            'panel_claim_id' => $foreign->id, 'panel_remittance_id' => $mismatched, 'amount_sen' => 1000, 'currency' => 'MYR',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'received_at' => now(),
            'recorded_by_user_id' => $officer->id, 'created_at' => now(), 'updated_at' => now(),
        ]), 'its own Panel');
        // A split added later to an already balanced remittance is refused at commit as well.
        DB::table('panel_claim_payments')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
            'panel_claim_id' => $claim->id, 'panel_remittance_id' => $remittance->id, 'amount_sen' => 500, 'currency' => 'MYR',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('c', 64), 'received_at' => now(),
            'recorded_by_user_id' => $officer->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertRefusedBy(fn () => DB::statement('SET CONSTRAINTS panel_claim_payments_remittance_balanced IMMEDIATE'), 'split in full');
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

    private function rawRemittance(Panel $panel, int $amount, User $officer): int
    {
        return (int) DB::table('panel_remittances')->insertGetId([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'branch_id' => $this->branch->id,
            'panel_id' => $panel->id, 'amount_sen' => $amount, 'currency' => 'MYR', 'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => str_repeat('b', 64), 'received_at' => now(), 'recorded_by_user_id' => $officer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertNothingRecorded(): void
    {
        $this->assertSame(0, PanelRemittance::query()->count());
        $this->assertSame(0, DB::table('panel_claim_payments')->count());
        $this->assertSame(0, DB::table('audit_logs')->whereIn('event', ['panel_remittance.recorded', 'panel_claim.payment_received'])->count());
    }

    /**
     * @param  list<array{0: PanelClaim, 1: int}>  $splits
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Panel $panel, int $amount, array $splits, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $this->branch->id,
            'idempotency_key' => (string) Str::uuid(),
            'panel' => $panel->code,
            'amount_sen' => $amount,
            'received_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            'reference' => null,
            'splits' => array_map(static fn (array $split): array => ['claim' => $split[0]->public_id, 'amount_sen' => $split[1]], $splits),
            ...$overrides,
        ];
    }

    /**
     * Creates one Submitted claim per amount and records the Panel's decision: the full RM 30.00 claim amount
     * is accepted, a smaller amount is partially accepted, and null leaves the claim Submitted.
     *
     * @param  list<int|null>  $acceptedAmounts
     * @return list<PanelClaim>
     */
    private function decidedClaims(Panel $panel, array $acceptedAmounts): array
    {
        $claims = [];
        foreach ($acceptedAmounts as $accepted) {
            [$allocation, $invoice] = $this->approvedAllocation($panel);
            $officer = $this->officer();
            $this->selectBranch($officer, $this->branch);
            $this->post(route('panel-claims.store', $allocation), [
                'expected_branch_id' => $this->branch->id,
                'allocation_lock_version' => $allocation->lock_version,
                'invoice_lock_version' => $invoice->lock_version,
                'idempotency_key' => (string) Str::uuid(),
                'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            ])->assertSessionHasNoErrors();
            $claim = PanelClaim::query()->where('coverage_allocation_id', $allocation->id)->sole();
            if ($accepted !== null) {
                $this->post(route('panel-claims.decision', $claim), [
                    'expected_branch_id' => $this->branch->id,
                    'lock_version' => $claim->lock_version,
                    'decision' => $accepted === $claim->claim_amount_sen ? 'accepted' : 'partially_accepted',
                    'accepted_amount_sen' => $accepted,
                    'note' => 'Synthetic Panel decision',
                ])->assertSessionHasNoErrors();
            }
            $claims[] = $claim->refresh();
        }

        return $claims;
    }
}
