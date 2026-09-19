<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class ValidatePublicIntakeProxy
{
    public function handle(Request $request, Closure $next): Response
    {
        $proxies = config('public-intake.trusted_proxies', []);
        if (! is_array($proxies)) {
            throw new LogicException('Public intake trusted proxies must be an explicit list.');
        }
        if (! app()->environment(['local', 'testing']) && in_array('*', $proxies, true)) {
            throw new LogicException('Wildcard trusted proxies are forbidden for public patient intake.');
        }

        $previousProxies = Request::getTrustedProxies();
        $previousHeaderSet = Request::getTrustedHeaderSet();
        if ($previousHeaderSet < 0 || $previousHeaderSet > Request::HEADER_X_FORWARDED_TRAEFIK) {
            throw new LogicException('The existing trusted proxy header set is outside the supported range.');
        }

        try {
            Request::setTrustedProxies(
                $proxies,
                Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO,
            );

            return $next($request);
        } finally {
            Request::setTrustedProxies($previousProxies, $previousHeaderSet);
        }
    }
}
