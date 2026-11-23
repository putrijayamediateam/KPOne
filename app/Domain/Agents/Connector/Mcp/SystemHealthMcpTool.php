<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\SystemHealthQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('system_health')]
#[Description('Counts only for the KPOne server: error log lines, failed scheduled runs, pending database changes, failed queue jobs. Read-only.')]
#[IsReadOnly]
final class SystemHealthMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(SystemHealthQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['since' => $schema->string()->description('Count errors from this date, YYYY-MM-DD. Leave out for the last 24 hours.')];
    }
}
