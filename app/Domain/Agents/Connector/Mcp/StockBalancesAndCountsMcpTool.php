<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\StockBalancesAndCountsQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('stock_balances_and_counts')]
#[Description('Usable stock against the reorder level at each branch (first 25 items below), and stock counts due. Read-only.')]
#[IsReadOnly]
final class StockBalancesAndCountsMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(StockBalancesAndCountsQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->branchField($schema);
    }
}
