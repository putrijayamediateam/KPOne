<?php

namespace Tests\Support;

use App\Models\User;
use Laravel\Passport\Passport;

/** Test helper: sign a person in with a connector token and ask one connector question over MCP. */
trait AsksTheConnector
{
    protected function connectorSetUp(): void
    {
        config(['agents.connector_enabled' => true]);
        $keys = sys_get_temp_dir().'/kpone-connector-keys-'.getmypid();
        if (! is_dir($keys)) {
            mkdir($keys, 0700, true);
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            file_put_contents($keys.'/oauth-private.key', $private);
            file_put_contents($keys.'/oauth-public.key', openssl_pkey_get_details($key)['key']);
            chmod($keys.'/oauth-private.key', 0600);
            chmod($keys.'/oauth-public.key', 0600);
        }
        Passport::loadKeysFrom($keys);
    }

    /** @param array<string, mixed> $arguments */
    protected function connectorAnswer(User $user, string $tool, array $arguments = []): array
    {
        Passport::actingAs($user, ['mcp:use'], 'api');
        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], [
            'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => $tool,
        ])->assertOk();
        $this->assertFalse((bool) $response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode((string) $response->json('result.content.0.text'), true);
    }
}
