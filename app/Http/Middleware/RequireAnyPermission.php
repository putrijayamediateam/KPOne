<?php

namespace App\Http\Middleware;

use App\Domain\Audit\AuditRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAnyPermission
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyPermission($permissions)) {
            $this->audit->record('authorization.denied', $user, [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
                'required_permissions' => $permissions,
            ], $user);

            abort(403);
        }

        return $next($request);
    }
}
