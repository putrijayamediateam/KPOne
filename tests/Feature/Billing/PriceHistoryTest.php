<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Organisation\Models\Organisation;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Billing\Services\PriceHistoryService;
use App\Domain\Visit\Billing\Services\PriceListCheckService;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * CAT-3 (owner decision 2026-11-05): a read-only history of every published price of one charge.
 */
class PriceHistoryTest extends ClinicalTestCase
{
    private User $supervisor;

    private PricingReferenceAdministrationService $references;

    private PricePublicationService $publication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supervisor = $this->actor('ca_supervisor');
        $this->selectBranch($this->supervisor);
        $this->references = app(PricingReferenceAdministrationService::class);
        $this->publication = app(PricePublicationService::class);
    }

    private function charge(string $code): ChargeDefinition
    {
        $medicine = MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id,
            'code' => $code,
            'display_name' => 'Synthetic '.$code,
            'order_unit' => 'tablet',
            'created_by_user_id' => $this->supervisor->id,
            'updated_by_user_id' => $this->supervisor->id,
        ]);

        return $this->references->createMedicineCharge($this->supervisor, $medicine, ['code' => $code, 'display_name' => 'Synthetic '.$code]);
    }

    private function book(string $tier, ?Branch $branch = null, ?Panel $panel = null): PriceBook
    {
        return $this->references->createPriceBook($this->supervisor, $branch, array_filter([
            'name' => 'Synthetic '.$tier.' '.($branch?->code ?? '').($panel?->code ?? ''),
            'price_tier' => $tier,
            'panel_id' => $panel?->id,
        ], fn ($value) => $value !== null));
    }

    private function publish(PriceBook $book, ChargeDefinition $charge, int $sen, ?User $by = null): PriceEntry
    {
        $version = (int) DB::table('price_entries')->where('price_book_id', $book->id)
            ->where('charge_definition_id', $charge->id)->max('version');
        $this->publication->publish($by ?? $this->supervisor, $book, $charge, $sen, $version, $this->branch->id);
        $this->travel(1)->minutes();

        return PriceEntry::query()->where('price_book_id', $book->id)->where('charge_definition_id', $charge->id)->orderByDesc('version')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function history(ChargeDefinition $charge, ?string $book = null, int $page = 1, ?User $actor = null): array
    {
        return app(PriceHistoryService::class)->history($actor ?? $this->supervisor, $charge, $this->branch, $book, $page);
    }

    public function test_every_version_in_every_book_is_listed_newest_first_with_in_use_replaced_and_scheduled(): void
    {
        $charge = $this->charge('HIS-ONE');
        $organisationBook = $this->book('self_pay');
        $branchBook = $this->book('self_pay', $this->branch);
        $panelDefault = $this->book('panel');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-HIS-PANEL', 'name' => 'Synthetic History Panel']);
        $panelBook = $this->book('panel', panel: $panel);

        $this->publish($organisationBook, $charge, 1000);
        $this->publish($organisationBook, $charge, 1200);
        $this->publish($branchBook, $charge, 900);
        $this->publish($panelDefault, $charge, 0);
        $this->publish($panelBook, $charge, 800);
        // A version that takes effect later is "Scheduled" and does not replace the one in use.
        $future = PriceEntry::query()->forceCreate([
            'organisation_id' => $this->organisation->id, 'price_book_id' => $organisationBook->id, 'charge_definition_id' => $charge->id,
            'unit_price_sen' => 1500, 'version' => 3, 'effective_at' => now()->utc()->addDay(), 'published_by_user_id' => $this->supervisor->id,
        ]);

        $result = $this->history($charge);

        $this->assertSame(['publicId' => $charge->public_id, 'code' => 'HIS-ONE', 'displayName' => 'Synthetic HIS-ONE', 'unit' => 'tablet', 'isActive' => true], $result['charge']);
        $this->assertSame(6, $result['total']);
        $rows = $result['rows'];
        $this->assertSame([$future->id], [$rows[0]['id']]);
        $this->assertSame(
            [
                ['Self-pay (organisation)', 3, 1500, 'scheduled'],
                ['Panel · Synthetic History Panel', 1, 800, 'in_use'],
                ['Default Panel', 1, 0, 'in_use'],
                ['Self-pay · '.$this->branch->name, 1, 900, 'in_use'],
                ['Self-pay (organisation)', 2, 1200, 'in_use'],
                ['Self-pay (organisation)', 1, 1000, 'replaced'],
            ],
            array_map(fn (array $row): array => [$row['book'], $row['version'], $row['amountSen'], $row['status']], $rows),
        );
        $this->assertSame($this->supervisor->name, $rows[1]['publishedBy']);
        $this->assertSame(
            ['Default Panel', 'Panel · Synthetic History Panel', 'Self-pay (organisation)', 'Self-pay · '.$this->branch->name],
            array_column($result['books'], 'label'),
        );
        $this->assertSame($future->effective_at->setTimezone($this->branch->timezone)->format('j M Y, g:i A'), $rows[0]['effectiveAt']);
    }

    public function test_the_book_filter_narrows_the_list_and_pagination_holds_25_rows(): void
    {
        $charge = $this->charge('HIS-MANY');
        $selfPay = $this->book('self_pay');
        $panelDefault = $this->book('panel');
        foreach (range(1, 26) as $i) {
            $this->publish($selfPay, $charge, 100 + $i);
        }
        $this->publish($panelDefault, $charge, 90);

        $all = $this->history($charge);
        $this->assertSame(27, $all['total']);
        $this->assertCount(25, $all['rows']);
        $this->assertSame(2, $all['lastPage']);
        $this->assertCount(2, $this->history($charge, page: 2)['rows']);

        $only = $this->history($charge, $panelDefault->public_id);
        $this->assertSame(1, $only['total']);
        $this->assertSame([90], array_column($only['rows'], 'amountSen'));
        // An active book with no price for this charge can still be chosen, and shows nothing.
        $branchBook = $this->book('self_pay', $this->branch);
        $this->assertContains($branchBook->public_id, array_column($this->history($charge)['books'], 'publicId'));
        $this->assertSame(0, $this->history($charge, $branchBook->public_id)['total']);
        // An unknown book shows everything rather than nothing; the controller only accepts a uuid.
        $this->assertSame(27, $this->history($charge, '00000000-0000-4000-8000-000000000000')['total']);
        // A page past the end shows the last page.
        $this->assertSame(2, $this->history($charge, page: 9)['currentPage']);
    }

    public function test_a_publisher_the_viewer_may_not_see_in_the_staff_directory_reads_staff_member(): void
    {
        $charge = $this->charge('HIS-NAMES');
        $selfPay = $this->book('self_pay');
        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->firstOrFail();
        $finance = $this->actor('finance_officer', $otherBranch);
        $this->selectBranch($finance, $otherBranch);
        $version = 0;
        $this->publication->publish($finance, $selfPay, $charge, 500, $version, $otherBranch->id);

        // The CA Supervisor sees staff at their own branch only; the Director sees the whole organisation.
        $this->assertSame('Staff member', $this->history($charge)['rows'][0]['publishedBy']);
        $director = $this->actor('director');
        $this->assertSame($finance->name, $this->history($charge, actor: $director)['rows'][0]['publishedBy']);
    }

    public function test_a_charge_with_no_prices_and_an_inactive_charge_are_shown_as_they_are(): void
    {
        $charge = $this->charge('HIS-EMPTY');
        $this->references->deactivateCharge($this->supervisor, $charge);

        $result = $this->history($charge->fresh());

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['rows']);
        $this->assertSame([], $result['books']);
        $this->assertFalse($result['charge']['isActive']);
    }

    public function test_the_page_is_read_only_and_limited_to_the_pricing_roles_and_organisation(): void
    {
        $charge = $this->charge('HIS-ROUTE');
        $this->publish($this->book('self_pay'), $charge, 700);
        $allowed = array_map(fn (string $role): User => $this->actor($role), ['director', 'finance_officer', 'administrator', 'ca_supervisor']);
        $tables = ['price_entries', 'price_books', 'charge_definitions', 'audit_logs'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        foreach ($allowed as $user) {
            $this->selectBranch($user);
            $this->actingAs($user)->get(route('pricing.charges.history', $charge))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('Pricing/PriceHistory')->where('result.total', 1)->where('result.rows.0.amountSen', 700));
        }
        $this->assertSame($before, array_map(fn (string $table): int => DB::table($table)->count(), $tables));

        foreach (['ca', 'resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            $user = $this->actor($role);
            $this->selectBranch($user);
            $this->actingAs($user)->get(route('pricing.charges.history', $charge))->assertForbidden();
        }

        $this->expectException(AuthorizationException::class);
        app(PriceHistoryService::class)->history($this->actor('ca'), $charge, $this->branch, null, 1);
    }

    public function test_the_page_is_told_the_time_zone_its_times_are_shown_in(): void
    {
        $charge = $this->charge('HIS-ZONE');
        $this->publish($this->book('self_pay'), $charge, 700);
        $this->selectBranch($this->supervisor);

        $this->actingAs($this->supervisor)->get(route('pricing.charges.history', $charge))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('timezone', $this->branch->timezone)->where('result.total', 1));
    }

    public function test_another_organisations_charge_is_not_found_and_bad_filters_stay_on_the_page(): void
    {
        $charge = $this->charge('HIS-ORG');
        $other = Organisation::query()->create(['code' => 'SYN-HISTORY', 'name' => 'Synthetic history organisation', 'is_active' => true]);
        $foreign = ChargeDefinition::query()->forceCreate([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $other->id, 'code' => 'FOREIGN', 'display_name' => 'Synthetic foreign',
            'type' => 'consultation', 'source_key' => 'consultation', 'unit' => 'consultation', 'is_active' => true,
        ]);

        $this->actingAs($this->supervisor)->get(route('pricing.charges.history', $foreign))->assertNotFound();

        $this->actingAs($this->supervisor)->get(route('pricing.charges.history', $charge).'?book=not-a-uuid')
            ->assertRedirect(route('pricing.charges.history', $charge))
            ->assertSessionHasErrors('book');
        $this->actingAs($this->supervisor)->get(route('pricing.charges.history', $charge).'?from=elsewhere')
            ->assertRedirect(route('pricing.charges.history', $charge))
            ->assertSessionHasErrors('from');
        $this->actingAs($this->supervisor)->get(route('pricing.charges.history', $charge).'?from=price-check')
            ->assertOk()->assertInertia(fn ($page) => $page->where('from', 'price-check'));
    }

    public function test_a_version_in_an_inactive_book_is_never_in_use_and_the_applied_filter_is_returned(): void
    {
        $charge = $this->charge('HIS-INACTIVE-BOOK');
        $selfPay = $this->book('self_pay');
        $branchBook = $this->book('self_pay', $this->branch);
        $this->publish($selfPay, $charge, 600);
        $this->publish($branchBook, $charge, 650);
        $this->publish($branchBook, $charge, 700);
        $this->references->deactivatePriceBook($this->supervisor, $branchBook);

        $rows = $this->history($charge)['rows'];

        $this->assertSame(
            [[700, 'not_in_use'], [650, 'replaced'], [600, 'in_use']],
            array_map(fn (array $row): array => [$row['amountSen'], $row['status']], $rows),
        );
        $this->assertFalse($rows[0]['bookActive']);
        // The inactive book holds versions, so it can still be chosen.
        $this->assertContains($branchBook->public_id, array_column($this->history($charge)['books'], 'publicId'));
        $this->assertSame($branchBook->public_id, $this->history($charge, $branchBook->public_id)['book']);
        $this->assertNull($this->history($charge, '00000000-0000-4000-8000-000000000000')['book']);
        $this->actingAs($this->supervisor)
            ->get(route('pricing.charges.history', $charge).'?book=00000000-0000-4000-8000-000000000000')
            ->assertOk()->assertInertia(fn ($page) => $page->where('filters.book', null)->where('result.total', 3));
    }

    public function test_a_bad_filter_keeps_the_way_back_to_the_price_list_check(): void
    {
        $charge = $this->charge('HIS-BACK');

        $this->actingAs($this->supervisor)->get(route('pricing.charges.history', $charge).'?from=price-check&book=nope')
            ->assertRedirect(route('pricing.charges.history', ['charge' => $charge, 'from' => 'price-check']))
            ->assertSessionHasErrors(['book' => 'Choose a price book from the list.']);
    }

    public function test_the_price_list_check_shows_no_price_for_an_inactive_charge_and_links_each_charge(): void
    {
        $medicine = MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id, 'code' => 'HIS-CHK-OFF', 'display_name' => 'Synthetic HIS-CHK-OFF', 'order_unit' => 'tablet',
            'created_by_user_id' => $this->supervisor->id, 'updated_by_user_id' => $this->supervisor->id,
        ]);
        $charge = $this->references->createMedicineCharge($this->supervisor, $medicine, ['code' => 'HIS-CHK-OFF', 'display_name' => 'Synthetic HIS-CHK-OFF']);
        $this->publish($this->book('self_pay'), $charge, 300);
        $this->publish($this->book('panel'), $charge, 280);
        $this->references->deactivateCharge($this->supervisor, $charge);

        $row = collect(app(PriceListCheckService::class)->check($this->supervisor, $this->branch, [], 1)['rows'])->firstWhere('code', 'HIS-CHK-OFF');

        $this->assertSame(['charge_inactive'], $row['checks']);
        $this->assertNull($row['selfPaySen']);
        $this->assertFalse($row['selfPayFromBranch']);
        $this->assertNull($row['panelDefaultSen']);
        $this->assertSame([], $row['panelPrices']);
        $this->assertSame($charge->public_id, $row['chargePublicId']);
    }
}
