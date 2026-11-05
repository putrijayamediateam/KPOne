<?php

namespace App\Domain\Visit\Billing\Services;

use App\Domain\Clinical\Models\ClinicalServiceCatalogueItem;
use App\Domain\Clinical\Models\MedicineCatalogueItem;
use App\Domain\Organisation\Models\Branch;
use App\Domain\Visit\Billing\Models\ChargeDefinition;
use App\Domain\Visit\Billing\Models\PriceBook;
use App\Domain\Visit\Billing\Models\PriceEntry;
use App\Domain\Visit\Models\Panel;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * CAT-2: read-only price list check.
 *
 * Lists every active medicine and clinical service (plus the consultation charge) with the prices billing would
 * use, and flags what would make billing refuse a line or charge nothing. It follows PriceResolutionService:
 * a Self-pay line uses the active branch's Self-pay book before the organisation one; a Panel line uses the
 * Panel's own book before the default Panel book; only active charges, active books and prices already in
 * effect count; and the charge unit must equal the item's order unit. It writes nothing.
 */
final class PriceListCheckService
{
    public const PER_PAGE = 25;

    public const CHECKS = ['no_charge', 'charge_inactive', 'no_self_pay', 'unit_differs', 'no_panel_default', 'zero_price'];

    /**
     * @param  array{search?: ?string, type?: ?string, attention?: bool}  $filters
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     summary: array{checked: int, attention: int, noSelfPay: int, noPanelDefault: int},
     *     total: int, currentPage: int, lastPage: int
     * }
     */
    public function check(User $actor, Branch $branch, array $filters, int $page): array
    {
        if (! $actor->can(PricingReferenceAdministrationService::PERMISSION)
            || (int) $branch->organisation_id !== (int) $actor->organisation_id) {
            throw new AuthorizationException;
        }
        $organisationId = (int) $actor->organisation_id;

        $items = collect($this->items($organisationId));
        $charges = ChargeDefinition::query()
            ->where('organisation_id', $organisationId)
            ->whereIn('source_key', $items->pluck('sourceKey')->all())
            ->get(['id', 'source_key', 'unit', 'is_active'])
            ->keyBy('source_key');

        $books = PriceBook::query()
            ->where('organisation_id', $organisationId)
            ->where('currency', 'MYR')
            ->where('is_active', true)
            ->where(function ($query) use ($branch): void {
                $query->where(function ($selfPay) use ($branch): void {
                    $selfPay->where('price_tier', 'self_pay')
                        ->where(fn ($scope) => $scope->whereNull('branch_id')->orWhere('branch_id', $branch->id));
                })->orWhere(fn ($panel) => $panel->where('price_tier', 'panel')->whereNull('branch_id'));
            })
            ->orderBy('id')
            ->get(['id', 'price_tier', 'branch_id', 'panel_id']);
        $activePanels = Panel::query()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->keyBy('id');

        $branchSelfPay = $books->first(fn (PriceBook $book): bool => $book->price_tier === 'self_pay' && (int) $book->branch_id === (int) $branch->id);
        $organisationSelfPay = $books->first(fn (PriceBook $book): bool => $book->price_tier === 'self_pay' && $book->branch_id === null);
        $panelDefault = $books->first(fn (PriceBook $book): bool => $book->price_tier === 'panel' && $book->panel_id === null);
        /** @var Collection<int, PriceBook> $panelBooks */
        $panelBooks = $books
            ->filter(fn (PriceBook $book): bool => $book->price_tier === 'panel' && $book->panel_id !== null && $activePanels->has((int) $book->panel_id))
            ->unique('panel_id')
            ->values();

        $latest = $this->latestPrices(
            $organisationId,
            $charges->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            $books->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
        );
        $priceIn = fn (?PriceBook $book, ?ChargeDefinition $charge): ?int => $book && $charge
            ? ($latest[(int) $book->id][(int) $charge->id] ?? null)
            : null;

        $rows = $items->map(function (array $item) use ($charges, $branchSelfPay, $organisationSelfPay, $panelDefault, $panelBooks, $activePanels, $priceIn): array {
            $charge = $charges->get($item['sourceKey']);
            $branchPrice = $priceIn($branchSelfPay, $charge);
            $selfPay = $branchPrice ?? $priceIn($organisationSelfPay, $charge);
            $panelDefaultPrice = $priceIn($panelDefault, $charge);
            $panelPrices = $panelBooks
                ->map(fn (PriceBook $book): array => [
                    'panel' => (string) $activePanels->get((int) $book->panel_id)?->name,
                    'amountSen' => $priceIn($book, $charge),
                ])
                ->filter(fn (array $price): bool => $price['amountSen'] !== null)
                ->values()
                ->all();

            $checks = [];
            if ($charge === null) {
                $checks[] = 'no_charge';
            } else {
                if (! $charge->is_active) {
                    $checks[] = 'charge_inactive';
                }
                if ($selfPay === null) {
                    $checks[] = 'no_self_pay';
                }
                if ($item['orderUnit'] !== null && $charge->unit !== $item['orderUnit']) {
                    $checks[] = 'unit_differs';
                }
                if ($panelDefaultPrice === null) {
                    $checks[] = 'no_panel_default';
                }
                $zero = $selfPay === 0 || $panelDefaultPrice === 0
                    || collect($panelPrices)->contains(fn (array $price): bool => $price['amountSen'] === 0);
                if ($zero) {
                    $checks[] = 'zero_price';
                }
            }

            return [
                'type' => $item['type'],
                'code' => $item['code'],
                'displayName' => $item['displayName'],
                'orderUnit' => $item['orderUnit'],
                'chargeUnit' => $charge?->unit,
                'selfPaySen' => $selfPay,
                'selfPayFromBranch' => $branchPrice !== null,
                'panelDefaultSen' => $panelDefaultPrice,
                'panelPrices' => $panelPrices,
                'checks' => $checks,
            ];
        });

        $summary = [
            'checked' => $rows->count(),
            'attention' => $rows->filter(fn (array $row): bool => $row['checks'] !== [])->count(),
            'noSelfPay' => $rows->filter(fn (array $row): bool => in_array('no_self_pay', $row['checks'], true)
                || in_array('no_charge', $row['checks'], true))->count(),
            'noPanelDefault' => $rows->filter(fn (array $row): bool => in_array('no_panel_default', $row['checks'], true)
                || in_array('no_charge', $row['checks'], true))->count(),
        ];

        $filtered = $rows->filter(function (array $row) use ($filters): bool {
            $type = $filters['type'] ?? null;
            if ($type !== null && $type !== '' && $row['type'] !== $type) {
                return false;
            }
            if (($filters['attention'] ?? false) && $row['checks'] === []) {
                return false;
            }
            $needle = mb_strtolower(trim((string) ($filters['search'] ?? '')));

            return $needle === ''
                || str_contains(mb_strtolower($row['code']), $needle)
                || str_contains(mb_strtolower($row['displayName']), $needle);
        })->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $currentPage = min(max(1, $page), $lastPage);

        return [
            'rows' => $filtered->forPage($currentPage, self::PER_PAGE)->values()->all(),
            'summary' => $summary,
            'total' => $total,
            'currentPage' => $currentPage,
            'lastPage' => $lastPage,
        ];
    }

    /** @return array<int, array{type: string, sourceKey: string, code: string, displayName: string, orderUnit: ?string}> */
    private function items(int $organisationId): array
    {
        $consultation = collect([[
            'type' => 'consultation',
            'sourceKey' => 'consultation',
            'code' => 'CONSULTATION',
            'displayName' => 'Consultation',
            'orderUnit' => null,
        ]]);
        $medicines = MedicineCatalogueItem::query()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('id')
            ->get(['id', 'code', 'display_name', 'order_unit'])
            ->map(fn (MedicineCatalogueItem $medicine): array => [
                'type' => 'medicine',
                'sourceKey' => 'medicine:'.$medicine->id,
                'code' => (string) $medicine->code,
                'displayName' => (string) $medicine->display_name,
                'orderUnit' => (string) $medicine->order_unit,
            ]);
        $services = ClinicalServiceCatalogueItem::query()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('id')
            ->get(['id', 'code', 'display_name', 'order_unit'])
            ->map(fn (ClinicalServiceCatalogueItem $service): array => [
                'type' => 'service',
                'sourceKey' => 'service:'.$service->id,
                'code' => (string) $service->code,
                'displayName' => (string) $service->display_name,
                'orderUnit' => (string) $service->order_unit,
            ]);

        return [...$consultation->all(), ...$medicines->all(), ...$services->all()];
    }

    /**
     * The latest version in effect of each charge in each book, as PriceResolutionService picks it.
     *
     * @param  array<int, int>  $chargeIds
     * @param  array<int, int>  $bookIds
     * @return array<int, array<int, int>> unit price in sen, by book id then charge id
     */
    private function latestPrices(int $organisationId, array $chargeIds, array $bookIds): array
    {
        if ($chargeIds === [] || $bookIds === []) {
            return [];
        }
        $prices = [];
        $versions = [];
        PriceEntry::query()
            ->where('organisation_id', $organisationId)
            ->whereIn('charge_definition_id', $chargeIds)
            ->whereIn('price_book_id', $bookIds)
            ->where('effective_at', '<=', now()->utc())
            ->get(['price_book_id', 'charge_definition_id', 'version', 'unit_price_sen'])
            ->each(function (PriceEntry $entry) use (&$prices, &$versions): void {
                $book = (int) $entry->price_book_id;
                $charge = (int) $entry->charge_definition_id;
                if (($versions[$book][$charge] ?? -1) < (int) $entry->version) {
                    $versions[$book][$charge] = (int) $entry->version;
                    $prices[$book][$charge] = (int) $entry->unit_price_sen;
                }
            });

        return $prices;
    }
}
