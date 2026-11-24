<?php

namespace App\Domain\Agents\Ask;

/** The "Ask through Claude" kill switch (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md section 4). Read on every request, default OFF. */
final class AskSwitch
{
    public const SCOPE = 'mcp:answer';

    /** On only when its own switch AND the connector switch are on (the answer comes back through the connector). */
    public static function enabled(): bool
    {
        return (bool) config('agents.ask_claude_enabled', false) && (bool) config('agents.connector_enabled', false);
    }

    /** Whether a token must carry the mcp:answer scope to save an answer (default yes; a conscious owner choice can lift it for the trial). */
    public static function requiresScope(): bool
    {
        return (bool) config('agents.ask_claude_require_scope', true);
    }
}
