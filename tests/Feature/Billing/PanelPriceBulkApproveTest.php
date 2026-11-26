<?php

namespace Tests\Feature\Billing;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * PNL-30: "Approve selected" decides many waiting Panel price proposals in one press. Each is approved through the same
 * approve() as a single approval, so the approver is never the proposer and every proposal keeps its own lock and audit
 * row. Synthetic data only.
 */
class PanelPriceBulkApproveTest extends ClinicalTestCase
{
    private User $officer;

    private User $finance;

    private PanelPriceProposalService $service;

    private PriceBook $book;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $supervisor = $this->actor('ca_supervisor');
        $this->officer = $this->actor('panel_officer');
        $this->finance = $this->actor('finance_officer');
        foreach ([$supervisor, $this->officer, $this->finance] as $user) {
            $this->selectBranch($user);
        }
        $this->service = app(PanelPriceProposalService::class);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-BULK', 'name' => 'Synthetic Bulk Panel']);
        $this->book = app(PricingReferenceAdministrationService::class)->createPriceBook($supervisor, null, ['name' => 'Synthetic bulk book', 'price_tier' => 'panel', 'panel_id' => $panel->id]);
    }

    private function proposal(?User $by = null, int $sen = 1450): PriceChangeProposal
    {
        $supervisor = $this->actor('ca_supervisor');
        $this->n++;
        $medicine = MedicineCatalogueItem::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'BLK-'.$this->n, 'display_name' => 'Synthetic bulk medicine '.$this->n, 'order_unit' => 'tablet', 'created_by_user_id' => $supervisor->id, 'updated_by_user_id' => $supervisor->id]);
        $charge = app(PricingReferenceAdministrationService::class)->createMedicineCharge($supervisor, $medicine, ['code' => 'BLK-'.$this->n, 'display_name' => 'Synthetic bulk medicine '.$this->n]);

        return $this->service->propose($by ?? $this->officer, $this->book, $charge, [
            'expected_branch_id' => $this->branch->id, 'expected_version' => 0, 'amount_sen' => $sen, 'agreement_reference' => 'AGR-BULK-'.$this->n,
            'effective_date' => CarbonImmutable::now($this->branch->timezone)->addDay()->toDateString(), 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    /**
     * @param  list<PriceChangeProposal>  $proposals
     * @return array<int, array<string, mixed>>
     */
    private function items(array $proposals): array
    {
        return array_map(static fn (PriceChangeProposal $p): array => ['id' => $p->public_id, 'lock_version' => $p->lock_version], $proposals);
    }

    public function test_every_selected_proposal_is_approved_with_its_own_price_entry_and_audit_and_one_counts_only_batch_row(): void
    {
        $proposals = [$this->proposal(), $this->proposal(), $this->proposal()];
        $entries = PriceEntry::query()->count();

        $result = $this->service->approveMany($this->finance, $this->branch->id, $this->items($proposals));

        $this->assertSame(3, $result['approved']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame($entries + 3, PriceEntry::query()->count());
        foreach ($proposals as $proposal) {
            $this->assertSame('approved', $proposal->refresh()->status);
            $this->assertSame($this->finance->id, $proposal->decided_by_user_id);
            $this->assertNotNull($proposal->price_entry_id);
        }
        $this->assertSame(3, AuditLog::query()->where('event', 'panel_price.approved')->count());
        $bulk = AuditLog::query()->where('event', 'panel_price.bulk_approved')->sole();
        $this->assertSame(['requested' => 3, 'approved' => 3, 'skipped' => 0], $bulk->metadata);
    }

    public function test_a_proposal_that_cannot_be_approved_is_skipped_and_named_and_the_rest_still_go_through(): void
    {
        $good = $this->proposal();
        $stale = $this->proposal();
        $decided = $this->proposal();
        $this->service->reject($this->finance, $decided, ['expected_branch_id' => $this->branch->id, 'lock_version' => $decided->lock_version, 'reason_code' => 'wrong_amount']);
        $items = $this->items([$good, $stale, $decided]);
        $items[1]['lock_version'] = 99;

        $result = $this->service->approveMany($this->finance, $this->branch->id, $items);

        $this->assertSame(1, $result['approved']);
        $this->assertCount(2, $result['skipped']);
        $this->assertSame('approved', $good->refresh()->status);
        $this->assertSame('proposed', $stale->refresh()->status);
        $ids = collect($result['skipped'])->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$stale->public_id, $decided->public_id])->sort()->values()->all(), $ids);
        $this->assertSame([PanelPriceProposalService::CHANGED], collect($result['skipped'])->pluck('message')->unique()->all());
    }

    public function test_an_upper_case_proposal_id_is_still_matched(): void
    {
        $proposal = $this->proposal();

        $result = $this->service->approveMany($this->finance, $this->branch->id, [['id' => strtoupper($proposal->public_id), 'lock_version' => $proposal->lock_version]]);

        $this->assertSame(1, $result['approved']);
        $this->assertSame('approved', $proposal->refresh()->status);
    }

    public function test_the_approver_cannot_approve_their_own_proposal_in_a_batch(): void
    {
        $this->finance->givePermissionTo(PanelPriceProposalService::PROPOSE_PERMISSION);
        $theirs = $this->proposal($this->finance);
        $others = $this->proposal();

        $result = $this->service->approveMany($this->finance, $this->branch->id, $this->items([$theirs, $others]));

        $this->assertSame(1, $result['approved']);
        $this->assertSame('You proposed this. Someone else must decide.', $result['skipped'][0]['message']);
        $this->assertSame('proposed', $theirs->refresh()->status);
    }

    public function test_someone_who_cannot_approve_changes_nothing(): void
    {
        $proposal = $this->proposal();
        try {
            $this->service->approveMany($this->officer, $this->branch->id, $this->items([$proposal]));
            $this->fail('A Panel Officer approved a price change.');
        } catch (AuthorizationException) {
            $this->assertSame('proposed', $proposal->refresh()->status);
            $this->assertSame(0, AuditLog::query()->where('event', 'panel_price.bulk_approved')->count());
        }
    }

    public function test_an_empty_oversized_or_repeated_selection_is_refused_before_anything_is_decided(): void
    {
        $proposal = $this->proposal();
        $row = ['id' => $proposal->public_id, 'lock_version' => $proposal->lock_version];
        $tooMany = array_map(static fn (int $i): array => ['id' => (string) Str::uuid(), 'lock_version' => 1], range(1, PanelPriceProposalService::BULK_LIMIT + 1));

        foreach ([[], $tooMany, [$row, $row]] as $bad) {
            try {
                $this->service->approveMany($this->finance, $this->branch->id, $bad);
                $this->fail('A bad selection was accepted.');
            } catch (ValidationException) {
                $this->assertSame('proposed', $proposal->refresh()->status);
            }
        }
    }

    public function test_the_route_approves_the_selection_for_a_finance_officer_and_refuses_a_panel_officer(): void
    {
        $proposals = [$this->proposal(), $this->proposal()];
        $payload = ['expected_branch_id' => $this->branch->id, 'items' => $this->items($proposals)];

        $this->actingAs($this->officer)->post(route('panel-price-changes.approve-selected'), $payload)->assertForbidden();
        $this->actingAs($this->finance)->post(route('panel-price-changes.approve-selected'), $payload)
            ->assertRedirect(route('panel-price-changes.index'))
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 price changes approved.']);
        $this->assertSame(2, PriceChangeProposal::query()->where('status', 'approved')->count());
    }

    public function test_a_proposer_cannot_create_a_batch_audit_when_all_selected_ids_are_missing(): void
    {
        $this->actingAs($this->officer)->post(route('panel-price-changes.approve-selected'), [
            'expected_branch_id' => $this->branch->id,
            'items' => [['id' => (string) Str::uuid(), 'lock_version' => 1]],
        ])->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('event', 'panel_price.bulk_approved')->count());
    }

    public function test_case_variants_of_one_uuid_are_refused_before_any_decision(): void
    {
        $proposal = $this->proposal();
        $items = [
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'lock_version' => 1],
            ['id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', 'lock_version' => 1],
        ];
        try {
            $this->service->approveMany($this->finance, $this->branch->id, $items);
            $this->fail('A case-variant duplicate was accepted.');
        } catch (ValidationException) {
            $this->assertSame('proposed', $proposal->refresh()->status);
            $this->assertSame(0, AuditLog::query()->where('event', 'panel_price.bulk_approved')->count());
        }
    }

    public function test_skipped_missing_and_decided_items_do_not_claim_to_be_waiting(): void
    {
        $proposal = $this->proposal();
        $this->service->reject($this->finance, $proposal, ['expected_branch_id' => $this->branch->id, 'lock_version' => $proposal->lock_version, 'reason_code' => 'wrong_amount']);

        $this->actingAs($this->finance)->post(route('panel-price-changes.approve-selected'), [
            'expected_branch_id' => $this->branch->id,
            'items' => [...$this->items([$proposal]), ['id' => (string) Str::uuid(), 'lock_version' => 1]],
        ])->assertRedirect(route('panel-price-changes.index'))
            ->assertSessionHasErrors(['bulk' => '2 could not be approved. Review the refreshed lists. '.PanelPriceProposalService::CHANGED.' This price change was not found.']);
        $this->assertSame('rejected', $proposal->refresh()->status);
    }

    public function test_the_route_says_how_many_were_skipped_and_why(): void
    {
        $good = $this->proposal();
        $this->finance->givePermissionTo(PanelPriceProposalService::PROPOSE_PERMISSION);
        $mine = $this->proposal($this->finance);

        $this->actingAs($this->finance)->post(route('panel-price-changes.approve-selected'), ['expected_branch_id' => $this->branch->id, 'items' => $this->items([$good, $mine])])
            ->assertRedirect(route('panel-price-changes.index'))
            ->assertSessionHasErrors(['bulk' => '1 could not be approved. Review the refreshed lists. You proposed this. Someone else must decide.']);
    }

    public function test_bulk_approval_preserves_an_unlimited_worker_timeout(): void
    {
        $original = (int) ini_get('max_execution_time');
        try {
            set_time_limit(0);
            $proposal = $this->proposal();
            $this->actingAs($this->finance)->post(route('panel-price-changes.approve-selected'), [
                'expected_branch_id' => $this->branch->id, 'items' => $this->items([$proposal]),
            ])->assertRedirect(route('panel-price-changes.index'))->assertSessionHasNoErrors();
            $this->assertSame(0, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($original);
        }
    }

    public function test_bulk_validation_failure_restores_the_original_bounded_timeout(): void
    {
        $original = (int) ini_get('max_execution_time');
        try {
            set_time_limit(60);
            $this->actingAs($this->finance)->post(route('panel-price-changes.approve-selected'), [
                'expected_branch_id' => $this->branch->id, 'items' => [],
            ])->assertRedirect(route('panel-price-changes.index'))->assertSessionHasErrors();
            $this->assertSame(60, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($original);
        }
    }

    public function test_successful_bulk_approval_restores_the_original_bounded_timeout(): void
    {
        $original = (int) ini_get('max_execution_time');
        try {
            set_time_limit(60);
            $proposal = $this->proposal();
            $this->actingAs($this->finance)->post(route('panel-price-changes.approve-selected'), [
                'expected_branch_id' => $this->branch->id, 'items' => $this->items([$proposal]),
            ])->assertRedirect(route('panel-price-changes.index'))->assertSessionHasNoErrors();
            $this->assertSame(60, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($original);
        }
    }
}
