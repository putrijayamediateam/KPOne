<?php

namespace App\Http\Middleware;

use App\Domain\Agents\Connector\ConnectorSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** While the connector kill switch is off, every connector and OAuth route answers 404 (design note section 3). */
final class ConnectorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(ConnectorSwitch::enabled(), 404);

        return $next($request);
    }
}
