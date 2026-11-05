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
    public function __construct(private readonly PriceResolutionService $resolver) {}

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

        $panelBooks = $books
            ->filter(fn (PriceBook $book): bool => $book->price_tier === 'panel' && $book->panel_id !== null && $activePanels->has((int) $book->panel_id))
            ->unique('panel_id')
            ->sortBy(fn (PriceBook $book): string => mb_strtolower((string) $activePanels->get((int) $book->panel_id)?->name))
            ->values();

        $entries = $charges->isEmpty() || $books->isEmpty() ? collect() : PriceEntry::query()
            ->where('organisation_id', $organisationId)
            ->whereIn('charge_definition_id', $charges->pluck('id'))
            ->whereIn('price_book_id', $books->pluck('id'))
            ->where('effective_at', '<=', now()->utc())
            ->orderBy('id')
            ->get(['id', 'price_book_id', 'charge_definition_id', 'version', 'unit_price_sen']);
        $bookById = $books->keyBy('id');
        $branchId = (int) $branch->id;
        // Billing's own choice (PriceResolutionService::select), so this page and a real bill always agree.
        $resolver = $this->resolver;
        // Each charge only needs its own entries; the choice itself is unchanged.
        $entriesByCharge = $entries->groupBy('charge_definition_id');
        $choose = fn (?ChargeDefinition $charge, bool $panel, ?int $panelId): ?PriceEntry => $resolver->select(
            $books,
            $charge === null ? collect() : $entriesByCharge->get((int) $charge->id, collect()),
            $charge,
            $panel,
            $panelId,
            $branchId,
        );

        $rows = $items->map(function (array $item) use ($charges, $panelBooks, $activePanels, $bookById, $branchId, $choose): array {
            $charge = $charges->get($item['sourceKey']);
            $selfPayEntry = $choose($charge, false, null);
            $selfPay = $selfPayEntry?->unit_price_sen === null ? null : (int) $selfPayEntry->unit_price_sen;
            $selfPayFromBranch = $selfPayEntry !== null
                && (int) $bookById->get((int) $selfPayEntry->price_book_id)?->branch_id === $branchId;
            $panelDefaultEntry = $choose($charge, true, null);
            $panelDefaultPrice = $panelDefaultEntry === null ? null : (int) $panelDefaultEntry->unit_price_sen;
            $panelPrices = $panelBooks
                ->map(function (PriceBook $book) use ($choose, $charge, $activePanels): ?array {
                    $entry = $choose($charge, true, (int) $book->panel_id);

                    // Only a Panel's own price is listed; a fall-back to the default Panel price is not.
                    return $entry !== null && (int) $entry->price_book_id === (int) $book->id
                        ? ['panel' => (string) $activePanels->get((int) $book->panel_id)?->name, 'amountSen' => (int) $entry->unit_price_sen]
                        : null;
                })
                ->filter()
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
                'selfPayFromBranch' => $selfPayFromBranch,
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
            // Billing bills the consultation in the unit 'consultation' (BillingSourceService).
            'orderUnit' => 'consultation',
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
}
