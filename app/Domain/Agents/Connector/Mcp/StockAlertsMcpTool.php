<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\StockAlertsQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('stock_alerts')]
#[Description('Low stock, expiring soon and expired on hand at each branch: counts and the first 25 items. Read-only.')]
#[IsReadOnly]
final class StockAlertsMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(StockAlertsQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->branchField($schema);
    }
}
