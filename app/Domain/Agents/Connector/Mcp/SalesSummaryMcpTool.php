<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\SalesSummaryQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('sales_summary')]
#[Description('Net invoice sales for a period against the previous period, in sen, per branch. Read-only.')]
#[IsReadOnly]
final class SalesSummaryMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(SalesSummaryQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->branchField($schema), $this->rangeFields($schema, 'Default: yesterday.'));
    }
}
