<?php

namespace App\Domain\Agents\Ask;

use App\Domain\Agents\AgentPermissions;
use App\Models\User;

/** The "Ask through Claude" kill switch (docs/agents/CHAT-2-ASK-THROUGH-CLAUDE-DESIGN.md section 4). Read on every request, default OFF. */
final class AskSwitch
{
    /**
     * Which Ask KPOne paths the overview page may offer this person: the API chatbox and "Ask through Claude", each only while its own
     * switch is on and the person holds the use permission. (The chat switch is read from its setting here, so pages outside the agent
     * platform never name the chat classes.)
     *
     * @return array{chat: bool, claude: bool}
     */
    public static function pathsFor(User $user): array
    {
        $may = $user->is_active && $user->can(AgentPermissions::CHAT_USE);

        return ['chat' => $may && (bool) config('agents.chat_enabled', false), 'claude' => $may && self::enabled()];
    }

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
