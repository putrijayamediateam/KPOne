<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `stock_alerts`: low stock, expiring soon and expired on hand at a branch (counts and the first 25 items). */
final class StockAlertsQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'stock_alerts';
    }

    public function sources(): array
    {
        return ['inventory.stock_review'];
    }

    public function rules(): array
    {
        return [];
    }

    public function definitions(): string
    {
        return 'Counts are batches or location lines. Low stock: below the reorder level. Expiring soon: each item\'s own alert window, capped at 90 days. Expired: still on hand past the expiry date. Quantities are in each item\'s stock unit. Shows the first 25 items with an alert, ordered by code.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        $result = $reads->call('inventory.stock_review', $branch);
        [$items, $limits] = $this->cap(array_map(fn (array $sku): array => [
            'skuCode' => $this->label($sku['skuCode'] ?? ''),
            'item' => $this->label($sku['item'] ?? ''),
            'stockUnit' => $this->label($sku['stockUnit'] ?? ''),
            'lowStockLocations' => (int) ($sku['lowStockLocations'] ?? 0),
            'expiringBatches' => (int) ($sku['expiringBatches'] ?? 0),
            'expiringQuantity' => (string) ($sku['expiringQuantity'] ?? '0.000'),
            'expiredBatches' => (int) ($sku['expiredBatches'] ?? 0),
            'expiredQuantity' => (string) ($sku['expiredQuantity'] ?? '0.000'),
        ], $result['skus'] ?? []));

        return [
            'branch' => $result['branch'] ?? $branch?->code,
            'date' => $result['date'] ?? null,
            'expiryWindowDays' => $result['expiryWindowDays'] ?? null,
            'totals' => $result['totals'] ?? [],
            'items' => $items,
            'limits' => ['items' => $limits],
        ];
    }
}
