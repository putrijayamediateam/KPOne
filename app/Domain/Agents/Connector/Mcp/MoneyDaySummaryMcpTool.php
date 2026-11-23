<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\MoneyDaySummaryQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('money_day_summary')]
#[Description('Net sales, receipts by payment method (Cash and Non-cash), discounts, Panel approved and pay-later due for a branch day, in sen. Read-only.')]
#[IsReadOnly]
final class MoneyDaySummaryMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(MoneyDaySummaryQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->branchField($schema), $this->dateField($schema));
    }
}
