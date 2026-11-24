<?php

namespace App\Domain\Agents\Chat\Providers;

use App\Domain\Agents\Chat\ChatModel;
use App\Domain\Agents\Chat\ChatProviderFailed;
use App\Domain\Agents\Chat\ChatReply;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The provider adapter (Anthropic Messages API over Laravel's HTTP client; no SDK package). The key comes from the server's
 * environment secrets (config/ai_chat.php) and is sent only in a header. Nothing here logs the prompt, the answer or the key.
 */
final class HttpChatModel implements ChatModel
{
    public function complete(string $system, array $turns, array $tools): ChatReply
    {
        $key = config('ai_chat.api_key');
        if (! is_string($key) || $key === '') {
            throw new ChatProviderFailed('The AI provider is not configured.');
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => (string) config('ai_chat.api_version')])
                ->acceptJson()->asJson()->timeout((int) config('ai_chat.timeout_seconds', 40))
                ->post((string) config('ai_chat.endpoint'), array_filter([
                    'model' => config('ai_chat.model'),
                    'max_tokens' => (int) config('ai_chat.max_output_tokens', 1024),
                    'system' => $system,
                    'messages' => $turns,
                    'tools' => $tools === [] ? null : $tools,
                ], fn (mixed $value): bool => $value !== null));
        } catch (ConnectionException) {
            throw new ChatProviderFailed('The AI provider did not answer in time.');
        }
        if (! $response->successful()) {
            throw new ChatProviderFailed('The AI provider refused the request (status '.$response->status().').');
        }

        $body = $response->json();
        $blocks = is_array($body) && is_array($body['content'] ?? null) ? $body['content'] : null;
        if ($blocks === null) {
            throw new ChatProviderFailed('The AI provider answer could not be read.');
        }
        $text = '';
        $calls = [];
        $content = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
                $content[] = ['type' => 'text', 'text' => $block['text']];
            } elseif (($block['type'] ?? null) === 'tool_use' && is_string($block['id'] ?? null) && is_string($block['name'] ?? null)) {
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $calls[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => $input];
                $content[] = ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => (object) $input];
            }
        }
        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new ChatReply($text, $content, $calls, max(0, (int) ($usage['input_tokens'] ?? 0)), max(0, (int) ($usage['output_tokens'] ?? 0)));
    }
}
