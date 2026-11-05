<?php

namespace Tests\Feature\Billing;

use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\CoverageAllocation;
use App\Domain\Visit\Billing\Models\Invoice;
use App\Domain\Visit\Billing\Models\PanelClaim;
use App\Domain\Visit\Billing\Services\PanelClaimService;
use App\Domain\Visit\Models\Panel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * PNL-2: bulk claim creation. Several approved invoices of one Panel become Submitted claims in one
 * action; each claim keeps its own record, idempotency key, events and audit row, and the batch is
 * all-or-nothing.
 */
class PanelBulkClaimTest extends BillingTestCase
{
    use ApprovedPanelAllocations;

    /** @var array<string, string> */
    private array $keys = [];

    public function test_panel_officer_creates_one_submitted_claim_per_selected_invoice_of_one_panel(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $payload = $this->payload($sources);

        $this->post(route('panel-claims.bulk-store'), $payload)
            ->assertRedirect(route('panel-claims.index', ['tab' => 'submitted']))
            ->assertSessionHasNoErrors();

        $claims = PanelClaim::query()->orderBy('id')->get();
        $this->assertCount(3, $claims);
        $today = CarbonImmutable::now($this->branch->timezone)->toDateString();
        foreach ($sources as $index => [$allocation, $invoice]) {
            $claim = $claims->firstWhere('coverage_allocation_id', $allocation->id);
            $this->assertNotNull($claim);
            $this->assertSame('submitted', $claim->status);
            $this->assertSame($allocation->amount_sen, $claim->claim_amount_sen);
            $this->assertSame($payload['claims'][$index]['idempotency_key'], $claim->creation_idempotency_key);
            $this->assertSame('Synthetic bulk remarks', $claim->remarks);
            $this->assertSame(['prepared', 'submitted'], DB::table('panel_claim_events')
                ->where('panel_claim_id', $claim->id)->orderBy('id')->pluck('event_type')->all());
            $audit = DB::table('audit_logs')->where('event', 'panel_claim.created')->where('subject_id', $claim->id)->sole();
            $metadata = json_decode((string) $audit->metadata, true);
            $this->assertSame($today, $metadata['submitted_date']);
            $this->assertSame(3, $metadata['bulk_size']);
            $this->assertStringNotContainsString('Synthetic bulk remarks', (string) $audit->metadata);
            // Claim work never changes the invoice or the approved allocation.
            $this->assertSame($invoice->lock_version, $invoice->refresh()->lock_version);
            $this->assertSame($allocation->lock_version, $allocation->refresh()->lock_version);
            $this->assertSame('approved', $allocation->status);
        }
        $this->assertCount(3, array_unique($claims->pluck('creation_idempotency_key')->all()));
        // Every claim in one batch shares one submitted instant.
        $this->assertSame(1, DB::table('panel_claim_events')->where('event_type', 'submitted')
            ->distinct()->count('occurred_at'));
        $this->assertSame(1, DB::table('panel_claim_events')->where('event_type', 'prepared')
            ->distinct()->count('occurred_at'));

        $this->get(route('panel-claims.index', ['tab' => 'pending']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('work.eligible.data', 0)->where('work.tabs.submitted', 3));
    }

    public function test_a_retry_of_the_same_batch_creates_no_duplicates_and_changed_details_are_refused(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $payload = $this->payload($sources);

        $this->post(route('panel-claims.bulk-store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('panel-claims.bulk-store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, PanelClaim::query()->count());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());

        $this->post(route('panel-claims.bulk-store'), [...$payload, 'remarks' => 'Different synthetic remarks'])
            ->assertSessionHasErrors('claims');
        $this->assertSame(2, PanelClaim::query()->count());
    }

    public function test_a_retry_with_one_more_invoice_keeps_the_existing_claims_and_creates_only_the_new_one(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->post(route('panel-claims.bulk-store'), $this->payload([$sources[0], $sources[1]]))->assertSessionHasNoErrors();
        $first = PanelClaim::query()->orderBy('id')->pluck('id')->all();
        $this->post(route('panel-claims.bulk-store'), $this->payload($sources))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '3 claims are Submitted; 2 had already been created.']);

        $this->assertSame(3, PanelClaim::query()->count());
        $this->assertSame($first, PanelClaim::query()->whereIn('coverage_allocation_id', [$sources[0][0]->id, $sources[1][0]->id])->orderBy('id')->pluck('id')->all());
        $this->assertSame(3, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());
    }

    public function test_a_creation_key_already_used_for_another_invoice_or_an_unknown_invoice_refuses_the_batch(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        [$allocation, $invoice] = $sources[0];
        $usedKey = (string) Str::uuid();
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id,
            'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version,
            'idempotency_key' => $usedKey,
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();

        $reused = $this->payload([$sources[1]]);
        $reused['claims'][0]['idempotency_key'] = $usedKey;
        $this->post(route('panel-claims.bulk-store'), $reused)
            ->assertSessionHasErrors(['claims' => 'Invoice '.$sources[1][1]->invoice_number.': This creation key was already used with different details.']);

        $unknown = $this->payload([$sources[1]]);
        $unknown['claims'][] = [...$unknown['claims'][0], 'allocation' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()];
        $this->post(route('panel-claims.bulk-store'), $unknown)
            ->assertSessionHasErrors(['claims' => 'A selected invoice is no longer available at this branch. Reload the list.']);
        $this->assertSame(1, PanelClaim::query()->count());
    }

    public function test_a_refused_batch_returns_to_the_same_list_view_with_its_valid_filters(): void
    {
        $first = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $second = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($first), $this->approvedAllocation($second)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->post(route('panel-claims.bulk-store'), [
            ...$this->payload($sources),
            'tab' => 'overdue', 'range' => 'last_30_days', 'doctor' => 'Synthetic', 'allocations_page' => 2,
            'visit_type' => 'not-a-type',
        ])->assertRedirect(route('panel-claims.index', ['allocations_page' => 2, 'tab' => 'overdue', 'range' => 'last_30_days', 'doctor' => 'Synthetic']));
        $this->assertNothingCreated();
    }

    public function test_invoices_of_different_panels_are_refused_and_nothing_is_created(): void
    {
        $first = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $second = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($first), $this->approvedAllocation($second)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->post(route('panel-claims.bulk-store'), $this->payload($sources))
            ->assertRedirect(route('panel-claims.index'))
            ->assertSessionHasErrors(['claims' => 'Select invoices of one Panel only.']);
        $this->assertNothingCreated();
    }

    public function test_one_changed_or_already_claimed_invoice_refuses_the_whole_batch_and_names_the_invoice(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $stale = $this->payload($sources);
        $stale['claims'][1]['invoice_lock_version']++;
        $this->post(route('panel-claims.bulk-store'), $stale)
            ->assertSessionHasErrors(['claims' => 'Invoice '.$sources[1][1]->invoice_number.': The invoice or allocation has changed. Reload before creating the claim.']);
        $this->assertNothingCreated();

        // The second invoice is claimed on its own first; a batch that includes it is refused as a whole.
        [$allocation, $invoice] = $sources[1];
        $this->post(route('panel-claims.store', $allocation), [
            'expected_branch_id' => $this->branch->id,
            'allocation_lock_version' => $allocation->lock_version,
            'invoice_lock_version' => $invoice->lock_version,
            'idempotency_key' => (string) Str::uuid(),
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->post(route('panel-claims.bulk-store'), $this->payload($sources))
            ->assertSessionHasErrors(['claims' => 'Invoice '.$invoice->invoice_number.': A claim pack already exists for this approved Panel allocation.']);
        $this->assertSame(1, PanelClaim::query()->count());
        $this->assertSame(0, PanelClaim::query()->where('coverage_allocation_id', $sources[0][0]->id)->count());
    }

    public function test_the_submitted_date_must_be_between_every_visit_date_and_today(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $today = CarbonImmutable::now($this->branch->timezone);

        foreach ([$today->addDay(), $today->subDays(3)] as $date) {
            $this->post(route('panel-claims.bulk-store'), $this->payload($sources, ['submitted_date' => $date->toDateString()]))
                ->assertSessionHasErrors('submitted_date');
        }
        $this->assertNothingCreated();
    }

    public function test_only_panel_officers_at_the_invoices_branch_may_create_a_batch(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $payload = $this->payload($sources);

        foreach (['ca', 'ca_supervisor', 'resident_doctor', 'director', 'finance_officer', 'administrator', 'technical_admin'] as $role) {
            $actor = $this->actor($role);
            $this->selectBranch($actor, $this->branch);
            $this->post(route('panel-claims.bulk-store'), $payload)->assertForbidden();
        }

        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->where('code', 'PUCHONG')->firstOrFail();
        $elsewhere = $this->actor('panel_officer', $otherBranch);
        $this->selectBranch($elsewhere, $otherBranch);
        $this->post(route('panel-claims.bulk-store'), [...$payload, 'expected_branch_id' => $otherBranch->id])
            ->assertSessionHasErrors(['claims' => 'A selected invoice is no longer available at this branch. Reload the list.']);
        $this->post(route('panel-claims.bulk-store'), $payload)->assertSessionHasErrors();
        $this->assertNothingCreated();
    }

    public function test_batch_input_is_bounded_and_rejects_duplicates_and_unknown_fields(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        $sources = [$this->approvedAllocation($panel), $this->approvedAllocation($panel)];
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);
        $payload = $this->payload($sources);

        $this->post(route('panel-claims.bulk-store'), [...$payload, 'claims' => []])->assertSessionHasErrors('claims');
        $tooMany = array_map(static fn () => [...$payload['claims'][0], 'allocation' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()], range(1, PanelClaimService::BULK_MAX + 1));
        $this->post(route('panel-claims.bulk-store'), [...$payload, 'claims' => $tooMany])
            ->assertSessionHasErrors(['claims' => 'Select at most '.PanelClaimService::BULK_MAX.' invoices at a time.']);

        $sameInvoice = $payload;
        $sameInvoice['claims'][1]['allocation'] = $sameInvoice['claims'][0]['allocation'];
        $this->post(route('panel-claims.bulk-store'), $sameInvoice)->assertSessionHasErrors('claims.1.allocation');

        $sameKey = $payload;
        $sameKey['claims'][1]['idempotency_key'] = $sameKey['claims'][0]['idempotency_key'];
        $this->post(route('panel-claims.bulk-store'), $sameKey)->assertSessionHasErrors('claims.1.idempotency_key');

        $extra = $payload;
        $extra['claims'][0]['claim_amount_sen'] = 1;
        $this->post(route('panel-claims.bulk-store'), $extra)->assertSessionHasErrors('claims.0');

        $this->post(route('panel-claims.bulk-store'), [...$payload, 'remarks' => str_repeat('a', 501)])->assertSessionHasErrors('remarks');
        $this->assertNothingCreated();
    }

    public function test_pending_rows_carry_the_versions_and_visit_date_the_bulk_form_needs(): void
    {
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id]);
        [$allocation, $invoice] = $this->approvedAllocation($panel);
        $officer = $this->officer();
        $this->selectBranch($officer, $this->branch);

        $this->get(route('panel-claims.index', ['tab' => 'pending']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('work.eligible.data.0.allocationId', $allocation->public_id)
            ->where('work.eligible.data.0.allocationLockVersion', $allocation->lock_version)
            ->where('work.eligible.data.0.invoiceLockVersion', $invoice->lock_version)
            ->where('work.eligible.data.0.visitDateIso', CarbonImmutable::now($this->branch->timezone)->toDateString())
            ->where('work.branch.today', CarbonImmutable::now($this->branch->timezone)->toDateString()));
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, PanelClaim::query()->count());
        $this->assertSame(0, DB::table('panel_claim_lines')->count());
        $this->assertSame(0, DB::table('panel_claim_events')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'panel_claim.created')->count());
    }

    /**
     * @param  list<array{0: CoverageAllocation, 1: Invoice}>  $sources
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $sources, array $overrides = []): array
    {
        return [
            'expected_branch_id' => $this->branch->id,
            'submitted_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(),
            'remarks' => 'Synthetic bulk remarks',
            'claims' => array_map(function (array $source): array {
                [$allocation, $invoice] = $source;
                $this->keys[$allocation->public_id] ??= (string) Str::uuid();

                return [
                    'allocation' => $allocation->public_id,
                    'allocation_lock_version' => $allocation->refresh()->lock_version,
                    'invoice_lock_version' => $invoice->refresh()->lock_version,
                    'idempotency_key' => $this->keys[$allocation->public_id],
                ];
            }, $sources),
            ...$overrides,
        ];
    }
}
