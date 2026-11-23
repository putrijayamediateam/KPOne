<?php

use App\Domain\Agents\Connector\Mcp\ConnectorServer;
use App\Http\Middleware\ConnectorAccess;
use App\Http\Middleware\ConnectorEnabled;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
| The KPOne connector (docs/agents/MCP-CONNECTOR-DESIGN.md). Loaded by the MCP package's service provider. Every route
| is behind the kill switch (ConnectorEnabled): while AGENTS_CONNECTOR_ENABLED is off, all of it answers 404.
| Passport's own route registration is switched off in AppServiceProvider, so the OAuth routes below are the only ones,
| each with the permission check KPOne needs: only an active person holding ai_connector.use.organisation can authorise.
*/
Route::middleware([ConnectorEnabled::class])->group(function (): void {
    // OAuth discovery and client registration (the connector app registers itself; redirect addresses are an allowlist
    // in config/mcp.php). Throttled, no cookies.
    Route::middleware(['throttle:30,1', 'sensitive.no-store'])->group(function (): void {
        Mcp::oauthRoutes();
        Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])->name('passport.token');
    });

    // The consent screen: an ordinary KPOne page behind the normal sign-in, for the permission holder only.
    Route::middleware(['web', 'auth', 'permission:ai_connector.use.organisation', 'sensitive.no-store'])->group(function (): void {
        Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');
        Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->name('passport.authorizations.approve');
        Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
    });

    // The MCP endpoint itself: bearer token only (no cookies, no CSRF), the token's scope, the person's permission on every
    // call, then 30 calls a minute per person.
    Mcp::web('/mcp', ConnectorServer::class)->middleware(['sensitive.no-store', 'auth:api', CheckToken::using('mcp:use'), ConnectorAccess::class, 'throttle:30,1']);
});
