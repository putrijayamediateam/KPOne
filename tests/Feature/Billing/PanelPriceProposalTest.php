<?php

namespace Tests\Feature\Billing;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * Panel price changes by proposal (owner decision 27, 21 Nov 2026): the Panel Officer proposes, a Finance Officer or
 * Director who is not the proposer approves, and only then does a PriceEntry exist. Synthetic data only.
 */
class PanelPriceProposalTest extends ClinicalTestCase
{
    private User $officer;

    private User $finance;

    private User $director;

    private User $supervisor;

    private PanelPriceProposalService $service;

    private PriceBook $book;

    private ChargeDefinition $charge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = $this->actor('ca_supervisor');
        $this->officer = $this->actor('panel_officer');
        $this->finance = $this->actor('finance_officer');
        $this->director = $this->actor('director');
        foreach ([$this->supervisor, $this->officer, $this->finance, $this->director] as $user) {
            $this->selectBranch($user);
        }
        $this->service = app(PanelPriceProposalService::class);
        $references = app(PricingReferenceAdministrationService::class);
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-PNL', 'name' => 'Synthetic Panel']);
        $this->book = $references->createPriceBook($this->supervisor, null, ['name' => 'Synthetic Panel book', 'price_tier' => 'panel', 'panel_id' => $panel->id]);
        $medicine = MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id, 'code' => 'PPP-MED', 'display_name' => 'Synthetic PPP medicine', 'order_unit' => 'tablet',
            'created_by_user_id' => $this->supervisor->id, 'updated_by_user_id' => $this->supervisor->id,
        ]);
        $this->charge = $references->createMedicineCharge($this->supervisor, $medicine, ['code' => 'PPP-MED', 'display_name' => 'Synthetic PPP medicine']);
    }

    /** @param array<string, mixed> $overrides */
    private function propose(?User $by = null, array $overrides = []): PriceChangeProposal
    {
        return $this->service->propose($by ?? $this->officer, $this->book, $this->charge, [
            'expected_branch_id' => $this->branch->id,
            'expected_version' => (int) PriceEntry::query()->where('price_book_id', $this->book->id)->where('charge_definition_id', $this->charge->id)->max('version'),
            'amount_sen' => 1450,
            'agreement_reference' => 'AGR-2026-001',
            'effective_date' => CarbonImmutable::now($this->branch->timezone)->addDays(3)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function decide(PriceChangeProposal $proposal, array $extra = []): array
    {
        return ['expected_branch_id' => $this->branch->id, 'lock_version' => $proposal->lock_version, ...$extra];
    }

    private function seedEntry(int $sen, ?CarbonImmutable $effective = null): PriceEntry
    {
        $proposal = $this->propose($this->officer, ['amount_sen' => $sen, 'effective_date' => ($effective ?? CarbonImmutable::now($this->branch->timezone))->toDateString()]);
        $approved = $this->service->approve($this->finance, $proposal, $this->decide($proposal));

        return PriceEntry::query()->findOrFail($approved->price_entry_id);
    }

    public function test_the_permissions_are_held_by_exactly_the_approved_roles(): void
    {
        $roles = PermissionCatalogue::roles();
        foreach (['panel_officer'] as $role) {
            $this->assertContains(PanelPriceProposalService::PROPOSE_PERMISSION, $roles[$role]);
        }
        foreach (['finance_officer', 'director'] as $role) {
            $this->assertContains(PanelPriceProposalService::APPROVE_PERMISSION, $roles[$role]);
        }
        foreach ($roles as $role => $permissions) {
            if ($role !== 'panel_officer') {
                $this->assertNotContains(PanelPriceProposalService::PROPOSE_PERMISSION, $permissions, $role.' must not propose');
            }
            if (! in_array($role, ['finance_officer', 'director'], true)) {
                $this->assertNotContains(PanelPriceProposalService::APPROVE_PERMISSION, $permissions, $role.' must not approve');
            }
        }
    }

    public function test_a_proposal_writes_no_price_and_records_an_event_and_an_audit_without_the_reference(): void
    {
        $entries = PriceEntry::query()->count();
        $proposal = $this->propose();

        $this->assertSame('proposed', $proposal->status);
        $this->assertSame(1450, $proposal->proposed_unit_price_sen);
        $this->assertSame(0, $proposal->base_version);
        $this->assertSame($entries, PriceEntry::query()->count(), 'a proposal never writes a price');
        $this->assertSame(['proposed'], DB::table('price_change_proposal_events')->where('price_change_proposal_id', $proposal->id)->pluck('event')->all());
        $audit = AuditLog::query()->where('event', 'panel_price.proposed')->sole();
        $this->assertStringNotContainsString('AGR-2026-001', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('1450', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_only_the_panel_officer_proposes_and_only_finance_or_director_decide(): void
    {
        foreach ([$this->finance, $this->director, $this->supervisor] as $user) {
            try {
                $this->propose($user);
                $this->fail('only the Panel Officer proposes.');
            } catch (AuthorizationException) {
            }
        }
        $proposal = $this->propose();
        foreach ([$this->officer, $this->supervisor] as $user) {
            try {
                $this->service->approve($user, $proposal, $this->decide($proposal));
                $this->fail('this role must not approve.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(0, PriceEntry::query()->count());
    }

    public function test_the_finance_officer_and_the_director_can_approve_and_the_price_appears_at_its_effective_time(): void
    {
        foreach ([$this->finance, $this->director] as $i => $approver) {
            $proposal = $this->propose($this->officer, ['amount_sen' => 1500 + $i * 100]);
            $approved = $this->service->approve($approver, $proposal, $this->decide($proposal));

            $this->assertSame('approved', $approved->status);
            $entry = PriceEntry::query()->findOrFail($approved->price_entry_id);
            $this->assertSame(1500 + $i * 100, $entry->unit_price_sen);
            $this->assertSame($i + 1, $entry->version);
            $this->assertSame($this->book->id, $entry->price_book_id);
            $this->assertTrue($entry->effective_at->equalTo($approved->effective_at), 'a future date is kept');
            $this->assertSame($approver->id, $entry->published_by_user_id);
        }
        $this->assertSame(['proposed', 'approved'], DB::table('price_change_proposal_events')->where('price_change_proposal_id', $approved->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame(2, AuditLog::query()->where('event', 'panel_price.approved')->count());
    }

    public function test_the_proposer_never_decides_even_with_both_permissions(): void
    {
        $this->director->givePermissionTo(PanelPriceProposalService::PROPOSE_PERMISSION);
        $proposal = $this->propose($this->director);

        $this->expectException(ValidationException::class);
        try {
            $this->service->approve($this->director, $proposal, $this->decide($proposal));
        } finally {
            $this->assertSame('proposed', $proposal->refresh()->status);
            $this->assertSame(0, PriceEntry::query()->count());
        }
    }

    public function test_a_past_effective_time_takes_effect_at_approval(): void
    {
        $proposal = $this->propose($this->officer, ['effective_date' => CarbonImmutable::now($this->branch->timezone)->toDateString()]);
        $this->travel(3)->days();

        $approved = $this->service->approve($this->finance, $proposal, $this->decide($proposal));

        $this->assertTrue($approved->applied_effective_at->gt($proposal->effective_at));
        $this->assertTrue($approved->applied_effective_at->lessThanOrEqualTo(now()));
        $this->assertTrue(AuditLog::query()->where('event', 'panel_price.approved')->sole()->metadata['took_effect_at_approval']);
    }

    public function test_a_past_date_is_refused_at_proposal_and_so_is_an_empty_or_long_reference_or_an_empty_amount(): void
    {
        foreach ([
            ['effective_date' => CarbonImmutable::now($this->branch->timezone)->subDay()->toDateString()],
            ['agreement_reference' => '   '],
            ['agreement_reference' => 'ab'],
            ['agreement_reference' => str_repeat('x', 121)],
            ['amount_sen' => ''],
            ['amount_sen' => '-1'],
        ] as $bad) {
            try {
                $this->propose($this->officer, $bad);
                $this->fail('refused: '.json_encode($bad));
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, PriceChangeProposal::query()->count());
    }

    public function test_a_stale_base_version_is_refused_at_proposal_and_at_approval(): void
    {
        $first = $this->propose();
        try {
            $this->propose($this->officer, ['expected_version' => 5]);
            $this->fail('a stale expected version is refused.');
        } catch (ValidationException $e) {
            $this->assertSame(PanelPriceProposalService::STALE_PRICE, $e->errors()['proposal'][0]);
        }

        // A direct publish changes the version after the proposal was made.
        app(PricePublicationService::class)->publish($this->supervisor, $this->book, $this->charge, 1000, 0, $this->branch->id);
        try {
            $this->service->approve($this->finance, $first, $this->decide($first));
            $this->fail('a proposal made on an old version is refused.');
        } catch (ValidationException $e) {
            $this->assertSame(PanelPriceProposalService::STALE_PRICE, $e->errors()['proposal'][0]);
        }
        $this->assertSame('proposed', $first->refresh()->status);
    }

    public function test_one_open_proposal_per_book_and_charge(): void
    {
        $this->propose();

        $this->expectException(ValidationException::class);
        $this->propose($this->officer, ['agreement_reference' => 'AGR-2']);
    }

    public function test_a_date_before_an_already_recorded_later_price_is_refused(): void
    {
        $later = CarbonImmutable::now($this->branch->timezone)->addDays(10);
        $this->seedEntry(1200, $later);

        $this->expectException(ValidationException::class);
        $this->propose($this->officer, ['expected_version' => 1, 'effective_date' => CarbonImmutable::now($this->branch->timezone)->addDays(2)->toDateString()]);
    }

    public function test_reject_withdraw_and_retries(): void
    {
        $proposal = $this->propose();
        $rejected = $this->service->reject($this->finance, $proposal, $this->decide($proposal, ['reason_code' => 'wrong_amount']));
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('wrong_amount', $rejected->reason_code);
        $this->assertSame(0, PriceEntry::query()->count());
        $this->assertSame(['proposed', 'rejected'], DB::table('price_change_proposal_events')->where('price_change_proposal_id', $proposal->id)->orderBy('id')->pluck('event')->all());

        $second = $this->propose();
        try {
            $this->service->reject($this->finance, $second, $this->decide($second, ['reason_code' => 'other']));
            $this->fail('Other needs a note.');
        } catch (ValidationException) {
        }
        try {
            $this->service->withdraw($this->finance, $second, $this->decide($second));
            $this->fail('Finance cannot withdraw a Panel Officer proposal.');
        } catch (AuthorizationException) {
        }
        $withdrawn = $this->service->withdraw($this->officer, $second, $this->decide($second));
        $this->assertSame('withdrawn', $withdrawn->status);

        try {
            $this->service->reject($this->finance, $second, $this->decide($second, ['reason_code' => 'wrong_panel']));
            $this->fail('a decided proposal is final.');
        } catch (ValidationException $e) {
            $this->assertSame(PanelPriceProposalService::CHANGED, $e->errors()['proposal'][0]);
        }

        $third = $this->propose();
        $again = $this->service->approve($this->finance, $third, $this->decide($third));
        $retry = $this->service->approve($this->finance, $third, $this->decide($third));
        $this->assertSame($again->id, $retry->id);
        $this->assertSame(1, PriceEntry::query()->count(), 'a retried approval writes one price');
    }

    public function test_an_idempotent_propose_returns_the_same_proposal_and_a_changed_payload_is_refused(): void
    {
        $key = (string) Str::uuid();
        $one = $this->propose($this->officer, ['idempotency_key' => $key]);
        $two = $this->propose($this->officer, ['idempotency_key' => $key]);
        $this->assertSame($one->id, $two->id);

        $this->expectException(ValidationException::class);
        $this->propose($this->officer, ['idempotency_key' => $key, 'amount_sen' => 9999]);
    }

    public function test_self_pay_books_and_foreign_organisation_books_are_refused(): void
    {
        $selfPay = app(PricingReferenceAdministrationService::class)->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay', 'price_tier' => 'self_pay']);
        try {
            $this->service->propose($this->officer, $selfPay, $this->charge, [
                'expected_branch_id' => $this->branch->id, 'expected_version' => 0, 'amount_sen' => 500, 'agreement_reference' => 'XYZ',
                'effective_date' => CarbonImmutable::now($this->branch->timezone)->toDateString(), 'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->fail('Self-pay prices are not proposed.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(0, PriceChangeProposal::query()->count());
    }

    public function test_postgresql_refuses_what_the_service_refuses(): void
    {
        $proposal = $this->propose();
        $entry = app(PricePublicationService::class)->publish($this->supervisor, $this->book, $this->charge, 777, 0, $this->branch->id);

        foreach ([
            ['status' => 'approved', 'decided_by_user_id' => $this->officer->id, 'decided_at' => now(), 'price_entry_id' => PriceEntry::query()->latest('id')->value('id'), 'applied_effective_at' => now()->addYear()],
            ['status' => 'rejected', 'decided_by_user_id' => $this->finance->id, 'decided_at' => now(), 'reason_code' => 'nonsense'],
            ['status' => 'rejected', 'decided_by_user_id' => $this->supervisor->id, 'decided_at' => now(), 'reason_code' => 'wrong_amount'],
            ['proposed_unit_price_sen' => 1],
        ] as $update) {
            try {
                DB::transaction(fn () => DB::table('price_change_proposals')->where('id', $proposal->id)->update($update + ['lock_version' => 2]));
                $this->fail('refused: '.json_encode(array_keys($update)));
            } catch (QueryException) {
            }
        }
        $this->assertNotNull($entry);
        $this->assertSame('proposed', DB::table('price_change_proposals')->where('id', $proposal->id)->value('status'));
    }

    public function test_postgresql_keeps_the_evidence(): void
    {
        $proposal = $this->propose();
        $this->service->reject($this->finance, $proposal, $this->decide($proposal, ['reason_code' => 'wrong_amount']));

        foreach ([
            fn () => DB::table('price_change_proposals')->where('id', $proposal->id)->delete(),
            fn () => DB::table('price_change_proposals')->where('id', $proposal->id)->update(['status' => 'proposed']),
            fn () => DB::table('price_change_proposal_events')->where('price_change_proposal_id', $proposal->id)->delete(),
            fn () => DB::table('price_change_proposal_events')->where('price_change_proposal_id', $proposal->id)->update(['event' => 'approved']),
        ] as $write) {
            try {
                DB::transaction($write);
                $this->fail('the evidence is retained.');
            } catch (QueryException) {
            }
        }
        $this->assertSame('rejected', DB::table('price_change_proposals')->where('id', $proposal->id)->value('status'));
        $this->assertSame(2, DB::table('price_change_proposal_events')->where('price_change_proposal_id', $proposal->id)->count());
    }

    public function test_money_and_stock_evidence_is_unchanged_by_proposals_and_approvals(): void
    {
        $tables = ['invoices', 'invoice_lines', 'payments', 'payment_allocations', 'coverage_allocations', 'panel_claims', 'stock_movements', 'inventory_stock_balances'];
        $before = array_map(fn (string $t): int => DB::table($t)->count(), $tables);
        $proposal = $this->propose();
        $this->service->approve($this->finance, $proposal, $this->decide($proposal));
        $this->propose($this->officer, ['expected_version' => 1, 'amount_sen' => 1600, 'agreement_reference' => 'AGR-2']);

        $this->assertSame($before, array_map(fn (string $t): int => DB::table($t)->count(), $tables));
    }

    public function test_a_proposal_is_refused_by_postgresql_for_a_self_pay_book_or_someone_who_may_not_propose(): void
    {
        $selfPay = app(PricingReferenceAdministrationService::class)->createPriceBook($this->supervisor, null, ['name' => 'Synthetic self-pay two', 'price_tier' => 'self_pay']);
        $row = fn (int $bookId, int $userId) => [
            'public_id' => (string) Str::uuid(), 'organisation_id' => $this->organisation->id, 'price_book_id' => $bookId, 'charge_definition_id' => $this->charge->id,
            'proposed_unit_price_sen' => 100, 'agreement_reference' => 'XYZ', 'effective_at' => now(), 'base_version' => 0, 'status' => 'proposed',
            'proposed_by_user_id' => $userId, 'proposed_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'lock_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ];
        foreach ([$row($selfPay->id, $this->officer->id), $row($this->book->id, $this->finance->id)] as $insert) {
            try {
                DB::transaction(fn () => DB::table('price_change_proposals')->insert($insert));
                $this->fail('refused by PostgreSQL.');
            } catch (QueryException) {
            }
        }
        $this->assertSame(0, PriceChangeProposal::query()->count());
    }
}
