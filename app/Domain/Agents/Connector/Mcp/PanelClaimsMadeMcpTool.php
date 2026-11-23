<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\PanelClaimsMadeQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('panel_claims_made')]
#[Description('Panel claims created in KPOne in a period (default today), per branch, status and Panel, with amounts in sen. Read-only.')]
#[IsReadOnly]
final class PanelClaimsMadeMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(PanelClaimsMadeQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->branchField($schema), $this->rangeFields($schema, 'Default: today.'));
    }
}
