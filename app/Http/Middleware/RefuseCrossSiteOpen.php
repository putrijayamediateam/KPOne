<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the HR routes where opening a file, letter, payslip or case is a GET that records an "opened" row.
 *
 * The browser's Sec-Fetch-Site header says where a request came from. A link, image or form on another site sends
 * "cross-site" (or "same-site" from a sibling host), and the request is refused before anything is read or recorded,
 * so another site cannot add "opened" rows for a signed-in person. An open from inside KPOne ("same-origin"), a typed
 * or bookmarked address ("none") and a client that sends no header at all go through unchanged. Nothing is written
 * for a refusal: an audit row would be the very row a cross-site link should not be able to add.
 */
class RefuseCrossSiteOpen
{
    private const REFUSED = ['cross-site', 'same-site'];

    public function handle(Request $request, Closure $next): Response
    {
        $site = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));

        if (in_array($site, self::REFUSED, true)) {
            abort(403, 'Open this from inside KPOne.');
        }

        return $next($request);
    }
}
