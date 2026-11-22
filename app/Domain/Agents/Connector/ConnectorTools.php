<?php

namespace App\Domain\Agents\Connector;

use App\Domain\Agents\Tools\AgentTool;

/** The connector's data sources and questions. CN-1 had one; CN-2 adds the seven that read existing data sources (design note section 6). */
final class ConnectorTools
{
    /** @return list<class-string<AgentTool>> data sources the connector itself adds to the registry */
    public static function classes(): array
    {
        return [ListBranchesTool::class, Sources\VisitsByStateTool::class, Sources\QueueNowTool::class, Sources\PanelClaimsMadeTool::class];
    }

    /** @return list<class-string<ConnectorQuery>> */
    public static function queries(): array
    {
        return [
            Queries\ClinicDaySummaryQuery::class,
            Queries\MoneyDaySummaryQuery::class,
            Queries\SalesSummaryQuery::class,
            Queries\PanelClaimsOutstandingQuery::class,
            Queries\StockAlertsQuery::class,
            Queries\StockBalancesAndCountsQuery::class,
            Queries\SystemHealthQuery::class,
            Queries\VisitsByStateQuery::class,
            Queries\QueueNowQuery::class,
            Queries\PanelClaimsMadeQuery::class,
        ];
    }

    /** @return list<string> the connector agent's allowlist: its own data sources and every source its questions read */
    public static function codes(): array
    {
        $codes = array_map(fn (string $class): string => app($class)->code(), self::classes());
        foreach (self::queries() as $query) {
            array_push($codes, ...app($query)->sources());
        }

        return array_values(array_unique($codes));
    }
}
