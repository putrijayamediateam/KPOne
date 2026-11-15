<?php

namespace App\Domain\Agents\Tools;

use InvalidArgumentException;
use LogicException;

/**
 * The wave 1 data sources (contract section 5). AG-4 provides the nine in TOOLS. Another slice adds its
 * data source without editing this file by tagging the class in a service provider:
 * `$this->app->tag([SystemHealthTool::class], AgentToolRegistry::TAG);` (AG-10, `system.health`).
 */
final class AgentToolRegistry
{
    public const TAG = 'agents.tools';

    /** @var list<class-string<AgentTool>> */
    public const TOOLS = [
        InsightsTodayTool::class,
        InsightsSalesSummaryTool::class,
        InventoryStockReviewTool::class,
        InventoryBranchBalancesTool::class,
        InventoryDispensingActivityTool::class,
        InventoryStockCountsTool::class,
        ClinicDaySummaryTool::class,
        MoneyDaySummaryTool::class,
        PanelClaimsSummaryTool::class,
    ];

    /** @return array<string, AgentTool> keyed by tool code */
    public function all(): array
    {
        $tools = [];
        foreach ([...array_map(fn (string $class): AgentTool => app($class), self::TOOLS), ...app()->tagged(self::TAG)] as $tool) {
            if (! $tool instanceof AgentTool || isset($tools[$tool->code()])) {
                throw new LogicException('Agent data sources must implement AgentTool and have unique codes.');
            }
            $tools[$tool->code()] = $tool;
        }

        return $tools;
    }

    public function get(string $code): AgentTool
    {
        return $this->all()[$code] ?? throw new InvalidArgumentException("Unknown agent data source [{$code}].");
    }
}
