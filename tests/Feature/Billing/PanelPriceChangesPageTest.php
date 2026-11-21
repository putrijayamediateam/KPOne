<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceChangeProposal;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Clinical\ClinicalTestCase;

/** The Panel price changes page and its routes (slice 2). Synthetic data only. */
class PanelPriceChangesPageTest extends ClinicalTestCase
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

    public function test_the_officer_proposes_and_a_finance_officer_approves_through_the_routes(): void
    {
        $this->actingAs($this->officer);
        $this->post(route('panel-price-changes.store'), $this->payload())->assertRedirect(route('panel-price-changes.index'));
        $proposal = PriceChangeProposal::query()->sole();

        $this->get(route('panel-price-changes.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('PanelPriceChanges/Index')->where('canPropose', true)->where('canApprove', false)
            ->where('waitingCount', 1)->where('waiting.0.mine', true)->where('waiting.0.newAmountSen', 1450));
        $this->post(route('panel-price-changes.approve', $proposal), ['expected_branch_id' => $this->branch->id, 'lock_version' => 1])->assertForbidden();

        $this->actingAs($this->finance);
        $this->get(route('panel-price-changes.index'))->assertInertia(fn (Assert $page) => $page
            ->where('canPropose', false)->where('canApprove', true)->where('waiting.0.mine', false)->missing('waiting.0.decisionNote'));
        $this->post(route('panel-price-changes.approve', $proposal), ['expected_branch_id' => $this->branch->id, 'lock_version' => 1])->assertRedirect(route('panel-price-changes.index'));
        $this->assertSame('approved', $proposal->refresh()->status);
        $this->get(route('panel-price-changes.index'))->assertInertia(fn (Assert $page) => $page
            ->where('waitingCount', 0)->where('decided.0.status', 'approved')->where('decided.0.oldAmountSen', null));
    }

    public function test_the_page_is_closed_to_other_roles_and_a_refusal_stays_on_the_page(): void
    {
        $this->actingAs($this->actor('ca'));
        $this->get(route('panel-price-changes.index'))->assertForbidden();

        $this->actingAs($this->officer);
        $this->post(route('panel-price-changes.store'), [...$this->payload(), 'agreement_reference' => 'x'])
            ->assertRedirect(route('panel-price-changes.index'))->assertSessionHasErrors('agreement_reference');
        $this->assertSame(0, PriceChangeProposal::query()->count());
    }

    public function test_the_selection_shows_the_current_price_and_whether_a_change_is_open(): void
    {
        $this->actingAs($this->officer);
        $this->get(route('panel-price-changes.index', ['panel' => (string) $this->panel->id, 'charge' => $this->charge->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('selection.hasPriceList', true)->where('selection.version', 0)->where('selection.currentAmountSen', null)->where('selection.openProposal', false));
        $this->post(route('panel-price-changes.store'), $this->payload());
        $this->get(route('panel-price-changes.index', ['panel' => (string) $this->panel->id, 'charge' => $this->charge->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('selection.openProposal', true));
    }

    public function test_panels_without_a_price_book_are_offered_and_the_first_approval_creates_the_book(): void
    {
        $fresh = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-NOBOOK', 'name' => 'Synthetic Panel without book']);
        $this->actingAs($this->officer);
        $this->get(route('panel-price-changes.index'))->assertInertia(fn (Assert $page) => $page
            ->where('panels', fn ($panels) => collect($panels)->pluck('id')->contains('default') && collect($panels)->pluck('id')->contains((string) $fresh->id)));

        $this->get(route('panel-price-changes.index', ['panel' => (string) $fresh->id, 'charge' => $this->charge->public_id]))
            ->assertInertia(fn (Assert $page) => $page->where('selection.hasPriceList', false)->where('selection.currentAmountSen', null));

        $this->post(route('panel-price-changes.store'), [...$this->payload(), 'panel' => (string) $fresh->id])->assertRedirect(route('panel-price-changes.index'));
        $this->assertSame(0, PriceBook::query()->where('scope_key', 'panel:'.$fresh->id)->count());
        $proposal = PriceChangeProposal::query()->sole();
        $this->get(route('panel-price-changes.index'))->assertInertia(fn (Assert $page) => $page
            ->where('waiting.0.panelName', 'Synthetic Panel without book')->where('waiting.0.panelKey', (string) $fresh->id)->where('waiting.0.oldAmountSen', null));

        $this->actingAs($this->finance);
        $this->post(route('panel-price-changes.approve', $proposal), ['expected_branch_id' => $this->branch->id, 'lock_version' => 1])->assertRedirect(route('panel-price-changes.index'));
        $this->assertSame(1, PriceBook::query()->where('scope_key', 'panel:'.$fresh->id)->count());
    }
}
