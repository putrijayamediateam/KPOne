<?php

namespace App\Http\Middleware;

use App\Domain\Agents\Ask\AskSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** While "Ask through Claude" is off (its own switch or the connector's), every one of its routes answers 404. */
final class AskClaudeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AskSwitch::enabled(), 404);

        return $next($request);
    }
}
