<?php

return [
    /*
     | Approval that prepares work (docs/agents/CONTRACT.md section 15). While false, every approval is decision only
     | and no agent suggestion prepares a stock request or purchase request. The owner turns it on; it stays off in
     | week one of the pilot. Set AGENTS_PREPARE_ENABLED in the server environment, never in code.
     */
    'prepare_enabled' => (bool) env('AGENTS_PREPARE_ENABLED', false),

    /*
     | The KPOne connector (docs/agents/MCP-CONNECTOR-DESIGN.md section 7). While false, the MCP and OAuth routes answer 404
     | and every connector token stops working. Default OFF: the person who manages the server environment turns it on with
     | AGENTS_CONNECTOR_ENABLED, never in code, and only on a server that holds fictional data until the privacy review.
     */
    'connector_enabled' => (bool) env('AGENTS_CONNECTOR_ENABLED', false),

    /*
     | The AI chatbox (docs/agents/AI-CHATBOX-DESIGN.md section 5). While false, the chatbox is not shown and its routes answer 404.
     | Default OFF: the person who manages the server environment turns it on with AGENTS_CHAT_ENABLED, never in code, and only
     | on a server that holds fictional data until the privacy review. The provider key is AI_CHAT_API_KEY (see config/ai_chat.php).
     */
    'chat_enabled' => (bool) env('AGENTS_CHAT_ENABLED', false),
];
