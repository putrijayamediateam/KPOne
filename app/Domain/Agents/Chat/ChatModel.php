<?php

namespace App\Domain\Agents\Chat;

/**
 * The one door to the AI provider. Exactly one adapter lives in Providers/ (the only place that names the provider); tests bind a
 * fake. `turns` is a list of ['role' => 'user'|'assistant', 'content' => string|list of blocks]; `tools` is a list of
 * ['name', 'description', 'input_schema'].
 */
interface ChatModel
{
    /**
     * @param  list<array<string, mixed>>  $turns
     * @param  list<array<string, mixed>>  $tools
     *
     * @throws ChatProviderFailed
     */
    public function complete(string $system, array $turns, array $tools): ChatReply;
}
