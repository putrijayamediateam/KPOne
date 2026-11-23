<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\VisitsByStateQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('visits_by_state')]
#[Description('Visits registered on a branch day by state: unfinished, completed and cancelled, with totals. No patient is named. Read-only.')]
#[IsReadOnly]
final class VisitsByStateMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(VisitsByStateQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->branchField($schema), $this->dateField($schema));
    }
}
