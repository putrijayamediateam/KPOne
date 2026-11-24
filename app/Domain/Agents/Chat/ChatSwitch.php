<?php

namespace App\Domain\Agents\Chat;

/** The chatbox kill switch (docs/agents/AI-CHATBOX-DESIGN.md section 5). Read on every request, default OFF. */
final class ChatSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('agents.chat_enabled', false);
    }
}
