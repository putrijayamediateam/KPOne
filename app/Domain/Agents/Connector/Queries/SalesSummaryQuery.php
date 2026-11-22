<?php

namespace App\Domain\Agents\Connector\Queries;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\ConnectorReads;
use App\Domain\Organisation\Models\Branch;

/** `sales_summary`: net invoice sales for a period against the previous period (Insights Sales). */
final class SalesSummaryQuery implements ConnectorQuery
{
    use ShapesAnswers;

    public function name(): string
    {
        return 'sales_summary';
    }

    public function sources(): array
    {
        return ['insights.sales_summary'];
    }

    public function rules(): array
    {
        return $this->rangeRules();
    }

    public function definitions(): string
    {
        return 'Amounts are in sen. Net is invoice sales after discounts and surcharges by invoice finalisation date; gross is before them. Unique visitors are distinct patients invoiced in the period. The previous period is the same length just before. Default period: yesterday. At most 31 days.';
    }

    public function organisationWide(): bool
    {
        return false;
    }

    public function run(ConnectorReads $reads, ?Branch $branch, array $input): array
    {
        $result = $reads->call('insights.sales_summary', $branch, array_intersect_key($input, ['from' => 1, 'to' => 1]));
        unset($result['scope']);

        return ['branch' => $branch?->code, ...$result];
    }
}
