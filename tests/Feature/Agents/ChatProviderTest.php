<?php

namespace Tests\Feature\Agents;

use App\Domain\Agents\Chat\ChatProviderFailed;
use App\Domain\Agents\Chat\Providers\HttpChatModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** CHAT-1: the one provider adapter, tested without the network. */
class ChatProviderTest extends TestCase
{
    public function test_it_sends_the_key_only_in_a_header_and_reads_text_tool_calls_and_usage(): void
    {
        config(['ai_chat.api_key' => 'test-key-123', 'ai_chat.endpoint' => 'https://provider.test/v1/messages']);
        Http::fake(['provider.test/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Let me check.'], ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'queue_now', 'input' => ['branch' => 'CHERAS']]],
            'usage' => ['input_tokens' => 11, 'output_tokens' => 7],
        ])]);

        $reply = (new HttpChatModel)->complete('System rules', [['role' => 'user', 'content' => 'Hi']], [['name' => 'queue_now', 'description' => 'd', 'input_schema' => ['type' => 'object']]]);

        $this->assertSame('Let me check.', $reply->text);
        $this->assertSame([['id' => 'toolu_9', 'name' => 'queue_now', 'input' => ['branch' => 'CHERAS']]], $reply->toolCalls);
        $this->assertSame([11, 7], [$reply->tokensIn, $reply->tokensOut]);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('x-api-key', 'test-key-123') && ! str_contains($request->body(), 'test-key-123')
                && $request['system'] === 'System rules' && $request['messages'][0]['content'] === 'Hi' && $request['tools'][0]['name'] === 'queue_now';
        });
    }

    public function test_a_missing_key_a_bad_status_and_an_unreadable_body_fail_without_leaking_anything(): void
    {
        config(['ai_chat.api_key' => null]);
        $this->expectFailure(fn () => (new HttpChatModel)->complete('s', [], []), 'not configured');

        config(['ai_chat.api_key' => 'test-key-123', 'ai_chat.endpoint' => 'https://provider.test/v1/messages']);
        Http::fake(['provider.test/*' => Http::sequence()->push(['error' => ['message' => 'secret prompt echo test-key-123']], 500)->push(['nothing' => true])]);
        $message = $this->expectFailure(fn () => (new HttpChatModel)->complete('s', [], []), 'status 500');
        $this->assertStringNotContainsString('test-key-123', $message);
        $this->assertStringNotContainsString('secret prompt', $message);

        $this->expectFailure(fn () => (new HttpChatModel)->complete('s', [], []), 'could not be read');
    }

    private function expectFailure(callable $call, string $contains): string
    {
        try {
            $call();
        } catch (ChatProviderFailed $failed) {
            $this->assertStringContainsString($contains, $failed->getMessage());

            return $failed->getMessage();
        }
        $this->fail('The adapter did not fail.');
    }
}
