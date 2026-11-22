<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\QueueNowQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('queue_now')]
#[Description('The consultation queue right now at each branch: waiting, not ready to call, serving and on hold (counts). Read-only.')]
#[IsReadOnly]
final class QueueNowMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(QueueNowQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->branchField($schema);
    }
}
