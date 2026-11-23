<?php

namespace App\Domain\Agents\Connector\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('KPOne')]
#[Version('0.1.0')]
#[Instructions('Read-only totals from KPOne for the signed-in Director. No patient or staff personal data is ever returned. Every tool is read-only. Figures are for the Director\'s own branches. All data is synthetic until the privacy review is done.')]
final class ConnectorServer extends Server
{
    protected array $tools = [
        ListBranchesMcpTool::class,
        VisitsByStateMcpTool::class,
        QueueNowMcpTool::class,
        PanelClaimsMadeMcpTool::class,
        ClinicDaySummaryMcpTool::class,
        MoneyDaySummaryMcpTool::class,
        SalesSummaryMcpTool::class,
        PanelClaimsOutstandingMcpTool::class,
        StockAlertsMcpTool::class,
        StockBalancesAndCountsMcpTool::class,
        SystemHealthMcpTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
