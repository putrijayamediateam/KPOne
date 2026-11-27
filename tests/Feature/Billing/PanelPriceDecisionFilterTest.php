<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Services\PanelPriceChangesPage;
use App\Domain\Visit\Billing\Services\PanelPriceProposalService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/** The Panel price changes page and its routes (slice 2). Synthetic data only. */
class PanelPriceDecisionFilterTest extends ClinicalTestCase
{
    private User $officer;

    private User $finance;

    private PriceBook $book;

    private Panel $panel;

    private ChargeDefinition $charge;

    protected function setUp(): void
    {
        parent::setUp();
        $supervisor = $this->actor('ca_supervisor');
        $this->officer = $this->actor('panel_officer');
        $this->finance = $this->actor('finance_officer');
        foreach ([$supervisor, $this->officer, $this->finance] as $user) {
            $this->selectBranch($user);
        }
        $references = app(PricingReferenceAdministrationService::class);
        $this->panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-PNL', 'name' => 'Synthetic Panel']);
        $this->book = $references->createPriceBook($supervisor, null, ['name' => 'Synthetic Panel book', 'price_tier' => 'panel', 'panel_id' => $this->panel->id]);
        $medicine = MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id, 'code' => 'PPG-MED', 'display_name' => 'Synthetic page medicine', 'order_unit' => 'tablet',
            'created_by_user_id' => $supervisor->id, 'updated_by_user_id' => $supervisor->id,
        ]);
        $this->charge = $references->createMedicineCharge($supervisor, $medicine, ['code' => 'PPG-MED', 'display_name' => 'Synthetic page medicine']);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'expected_branch_id' => $this->branch->id, 'expected_version' => 0, 'panel' => (string) $this->panel->id, 'charge' => $this->charge->public_id,
            'amount_sen' => 1450, 'agreement_reference' => 'AGR-PAGE-001', 'effective_date' => CarbonImmutable::now($this->branch->timezone)->addDays(2)->toDateString(),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    private function decide(string $status): PriceChangeProposal
    {
        $this->actingAs($this->officer);
        $version = (int) DB::table('price_entries')->where('price_book_id', $this->book->id)->where('charge_definition_id', $this->charge->id)->max('version');
        $service = app(PanelPriceProposalService::class);
        $proposal = $service->propose($this->officer, $this->book, $this->charge, [...$this->payload(), 'expected_version' => $version]);
        if ($status === 'proposed') {
            return $proposal;
        }
        $this->actingAs($status === 'withdrawn' ? $this->officer : $this->finance);
        $action = ['approved' => 'approve', 'rejected' => 'reject', 'withdrawn' => 'withdraw'][$status];
        $service->{$action}($status === 'withdrawn' ? $this->officer : $this->finance, $proposal, ['expected_branch_id' => $this->branch->id, 'lock_version' => 1, 'reason_code' => 'wrong_amount']);
        $this->assertSame($status, $proposal->refresh()->status);

        return $proposal;
    }

    public function test_exact_status_filters_precede_twenty_five_row_pagination_and_keep_waiting(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->decide('approved');
        }
        $this->decide('rejected');
        $this->decide('withdrawn');
        $this->decide('proposed');
        $this->actingAs($this->finance);
        $this->get(route('panel-price-changes.index', ['decided_status' => 'approved', 'decided_page' => 2]))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('decidedStatus', 'approved')->where('decidedContext', true)->where('decidedPagination.total', 26)->where('decidedPagination.page', 2)
            ->has('decided', 1)->where('decided.0.status', 'approved')->where('waitingCount', 1)->has('waiting', 1));
        foreach (['rejected', 'withdrawn'] as $status) {
            $this->get(route('panel-price-changes.index', ['decided_status' => $status]))->assertInertia(fn (Assert $p) => $p
                ->where('decidedPagination.total', 1)->has('decided', 1)->where('decided.0.status', $status)->where('waitingCount', 1));
        }
        $this->get(route('panel-price-changes.index', ['decided_page' => 1]))->assertInertia(fn (Assert $p) => $p
            ->where('decidedStatus', null)->where('decidedContext', true)->where('decidedPagination.total', 28)->has('decided', 25));
    }

    public function test_unknown_and_malformed_statuses_are_rejected_and_authority_stays_closed(): void
    {
        $this->actingAs($this->officer);
        foreach (['proposed', 'paid', ['approved'], ['value' => 'approved']] as $status) {
            $this->getJson(route('panel-price-changes.index', ['decided_status' => $status]))->assertUnprocessable()->assertJsonValidationErrors('decided_status');
        }
        try {
            app(PanelPriceChangesPage::class)->page($this->officer, null, null, 1, ['approved']);
            $this->fail('Direct service input must be validated.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decided_status', $e->errors());
        }
        $this->actingAs($this->actor('ca'));
        $this->get(route('panel-price-changes.index', ['decided_status' => 'approved']))->assertForbidden();
    }

    public function test_filtered_read_keeps_proposal_selection_and_hides_other_organisations(): void
    {
        $this->decide('rejected');
        $this->selectBranch($this->officer);
        $this->get(route('panel-price-changes.index', ['decided_status' => 'rejected', 'panel' => (string) $this->panel->id, 'charge' => $this->charge->public_id]))->assertInertia(fn (Assert $p) => $p
            ->where('decidedPagination.total', 1)->where('selection.hasPriceList', true)->where('selection.openProposal', false)->where('selection.version', 0));
        $organisation = Organisation::query()->forceCreate(['code' => 'SYN-DECISION-OTHER', 'name' => 'Synthetic other organisation', 'is_active' => true]);
        $branch = Branch::query()->forceCreate(['organisation_id' => $organisation->id, 'code' => 'SYN-OTHER', 'name' => 'Synthetic other branch', 'timezone' => 'Asia/Kuala_Lumpur', 'is_active' => true]);
        $foreign = $this->actor('finance_officer', $branch);
        $this->selectBranch($foreign, $branch);
        $this->get(route('panel-price-changes.index', ['decided_status' => 'rejected']))->assertInertia(fn (Assert $p) => $p
            ->where('decidedPagination.total', 0)->has('decided', 0)->where('waitingCount', 0)->has('waiting', 0));
    }
}
