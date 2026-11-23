<?php

namespace App\Http\Middleware;

use App\Domain\Approvals\ApprovalInbox;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** APPR-1: after a person decides something, their cached inbox counts are cleared so the number is right on the next page. */
final class ForgetApprovalCounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->user() !== null) {
            ApprovalInbox::forget($request->user());
        }

        return $response;
    }
}
