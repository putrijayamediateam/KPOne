<?php

namespace App\Domain\Agents\Connector\Mcp;

use App\Domain\Agents\Ask\AskSwitch;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('KPOne')]
#[Version('0.1.0')]
#[Instructions('Read-only totals from KPOne for the signed-in Director. No patient or staff personal data is ever returned. Every tool is read-only except save_answer, which only saves one answer to a question the Director asked in the KPOne panel. Figures are for the Director\'s own branches. All data is synthetic until the privacy review is done.')]
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

    /** CHAT-2: the one write tool exists in the list only while "Ask through Claude" is switched on (the eleven read tools above never change). */
    protected function boot(): void
    {
        if (AskSwitch::enabled()) {
            $this->tools[] = SaveAnswerMcpTool::class;
        }
    }

    protected array $prompts = [];
}
