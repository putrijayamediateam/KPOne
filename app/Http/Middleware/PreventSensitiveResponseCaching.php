<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventSensitiveResponseCaching
{
    /**
     * REF-01: staff pages send Referrer-Policy: same-origin, so the browser still tells KPOne's own
     * server which page a request came from (Laravel's back() and failed-validation redirects read
     * the Referer header first) but never tells another site. The public QR check-in routes keep
     * no-referrer by passing it as the middleware parameter: sensitive.no-store:no-referrer.
     */
    public function handle(Request $request, Closure $next, string $referrerPolicy = 'same-origin'): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', in_array($referrerPolicy, ['no-referrer', 'same-origin'], true) ? $referrerPolicy : 'no-referrer');

        return $response;
    }
}
