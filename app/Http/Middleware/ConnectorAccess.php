<?php

namespace App\Http\Middleware;

use App\Domain\Agents\AgentPermissions;
use App\Domain\Audit\AuditRecorder;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every MCP request, after the bearer token: the person is still an active user who still holds the connector
 * permission, so a deactivated Director or a removed role stops working at the next call even with a valid token
 * (design note section 5). A refusal is audited without any token or request body.
 */
final class ConnectorAccess
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');
        if (! $user instanceof User || ! $user->is_active || ! $user->can(AgentPermissions::CONNECTOR_USE)) {
            if ($user instanceof User) {
                $this->audit->record('ai_connector.access_refused', null, ['reason' => $user->is_active ? 'permission_missing' : 'user_inactive'], $user, null, $user->organisation_id);
            }

            return response()->json(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'You may not use the KPOne connector.']], 403)
                ->header('Cache-Control', 'no-store');
        }
        // The MCP package reads the default guard's user; make the token's user the one it sees.
        auth()->shouldUse('api');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
