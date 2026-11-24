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

    /*
     | "Ask through Claude" (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md section 4). While false, the panel path is hidden, its routes answer
     | 404 and the connector does not offer save_answer. It also needs the connector switch. Default OFF: set AGENTS_ASK_CLAUDE_ENABLED in the
     | server environment, never in code, and only on a server that holds fictional data until the privacy review.
     */
    'ask_claude_enabled' => (bool) env('AGENTS_ASK_CLAUDE_ENABLED', false),

    /*
     | save_answer needs a token with the mcp:answer scope (a connection made before this existed stays read-only until the Director reconnects
     | once). Only the owner may set this to false, as a conscious choice, for a trial where the connector app does not request the scope.
     */
    'ask_claude_require_scope' => (bool) env('AGENTS_ASK_CLAUDE_REQUIRE_SCOPE', true),

    // Minutes a pending "Ask through Claude" request stays open.
    'ask_expiry_minutes' => 30,
];
