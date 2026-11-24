<?php

namespace App\Http\Middleware;

use App\Domain\Agents\Chat\ChatSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** While the chatbox kill switch is off, every chatbox route answers 404 (docs/agents/AI-CHATBOX-DESIGN.md section 5). */
final class ChatEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(ChatSwitch::enabled(), 404);

        return $next($request);
    }
}
