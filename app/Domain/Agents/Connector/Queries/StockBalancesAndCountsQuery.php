<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;
use Carbon\CarbonImmutable;

/** `stock_balances_and_counts`: usable quantity against the reorder level, and stock counts due, at a branch. */
final class StockBalancesAndCountsQuery implements ConnectorQuery
{
    use ShapesAnswers;

    /** A stocked item and location with no completed governed count in this many branch-local days is "due". */
    public const COUNT_DUE_DAYS = 30;

    public function name(): string
    {
        return 'stock_balances_and_counts';
    }

    public function sources(): array
    {
        return ['inventory.branch_balances', 'inventory.stock_counts'];
    }

    public function rules(): array
    {
        return [];
    }

    public function definitions(): string
    {
        return 'Usable quantity (eligible: not expired, held or quarantined) against the reorder level, per item at this branch, in each item\'s stock unit; the first 25 items below their level are listed. Counts due: stocked item and location pairs with no completed stock count in the last '.self::COUNT_DUE_DAYS.' days. Open count cases and unresolved variances are totals for the branch.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        assert($branch !== null);
        $balances = $reads->call('inventory.branch_balances', $branch);
        $below = [];
        $tracked = 0;
        foreach ($balances['skus'] ?? [] as $sku) {
            foreach ($sku['perBranch'] ?? [] as $row) {
                if (($row['branch'] ?? null) !== $branch->code) {
                    continue;
                }
                $level = $this->milli($row['reorderLevel'] ?? '0');
                if ($level <= 0) {
                    continue;
                }
                $tracked++;
                if ($this->milli($row['eligible'] ?? '0') < $level) {
                    $below[] = [
                        'skuCode' => $this->label($sku['skuCode'] ?? ''),
                        'item' => $this->label($sku['item'] ?? ''),
                        'stockUnit' => $this->label($sku['stockUnit'] ?? ''),
                        'onHand' => (string) ($row['onHand'] ?? '0.000'),
                        'eligible' => (string) ($row['eligible'] ?? '0.000'),
                        'reorderLevel' => (string) ($row['reorderLevel'] ?? '0.000'),
                    ];
                }
            }
        }
        [$items, $limits] = $this->cap($below);

        $counts = $reads->call('inventory.stock_counts', $branch);
        $today = CarbonImmutable::parse((string) ($counts['date'] ?? now($branch->timezone)->toDateString()));
        $due = 0;
        foreach ($counts['rows'] ?? [] as $row) {
            $last = $row['lastCompletedCountDate'] ?? null;
            if (($row['stocked'] ?? false) && ($last === null || CarbonImmutable::parse((string) $last)->diffInDays($today) >= self::COUNT_DUE_DAYS)) {
                $due++;
            }
        }

        return [
            'branch' => $branch->code,
            'date' => $counts['date'] ?? null,
            'itemsWithReorderLevel' => $tracked,
            'itemsBelowReorderLevel' => count($below),
            'belowReorderLevel' => $items,
            'countsDue' => $due,
            'openCountCases' => (int) ($counts['totals']['openCases'] ?? 0),
            'unresolvedCountVariances' => (int) ($counts['totals']['unresolvedVariances'] ?? 0),
            'limits' => ['belowReorderLevel' => $limits],
        ];
    }

    private function milli(mixed $value): int
    {
        $text = trim((string) $value);
        $negative = str_starts_with($text, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($text, '+-'), 2), 2, '');
        $milli = (int) $whole * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$milli : $milli;
    }
}
