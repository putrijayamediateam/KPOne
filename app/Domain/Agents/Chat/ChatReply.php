<?php

namespace App\Domain\Agents\Chat;

/**
 * What the model returned for one turn. `content` is the provider-neutral block list (text and tool_use blocks, the shape used by
 * the tool loop) so it can be echoed back with the tool results.
 */
final class ChatReply
{
    /**
     * @param  list<array<string, mixed>>  $content
     * @param  list<array{id: string, name: string, input: array<string, mixed>}>  $toolCalls
     */
    public function __construct(
        public readonly string $text,
        public readonly array $content,
        public readonly array $toolCalls,
        public readonly int $tokensIn,
        public readonly int $tokensOut,
    ) {}
}
