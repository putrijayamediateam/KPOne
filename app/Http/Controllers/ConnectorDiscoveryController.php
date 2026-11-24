<?php

namespace App\Http\Controllers;

use App\Domain\Agents\Ask\AskSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Mcp\Server\Registrar;

/**
 * The connector's OAuth discovery documents. They say the same as the MCP package's own, except that "Ask through Claude"
 * also advertises the `mcp:answer` scope while its switch is on, so the connector app can ask for it. With the switch off the
 * documents are identical to the package's (one scope, `mcp:use`).
 */
final class ConnectorDiscoveryController extends Controller
{
    public function authorizationServer(): JsonResponse
    {
        return response()->json(array_filter([
            'issuer' => config('mcp.authorization_server') ?? url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => Router::has('mcp.oauth.register') ? route('mcp.oauth.register') : null,
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => $this->scopes(),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
        ], fn (mixed $value): bool => $value !== null));
    }

    public function protectedResource(?string $path = ''): JsonResponse
    {
        return response()->json([
            'resource' => url('/'.ltrim((string) $path, '/')),
            'authorization_servers' => [config('mcp.authorization_server') ?? url('/')],
            'scopes_supported' => $this->scopes(),
        ]);
    }

    /** @return list<string> */
    private function scopes(): array
    {
        return AskSwitch::enabled() ? [Registrar::OAUTH_SCOPE, AskSwitch::SCOPE] : [Registrar::OAUTH_SCOPE];
    }
}
