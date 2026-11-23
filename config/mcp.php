<?php

/*
| Settings for laravel/mcp, used only by the KPOne connector (docs/agents/MCP-CONNECTOR-DESIGN.md). The package default
| accepts any redirect address for a self-registering client; KPOne accepts only the connector app's own callback
| origins, listed here or in MCP_REDIRECT_DOMAINS (comma separated, each ending in a slash), and no custom schemes.
| Confirm the exact addresses against the connector app's current help page when switching the connector on.
*/
return [
    'redirect_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_REDIRECT_DOMAINS',
        'https://claude.ai/,https://claude.com/',
    ))))),

    'custom_schemes' => [],

    'authorization_server' => null,

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    'clients' => [],
];
