<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Connector\ConnectorQuery;
use App\Domain\Agents\Connector\Queries\ClinicDaySummaryQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('clinic_day_summary')]
#[Description('Visits registered, seen, waiting now and average waiting and consultation minutes for a branch day. No patient or doctor is named. Read-only.')]
#[IsReadOnly]
final class ClinicDaySummaryMcpTool extends QueryMcpTool
{
    protected function query(): ConnectorQuery
    {
        return app(ClinicDaySummaryQuery::class);
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge($this->branchField($schema), $this->dateField($schema));
    }
}
