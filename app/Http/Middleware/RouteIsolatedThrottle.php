<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use RuntimeException;

class RouteIsolatedThrottle extends ThrottleRequests
{
    /**
     * Named limiters never call this method; Laravel retains their keys unchanged.
     */
    protected function resolveRequestSignature($request): string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            throw new RuntimeException('A matched route is required for numeric throttling.');
        }

        $scope = $route->getName() !== null
            ? 'name:'.$route->getName()
            : 'uri:'.$request->method().' '.$route->uri();

        // Use the URI pattern, never patient keys, query values or other input.
        return hash('sha256', 'numeric-route:'.$scope.'|'.parent::resolveRequestSignature($request));
    }
}
