<?php

namespace App\Domain\Agents\Connector;

/**
 * The connector kill switch (docs/agents/MCP-CONNECTOR-DESIGN.md section 7). Read on every request, never cached
 * in a property, so turning `AGENTS_CONNECTOR_ENABLED` off stops the connector at once. Default OFF.
 */
final class ConnectorSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('agents.connector_enabled', false);
    }
}
