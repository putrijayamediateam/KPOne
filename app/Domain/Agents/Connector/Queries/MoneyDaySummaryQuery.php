<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `money_day_summary`: net sales, receipts by method, discounts, Panel and pay-later amounts for a branch day. */
final class MoneyDaySummaryQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'money_day_summary';
    }

    public function sources(): array
    {
        return ['money.day_summary'];
    }

    public function rules(): array
    {
        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    public function definitions(): string
    {
        return 'All amounts are in sen (100 sen = RM 1). Net sales and discounts are by invoice finalisation date; receipts are by receipt date, split Cash and Non-cash, with reversals shown. Total received is Cash plus Non-cash only. Panel approved and pay-later due are by invoice finalisation date and are never part of total received.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        $entry = $reads->call('money.day_summary', $branch, array_intersect_key($input, ['date' => 1]))['branches'][0] ?? [];
        $entry['receiptsByMethod'] = array_map(fn (array $row): array => [
            'method' => $this->label($row['method'] ?? ''),
            'cash' => (bool) ($row['cash'] ?? false),
            'receiptsSen' => (int) ($row['receiptsSen'] ?? 0),
            'reversedSen' => (int) ($row['reversedSen'] ?? 0),
            'netSen' => (int) ($row['netSen'] ?? 0),
        ], array_slice($entry['receiptsByMethod'] ?? [], 0, self::MAX_ROWS));

        return $entry;
    }
}
