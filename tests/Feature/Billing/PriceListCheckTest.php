<?php

namespace Tests\Feature\Billing;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Services\PriceListCheckService;
use App\Domain\Visit\Billing\Services\PricePublicationService;
use App\Domain\Visit\Billing\Services\PricingReferenceAdministrationService;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Clinical\ClinicalTestCase;

/**
 * CAT-2 (owner decision 2026-11-05): a read-only price list check that flags what would make billing refuse a
 * line or charge nothing, following PriceResolutionService.
 */
class PriceListCheckTest extends ClinicalTestCase
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

    private function medicine(string $code, string $unit = 'tablet', bool $active = true): MedicineCatalogueItem
    {
        return MedicineCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id,
            'code' => $code,
            'display_name' => 'Synthetic '.$code,
            'order_unit' => $unit,
            'is_active' => $active,
            'created_by_user_id' => $this->supervisor->id,
            'updated_by_user_id' => $this->supervisor->id,
        ]);
    }

    private function book(string $tier, ?Branch $branch = null, ?Panel $panel = null): PriceBook
    {
        return $this->references->createPriceBook($this->supervisor, $branch, array_filter([
            'name' => 'Synthetic '.$tier.' '.($branch?->code ?? '').($panel?->code ?? ''),
            'price_tier' => $tier,
            'panel_id' => $panel?->id,
        ], fn ($value) => $value !== null));
    }

    private function publish(PriceBook $book, ChargeDefinition $charge, int $sen): void
    {
        $version = (int) DB::table('price_entries')->where('price_book_id', $book->id)
            ->where('charge_definition_id', $charge->id)->max('version');
        $this->publication->publish($this->supervisor, $book, $charge, $sen, $version, $this->branch->id);
    }

    /** @return array<string, array<string, mixed>> rows keyed by code */
    private function rows(array $filters = []): array
    {
        $result = app(PriceListCheckService::class)->check($this->supervisor, $this->branch, $filters, 1);

        return collect($result['rows'])->keyBy('code')->all();
    }

    public function test_each_billing_blocker_and_zero_price_is_flagged_and_a_complete_item_is_ok(): void
    {
        $selfPay = $this->book('self_pay');
        $panelDefault = $this->book('panel');
        $panel = Panel::factory()->create(['organisation_id' => $this->organisation->id, 'code' => 'SYNTH-CHECK-PANEL', 'name' => 'Synthetic Check Panel']);
        $panelBook = $this->book('panel', panel: $panel);

        $complete = $this->medicine('CHK-OK');
        $completeCharge = $this->references->createMedicineCharge($this->supervisor, $complete, ['code' => 'CHK-OK', 'display_name' => 'Synthetic CHK-OK']);
        $this->publish($selfPay, $completeCharge, 150);
        $this->publish($panelDefault, $completeCharge, 120);
        $this->publish($panelBook, $completeCharge, 110);

        $this->medicine('CHK-NOCHARGE');

        $noSelfPay = $this->medicine('CHK-NOSELF');
        $noSelfPayCharge = $this->references->createMedicineCharge($this->supervisor, $noSelfPay, ['code' => 'CHK-NOSELF', 'display_name' => 'Synthetic CHK-NOSELF']);
        $this->publish($panelDefault, $noSelfPayCharge, 90);

        $free = $this->medicine('CHK-FREE');
        $freeCharge = $this->references->createMedicineCharge($this->supervisor, $free, ['code' => 'CHK-FREE', 'display_name' => 'Synthetic CHK-FREE']);
        $this->publish($selfPay, $freeCharge, 0);

        $unit = $this->medicine('CHK-UNIT');
        $unitCharge = $this->references->createMedicineCharge($this->supervisor, $unit, ['code' => 'CHK-UNIT', 'display_name' => 'Synthetic CHK-UNIT']);
        $this->publish($selfPay, $unitCharge, 200);
        $this->publish($panelDefault, $unitCharge, 180);
        $unit->forceFill(['order_unit' => 'capsule'])->save();

        $inactive = $this->medicine('CHK-INACTIVE-CHARGE');
        $inactiveCharge = $this->references->createMedicineCharge($this->supervisor, $inactive, ['code' => 'CHK-INACTIVE-CHARGE', 'display_name' => 'Synthetic inactive charge']);
        $this->publish($selfPay, $inactiveCharge, 300);
        $this->publish($panelDefault, $inactiveCharge, 300);
        $this->references->deactivateCharge($this->supervisor, $inactiveCharge);

        $this->medicine('CHK-RETIRED', active: false);

        $rows = $this->rows();

        $this->assertSame([], $rows['CHK-OK']['checks']);
        $this->assertSame(150, $rows['CHK-OK']['selfPaySen']);
        $this->assertSame(120, $rows['CHK-OK']['panelDefaultSen']);
        $this->assertSame([['panel' => 'Synthetic Check Panel', 'amountSen' => 110]], $rows['CHK-OK']['panelPrices']);
        $this->assertFalse($rows['CHK-OK']['selfPayFromBranch']);

        $this->assertSame(['no_charge'], $rows['CHK-NOCHARGE']['checks']);
        $this->assertSame(['no_self_pay'], $rows['CHK-NOSELF']['checks']);
        $this->assertSame(['no_panel_default', 'zero_price'], $rows['CHK-FREE']['checks']);
        $this->assertSame(['unit_differs'], $rows['CHK-UNIT']['checks']);
        $this->assertSame('tablet', $rows['CHK-UNIT']['chargeUnit']);
        $this->assertSame('capsule', $rows['CHK-UNIT']['orderUnit']);
        $this->assertSame(['charge_inactive'], $rows['CHK-INACTIVE-CHARGE']['checks']);
        $this->assertArrayNotHasKey('CHK-RETIRED', $rows, 'Inactive catalogue items are not checked.');
    }

    public function test_the_branch_self_pay_book_wins_over_the_organisation_book_as_in_billing(): void
    {
        $organisationBook = $this->book('self_pay');
        $branchBook = $this->book('self_pay', $this->branch);
        $panelDefault = $this->book('panel');
        $medicine = $this->medicine('CHK-BRANCH');
        $charge = $this->references->createMedicineCharge($this->supervisor, $medicine, ['code' => 'CHK-BRANCH', 'display_name' => 'Synthetic CHK-BRANCH']);
        $this->publish($organisationBook, $charge, 500);
        $this->publish($branchBook, $charge, 450);
        $this->publish($panelDefault, $charge, 400);

        $row = $this->rows()['CHK-BRANCH'];
        $this->assertSame(450, $row['selfPaySen']);
        $this->assertTrue($row['selfPayFromBranch']);

        $otherBranch = Branch::query()->where('organisation_id', $this->organisation->id)->whereKeyNot($this->branch->id)->orderBy('id')->firstOrFail();
        $other = app(PriceListCheckService::class)->check($this->supervisor, $otherBranch, [], 1);
        $otherRow = collect($other['rows'])->firstWhere('code', 'CHK-BRANCH');
        $this->assertSame(500, $otherRow['selfPaySen'], 'Another branch uses the organisation price.');
        $this->assertFalse($otherRow['selfPayFromBranch']);
    }

    public function test_only_the_latest_published_price_counts(): void
    {
        $selfPay = $this->book('self_pay');
        $panelDefault = $this->book('panel');
        $medicine = $this->medicine('CHK-LATEST');
        $charge = $this->references->createMedicineCharge($this->supervisor, $medicine, ['code' => 'CHK-LATEST', 'display_name' => 'Synthetic CHK-LATEST']);
        $this->publish($selfPay, $charge, 0);
        $this->publish($selfPay, $charge, 275);
        $this->publish($panelDefault, $charge, 250);

        $row = $this->rows()['CHK-LATEST'];
        $this->assertSame(275, $row['selfPaySen']);
        $this->assertSame([], $row['checks']);
    }

    public function test_services_and_the_consultation_are_checked_and_filters_and_summary_work(): void
    {
        $service = ClinicalServiceCatalogueItem::factory()->create([
            'organisation_id' => $this->organisation->id,
            'code' => 'CHK-SVC',
            'display_name' => 'Synthetic dressing check',
            'order_unit' => 'session',
            'is_active' => true,
        ]);
        $selfPay = $this->book('self_pay');
        $consultation = $this->references->createConsultationCharge($this->supervisor, ['code' => 'CONSULT-CHK', 'display_name' => 'Synthetic consultation']);
        $this->publish($selfPay, $consultation, 3000);

        $rows = $this->rows();
        $this->assertSame(['no_panel_default'], $rows['CONSULTATION']['checks']);
        $this->assertSame('consultation', $rows['CONSULTATION']['type']);
        $this->assertSame(['no_charge'], $rows['CHK-SVC']['checks']);
        $this->assertSame('service', $rows['CHK-SVC']['type']);

        $this->assertSame(['CHK-SVC'], array_keys($this->rows(['type' => 'service'])));
        $this->assertSame(['CHK-SVC'], array_keys($this->rows(['search' => 'dressing'])));
        $this->assertArrayHasKey('CONSULTATION', $this->rows(['attention' => true]));

        $result = app(PriceListCheckService::class)->check($this->supervisor, $this->branch, ['type' => 'service'], 1);
        $this->assertSame(1, $result['total']);
        $this->assertSame(2, $result['summary']['checked'], 'The summary always covers the whole list.');
        $this->assertSame(2, $result['summary']['attention']);
        $this->assertSame(1, $result['summary']['noSelfPay']);
        $this->assertSame(2, $result['summary']['noPanelDefault']);
        $this->assertSame($service->code, $result['rows'][0]['code']);
    }

    public function test_the_page_is_read_only_and_limited_to_pricing_reference_holders(): void
    {
        $this->medicine('CHK-PAGE');
        $tables = ['price_entries', 'price_books', 'charge_definitions', 'medicine_catalogue_items', 'audit_logs'];
        $before = collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()]);

        $this->get(route('pricing.price-check', ['attention' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Pricing/PriceCheck')
                ->where('branch.id', $this->branch->id)
                ->where('filters.attention', true)
                ->where('result.rows.0.code', 'CONSULTATION')
                ->has('result.summary'));

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "$table must not change");
        }

        foreach (['director', 'finance_officer', 'administrator'] as $role) {
            $holder = $this->actor($role);
            $this->selectBranch($holder);
            $this->get(route('pricing.price-check'))->assertOk();
        }
        foreach (['ca', 'resident_doctor', 'panel_officer', 'technical_admin'] as $role) {
            $other = $this->actor($role);
            $this->selectBranch($other);
            $this->get(route('pricing.price-check'))->assertForbidden();
        }

        $this->expectException(AuthorizationException::class);
        app(PriceListCheckService::class)->check($this->actor('ca'), $this->branch, [], 1);
    }

    public function test_invalid_filters_are_refused(): void
    {
        $this->get(route('pricing.price-check', ['type' => 'invoice']))->assertSessionHasErrors('type');
        $this->get(route('pricing.price-check', ['search' => str_repeat('x', 101)]))->assertSessionHasErrors('search');
    }
}
