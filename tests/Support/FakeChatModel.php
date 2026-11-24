<?php

namespace Tests\Support;

use App\Domain\Agents\Chat\ChatModel;
use App\Domain\Agents\Chat\ChatProviderFailed;
use App\Domain\Agents\Chat\ChatReply;

/** Test double for the AI provider: replays scripted replies and records what it was asked. No network. */
final class FakeChatModel implements ChatModel
{
    /** @var list<array{system: string, turns: list<array<string, mixed>>, tools: list<array<string, mixed>>}> */
    public array $calls = [];

    /** @param list<ChatReply|ChatProviderFailed|callable> $script */
    public function __construct(private array $script = []) {}

    public static function text(string $text, int $in = 100, int $out = 50): ChatReply
    {
        return new ChatReply($text, [['type' => 'text', 'text' => $text]], [], $in, $out);
    }

    /** @param array<string, mixed> $input */
    public static function tool(string $name, array $input = [], string $id = 'toolu_1', int $in = 100, int $out = 20): ChatReply
    {
        return new ChatReply('', [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => (object) $input]], [['id' => $id, 'name' => $name, 'input' => $input]], $in, $out);
    }

    public function complete(string $system, array $turns, array $tools): ChatReply
    {
        $this->calls[] = ['system' => $system, 'turns' => $turns, 'tools' => $tools];
        $next = array_shift($this->script);
        if ($next instanceof ChatProviderFailed) {
            throw $next;
        }
        if (is_callable($next)) {
            $next = $next($turns);
        }

        return $next ?? self::text('Done.');
    }
}
